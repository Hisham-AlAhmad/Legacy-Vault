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
| id | BIGINT UNSIGNED PK | Internal only — never exposed by the API; FK target for `attachments` |
| uuid | CHAR(36) | Client-generated UUIDv4, the item's public identifier and the item ID bound into AAD (`06_Cryptography.md` §9, `19_Decision_Log.md` Decision #15). Stays reserved after a soft delete |
| user_id | BIGINT UNSIGNED FK→users | |
| recipient_id | BIGINT UNSIGNED FK→recipients | One item = one recipient (VAULT-5); a "send to multiple" UI action creates multiple rows, not a join table, to keep per-recipient encryption strictly independent. Also bound into AAD, so changing this column makes the item undecryptable rather than misdelivered |
| item_type | VARCHAR(20) | `message` \| `file_bundle` (validated on write; a string rather than a MySQL `ENUM` so one migration serves SQLite too). Fixed at creation. Either type can carry attachments (`19_Decision_Log.md` Decision #27) |
| title_enc | VARBINARY(1024) **(enc)** | Envelope (`06_Cryptography.md` §13), plaintext ≤ 200 chars, encrypted with the item's content key |
| content_enc | LONGBLOB NULL **(enc)** | Envelope of the sanitized-HTML body, ≤ 262,144 bytes. `NULL` for `file_bundle` items, and only for them |
| content_key_wrapped | VARBINARY(61) **(enc)** | Envelope of the random per-version content key, wrapped by the HKDF-derived Content-Wrap Key (`06_Cryptography.md` §4) — exactly 61 bytes |
| aad_version | TINYINT UNSIGNED | Envelope/AAD format version (currently 1). Equals the version byte inside each envelope; kept as a column so a future format migration can find old rows without parsing blobs |
| position | SMALLINT UNSIGNED DEFAULT 0 | Ordering within a recipient's items. Assigned `max + 1` on create, rewritten by the reorder endpoint |
| created_at, updated_at, deleted_at | TIMESTAMP | |

There is deliberately **no `nonce` column**: three ciphertexts can't share one nonce, so
each envelope carries its own (`19_Decision_Log.md` Decision #14).

**Indexes:** UNIQUE(user_id, uuid) — per user, so the constraint can't be used to probe
other accounts' IDs; INDEX(recipient_id, position) — the list query; INDEX(deleted_at) —
the purge sweep (added in Phase 2c, on `recipients` too).

## attachments

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | Internal only — never exposed by the API |
| uuid | CHAR(36) | Client-generated UUIDv4: the attachment's public identifier, its upload ID, and the attachment ID bound into AAD (`19_Decision_Log.md` Decision #21). Reserved until the row is purged |
| user_id | BIGINT UNSIGNED FK→users, RESTRICT | Owner, stored directly so the quota is one indexed sum |
| vault_item_id | BIGINT UNSIGNED FK→vault_items, RESTRICT | The item (message or file bundle) the file belongs to |
| storage_path | VARCHAR(255) | `u{user_id}/{server-generated UUIDv4}.bin` on the `attachments` disk — never derived from anything the client sent (`05_Security_Architecture.md` §11). Never returned by the API |
| filename_enc | VARBINARY(1024) **(enc)** | Envelope of the original filename (≤ 255 chars and ≤ 995 UTF-8 bytes), under the attachment key |
| mime_type_enc | VARBINARY(157) **(enc)** | Envelope of the MIME type padded to 128 bytes — always exactly 157 bytes |
| attachment_key_wrapped | VARBINARY(61) **(enc)** | Envelope of the random per-attachment key, wrapped by the Content-Wrap Key (`06_Cryptography.md` §4) |
| size_bytes | BIGINT UNSIGNED | Total **ciphertext** bytes, declared before upload and exactly matched by the chunks. Plaintext — needed for quota accounting without decryption |
| chunk_count | SMALLINT UNSIGNED | Number of chunks; also authenticated by every chunk's AAD (`06_Cryptography.md` §12) |
| received_chunks | SMALLINT UNSIGNED DEFAULT 0 | Chunks stored so far, i.e. the next chunk index the server will accept |
| completed_at | TIMESTAMP NULL | Set when the last chunk arrives. `NULL` = upload in progress: not listed, not downloadable, never deliverable |
| aad_version | TINYINT UNSIGNED | Envelope/AAD format version (currently 1) |
| created_at, updated_at, deleted_at | TIMESTAMP | |

**Indexes:** UNIQUE(user_id, uuid); INDEX(vault_item_id); INDEX(deleted_at).

**Quota enforcement** (`19_Decision_Log.md` Decisions #19, #26): a user's usage is
`SUM(size_bytes)` over **all** of their attachment rows that still exist — completed,
in progress (the declared size is a reservation) and soft-deleted (until purged). It is
compared with `legacyvault.attachment_quota_bytes` when an upload is declared, inside a
transaction that locks the user's row, before any content byte is accepted
(`05_Security_Architecture.md` §14, `NFR-3`).

**The file:** the chunk envelopes concatenated in order (`06_Cryptography.md` §12), at one
fixed `storage_path` from the first chunk on — `completed_at` is what marks it finished.

**Foreign keys:** `user_id` and `vault_item_id` are `ON DELETE RESTRICT`, the one
exception to this document's cascade convention. A cascade would delete attachment rows
and leave their files on disk, uncounted; with `RESTRICT` the only way to remove a parent
is the purge code, which deletes files and attachment rows first
(`19_Decision_Log.md` Decision #23). Any future account-deletion path must do the same.

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

- `recipients`, `vault_items`, `attachments`: soft delete while the user is alive/active,
  recoverable for an undo window of **30 days** (`legacyvault.trash.undo_window_days`),
  then hard-deleted by a scheduled job (`19_Decision_Log.md` Decisions #18, #28, #29).
  - **Cascade down, same timestamp.** Deleting a recipient soft-deletes its live items, and
    deleting an item (on its own or with its recipient) soft-deletes its completed
    attachments — all in one transaction, all with the identical `deleted_at`. Unfinished
    uploads under a deleted item are discarded immediately.
  - **Restore by matching timestamp.** Restoring a recipient restores the items whose
    `deleted_at` equals the recipient's, and their attachments whose `deleted_at` equals
    the item's. Something deleted on its own earlier keeps its own timestamp and stays
    deleted. A child can only be restored into a live parent.
  - Soft-deleted rows are invisible to every API response except `GET /trash`, and must
    stay invisible to the delivery trigger. Their ciphertext stays in the database and on
    disk, and deleted files still count against the quota, until purge.
  - **Purge.** `php artisan vault:purge-deleted` runs hourly: it removes unfinished
    uploads older than 24 hours and everything whose `deleted_at` is 30 days old or more —
    attachment files first, then rows. The user can also erase a deleted entry at once
    from the recently-deleted list (same code path).
  - **What "permanently erased" covers:** the live database and the attachment disk.
    Off-host backups (`05_Security_Architecture.md` §21) keep the *ciphertext* until they
    age out of their own retention window.
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
