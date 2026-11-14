# 07 — Database Design (MySQL)

Conventions: every table has `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`,
`created_at`/`updated_at` timestamps (Laravel default), and `deleted_at` (soft delete) where
noted. Foreign keys use `ON DELETE CASCADE` unless stated otherwise — cascading is a deliberate
choice per table, not a default assumed blindly. Columns marked **(enc)** store ciphertext
(AES-256-GCM output, typically `VARBINARY`/`BLOB` plus a small JSON envelope for nonce/tag/AAD
version) — see `06_Cryptography.md` for what's encrypted and why.

## users

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| email | VARCHAR(255) UNIQUE | Plaintext — needed for login/lookup |
| password_hash | VARCHAR(255) | Argon2id PHC string |
| status | ENUM('pending','active','suspended','deceased_processing','purged') | `pending` = registered but Recovery Key not yet acknowledged (Flow 1 edge case); `deceased_processing` set at delivery trigger; `purged` after retention window |
| two_factor_enabled | BOOLEAN DEFAULT FALSE | |
| two_factor_secret_enc | VARBINARY(255) NULL **(enc)** | TOTP secret, encrypted with SWK-adjacent server key (not the vault Master Key — 2FA must work even if vault is locked) |
| two_factor_pending_secret_enc | VARBINARY(255) NULL **(enc)** | TOTP secret during enrollment, before the user confirms with a valid code (promoted to `two_factor_secret_enc` on confirm) |
| two_factor_backup_codes_hash | JSON NULL | Array of hashed backup codes |
| locale | ENUM('en','ar') DEFAULT 'en' | |
| failed_login_count | SMALLINT UNSIGNED DEFAULT 0 | Reset on success |
| locked_until | TIMESTAMP NULL | Backoff enforcement |
| recovery_key_acknowledged_at | TIMESTAMP NULL | Blocks `pending`→`active` until set |
| created_at, updated_at, deleted_at | TIMESTAMP | |

**Indexes:** UNIQUE(email). INDEX(status) — used heavily by the check-in sweep.
**Why `deceased_processing` as a status, not just a boolean:** several other tables' queries
(recipients, dashboard) need to branch on this, and an enum on `users` is the single cheapest
place to check it without joining `checkins` every time.

## encrypted_keys

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| user_id | BIGINT UNSIGNED FK→users | |
| key_type | ENUM('password_wrapped_master','recovery_wrapped_master','server_wrapped_master') | See `06_Cryptography.md` §4 |
| wrapped_key | VARBINARY(512) **(enc)** | The wrapped Master Key bytes |
| wrap_nonce | VARBINARY(16) | |
| kdf_salt | VARBINARY(16) NULL | Argon2id salt for the PDK/RDK wrap (`06_Cryptography.md` §7 — salts are stored alongside the wrapped-key record; the client needs it to re-derive the wrapping key at login/recovery). NULL for `server_wrapped_master`, which uses the SWK directly |
| wrap_version | TINYINT UNSIGNED | Crypto scheme version, for future migration |
| created_at, updated_at | TIMESTAMP | |

**Indexes:** UNIQUE(user_id, key_type). **Note:** `server_wrapped_master` row is the one
`DeliveryTriggerService` is permitted to read (`06_Cryptography.md` §5) — enforce this at the
application layer (a dedicated repository method), not just convention.

## sessions / devices

| Column (devices) | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| user_id | BIGINT UNSIGNED FK→users | |
| fingerprint_hash | VARCHAR(64) | Hash of UA + coarse network info, not invasive fingerprinting |
| label | VARCHAR(120) NULL | User-editable ("My Laptop") |
| trusted | BOOLEAN DEFAULT FALSE | |
| last_seen_at | TIMESTAMP | |
| created_at | TIMESTAMP | |

Sanctum's own `personal_access_tokens`/session store handles the actual session token;
`devices` is an app-level table joined to it for the "trusted devices" UI (`02_User_Flows.md`
Flow 10). Implementation note (Phase 1): the database session driver doesn't write custom
columns, so the session↔device link is carried as a `device_id` entry inside the session
payload rather than a dedicated FK column; the sessions list decodes it when rendering the
trusted-devices UI.

## recipients

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| user_id | BIGINT UNSIGNED FK→users | |
| name_enc | VARBINARY(512) **(enc)** | Sealed field (`06_Cryptography.md` §15), plaintext ≤ 120 chars. Encrypted — a DB leak shouldn't reveal who someone's beneficiaries are |
| relationship_label_enc | VARBINARY(512) **(enc)** | Sealed field, plaintext ≤ 60 chars |
| email | VARCHAR(255) NULL | Plaintext — operationally required for delivery routing (see `06_Cryptography.md` §15) |
| phone | VARCHAR(32) NULL | Plaintext, same reason. E.164 (`+` and 7–15 digits) |
| preferred_methods | JSON | Non-empty subset of `email`/`sms`/`link`. JSON rather than MySQL `SET` so one migration serves the SQLite dev database too |
| is_emergency_contact | BOOLEAN DEFAULT FALSE | A recipient MAY also be the emergency contact; see `emergency_contacts` for the distinct role table. At most one recipient per user carries the flag — set/cleared by `POST`/`DELETE /emergency-contact` |
| created_at, updated_at, deleted_at | TIMESTAMP | |

**Reachability invariant** (enforced in `RecipientReachability`, not the schema): `email` in
`preferred_methods` requires `email`; `sms` requires `phone`; and at least one of
`email`/`phone` must be present, so a `link`-only recipient is still reachable.

**Indexes:** INDEX(user_id). **Why email/phone are plaintext despite "encrypt everything":**
the server must be able to actually dispatch email/SMS without decrypting the whole vault to
do so — see `06_Cryptography.md` §15 for the explicit list of fields exempted from encryption
and why each is exempted.

## emergency_contacts

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| user_id | BIGINT UNSIGNED FK→users, UNIQUE | One active emergency contact per user in v1 |
| name_enc | VARBINARY(512) **(enc)** | Sealed field (`06_Cryptography.md` §15). When the contact is also a recipient, the client copies that recipient's details in; the record stays independent afterwards |
| email | VARCHAR(255) | Plaintext — needed for the confirmation link |
| phone | VARCHAR(32) NULL | |
| created_at, updated_at | TIMESTAMP | |

**Why a separate table from `recipients`:** distinct role, distinct authorization model
(single-use confirmation link, no vault content access at all — `05_Security_Architecture.md`
§2), and a user may want an emergency contact who receives nothing from the vault (e.g. a
neighbor who can just confirm "yes they're alive" without being a beneficiary).

## vault_items

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| user_id | BIGINT UNSIGNED FK→users | |
| recipient_id | BIGINT UNSIGNED FK→recipients | One item = one recipient (VAULT-5); a "send to multiple" UI action creates multiple rows, not a join table, to keep per-recipient encryption strictly independent |
| item_type | ENUM('message','file_bundle') | |
| title_enc | VARBINARY(255) **(enc)** | |
| content_enc | LONGBLOB NULL **(enc)** | Rich-text body, null for pure file-bundle items |
| content_key_wrapped | VARBINARY(255) **(enc)** | Per-item content key, wrapped by Master Key |
| nonce | VARBINARY(16) | |
| aad_version | TINYINT UNSIGNED | |
| position | SMALLINT UNSIGNED DEFAULT 0 | Ordering within a recipient's items |
| created_at, updated_at, deleted_at | TIMESTAMP | |

**Indexes:** INDEX(user_id), INDEX(recipient_id).

## attachments

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| vault_item_id | BIGINT UNSIGNED FK→vault_items | |
| storage_path | VARCHAR(255) | Server-generated UUID-based path — never derived from user filename (`05_Security_Architecture.md` §11) |
| filename_enc | VARBINARY(255) **(enc)** | Original filename |
| mime_type_enc | VARBINARY(120) **(enc)** | |
| size_bytes | BIGINT UNSIGNED | Plaintext — needed for quota accounting without decryption |
| attachment_key_wrapped | VARBINARY(255) **(enc)** | Per-attachment key, wrapped by Master Key |
| chunk_count | SMALLINT UNSIGNED | For streaming encryption/decryption (`06_Cryptography.md` §12) |
| created_at, deleted_at | TIMESTAMP | |

**Indexes:** INDEX(vault_item_id). **Quota enforcement** sums `size_bytes` per `user_id` via a
join through `vault_items`, checked before accepting new bytes (`05_Security_Architecture.md`
§14, `NFR-3`).

## checkins

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| user_id | BIGINT UNSIGNED FK→users | |
| interval_type | ENUM('week','month','6months','year','custom') | |
| interval_days | SMALLINT UNSIGNED | Resolved value, including custom |
| state | ENUM('active','grace_period','emergency_verification','delivery_triggered','paused') | See `11_CheckIn_Engine.md` §State Machine |
| last_checkin_at | TIMESTAMP | |
| next_due_at | TIMESTAMP | |
| grace_started_at | TIMESTAMP NULL | |
| emergency_started_at | TIMESTAMP NULL | |
| triggered_at | TIMESTAMP NULL | |
| created_at, updated_at | TIMESTAMP | |

**Indexes:** UNIQUE(user_id) — one active check-in configuration per user in v1. INDEX(state,
next_due_at) — this is the index the cron sweep query depends on; get this wrong and the sweep
becomes a full table scan as the user base grows.

## delivery_methods

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| recipient_id | BIGINT UNSIGNED FK→recipients | |
| method | ENUM('email','sms','link') | |
| status | ENUM('pending','sent','delivered','failed') | Per-attempt delivery tracking |
| sent_at, delivered_at | TIMESTAMP NULL | |
| failure_reason | VARCHAR(255) NULL | |

**Indexes:** INDEX(recipient_id).

## notifications

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| user_id | BIGINT UNSIGNED FK→users NULL | Null for recipient/emergency-contact-directed notifications |
| notifiable_type, notifiable_id | polymorphic | Covers user, recipient, emergency_contact targets |
| channel | ENUM('push','email','sms') | |
| type | VARCHAR(60) | e.g. `checkin_reminder`, `grace_period_escalation`, `emergency_verification_request`, `delivery_notice` |
| payload | JSON | Non-sensitive template data only — never vault content |
| status | ENUM('queued','sent','failed') | |
| created_at, sent_at | TIMESTAMP | |

**Indexes:** INDEX(notifiable_type, notifiable_id), INDEX(status).

## audit_logs

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| user_id | BIGINT UNSIGNED NULL | Nullable + retained even if user is later purged (`05_Security_Architecture.md` §21) |
| actor_type | ENUM('user','system','emergency_contact','admin') | |
| event_type | VARCHAR(80) | |
| metadata | JSON | No secrets, ever — enforced by a serializer allow-list |
| ip_address | VARBINARY(16) | Stored as binary (`INET6_ATON`) |
| created_at | TIMESTAMP | |

**Indexes:** INDEX(user_id, created_at), INDEX(event_type). Append-only — no `updated_at`, no
`deleted_at`, no application code path performs `UPDATE`/`DELETE` on this table (enforced by
DB user grants where the hosting plan allows it, otherwise by code convention +
`AI_RULES.md` #14).

## Relationships summary

```
users 1─┬─* recipients
        ├─1 emergency_contacts
        ├─1 checkins
        ├─* encrypted_keys
        ├─* devices
        └─* audit_logs

recipients 1─* vault_items
recipients 1─* delivery_methods

vault_items 1─* attachments
```

## Deletion strategy

- `recipients`, `vault_items`: soft delete while user is alive/active (recoverable within a
  short undo window in the UI), hard-deleted on a scheduled cleanup job after that window.
- `users`: soft delete on user-initiated account deletion; hard purge (ciphertext, keys,
  attachments) after the retention window in `05_Security_Architecture.md` §21, except
  `audit_logs`, which persist in anonymized form indefinitely.
- Post-delivery: `vault_items`/`attachments` for a completed delivery are retained for the
  recipient re-access window (§21) then purged; `encrypted_keys` are purged immediately after
  successful delivery completion (there is no reason to keep any wrapped Master Key copy after
  the vault has served its purpose).

## Scalability notes

- All `user_id`-scoped tables are natural candidates for future horizontal partitioning if
  needed, but v1 targets a single MySQL instance comfortably (shared-hosting scale) — no
  sharding planned.
- `audit_logs` and `notifications` are the fastest-growing tables; both have narrow, indexed
  access patterns and are candidates for periodic archival to keep the primary tables lean,
  without violating the retention requirements above.
