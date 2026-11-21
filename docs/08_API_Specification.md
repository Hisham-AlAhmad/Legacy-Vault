# 08 — API Specification

## 1. Conventions (apply to every endpoint below unless overridden)

- Base path: `/api/v1`.
- Auth: Sanctum cookie session unless marked **Public** or **Signed-link**.
- All state-changing requests require the CSRF header (`05_Security_Architecture.md` §5).
- All request/response bodies are JSON. All timestamps are ISO 8601 UTC.
- All endpoints return DTOs (`09_Backend_Architecture.md`), never raw Eloquent models.
- Standard error envelope:
  ```json
  { "error": { "code": "STRING_CODE", "message": "Human-readable", "fields": { "field": ["msg"] } } }
  ```
- Standard rate-limit headers on every response: `X-RateLimit-Limit`, `X-RateLimit-Remaining`.
- Every endpoint that mutates user data writes an `audit_logs` row per
  `05_Security_Architecture.md` §17, noted per-endpoint below as **Audit:**.

## 2. Full endpoint index

| Method & Path | Purpose |
|---|---|
| POST `/auth/register` | Create account |
| POST `/auth/login` | Log in (includes `totp_code` when 2FA is enabled — no separate verify endpoint; see note below) |
| POST `/auth/logout` | Log out current session |
| GET `/auth/me` | Current-user summary (SPA session bootstrap) |
| POST `/auth/2fa/enable` | Begin TOTP enrollment |
| POST `/auth/2fa/confirm` | Confirm TOTP enrollment (returns backup codes, once) |
| POST `/auth/2fa/disable` | Disable 2FA |
| POST `/auth/password/forgot` | Request password reset |
| POST `/auth/password/reset` | Perform password reset |
| POST `/auth/password/change` | Change password (authenticated) |
| GET `/auth/sessions` | List trusted devices/sessions (session IDs exposed only as SHA-256 hashes) |
| DELETE `/auth/sessions/{id}` | Revoke a session (`id` = the hash from the list; password required when revoking the current session) |
| GET `/auth/login-history` | Login history (AUTH-7), served from the audit log |
| POST `/vault/recovery-key/acknowledge` | Confirm the Recovery Key was saved; finalizes registration (`pending`→`active`, Flow 1 step 4) |
| POST `/vault/recovery-key/verify` | Begin recovery: returns the recovery-wrapped Master Key for client-side unwrap (the server can't verify the key itself — it never has it; a wrong key fails the client-side GCM tag check) |
| POST `/vault/recovery-key/rotate` | Issue new Recovery Key (replaces recovery-wrapped copy; optionally the password-wrapped copy during Flow 7) |

*Phase 1 note:* TOTP verification happens inside `POST /auth/login` via the `totp_code`
field (the login spec below) rather than a separate `/auth/2fa/verify` round trip — one
fewer partially-authenticated intermediate state to secure.
| POST `/vault/unlock` | Re-unlock in an existing session: verifies the password, returns the password-wrapped Master Key (Phase 2a — `19_Decision_Log.md` Decision #12) |
| GET `/recipients` | List recipients |
| POST `/recipients` | Create recipient |
| PATCH `/recipients/{id}` | Update recipient |
| DELETE `/recipients/{id}` | Delete recipient (step-up: `password` in body) |
| GET `/recipients/{id}/items` | List vault items for a recipient (titles only, no bodies) |
| POST `/recipients/{id}/items` | Create vault item |
| POST `/recipients/{id}/items/reorder` | Set the order of a recipient's items |
| GET `/vault-items/{uuid}` | One vault item, including its body ciphertext |
| PATCH `/vault-items/{uuid}` | Replace a vault item's key, title and body (every edit rotates the key) |
| DELETE `/vault-items/{uuid}` | Delete vault item (soft delete) |
| GET `/vault/storage` | Attachment quota: limit and current usage |
| GET `/vault-items/{uuid}/attachments` | List an item's completed attachments |
| POST `/vault-items/{uuid}/attachments` | Declare an attachment upload (reserves quota) |
| GET `/attachments/{uuid}` | One attachment, including upload progress (resume) |
| POST `/attachments/{uuid}/chunks` | Upload the next encrypted chunk |
| GET `/attachments/{uuid}/content` | Download the ciphertext file |
| DELETE `/attachments/{uuid}` | Delete an attachment (soft delete), or abort an unfinished upload |
| GET `/trash` | Recently deleted recipients, items and attachments that can still be restored |
| POST `/trash/recipients/{id}/restore` · `/trash/items/{uuid}/restore` · `/trash/attachments/{uuid}/restore` | Undo a deletion |
| DELETE `/trash/recipients/{id}` · `/trash/items/{uuid}` · `/trash/attachments/{uuid}` | Erase a deleted entry now (irreversible; step-up: `password` in body) |
| POST `/vault/integrity-events` | Report client-side decryption/integrity failures to the audit log (IDs and codes only; Phase 2d) |
| GET `/dashboard` | Aggregated dashboard data |
| GET `/checkin` | Current check-in state |
| POST `/checkin` | Perform a check-in |
| POST `/checkin/settings` | Update interval |
| GET `/checkin/confirm/{token}` **(Signed-link)** | One-click check-in from notification |
| GET `/emergency-contact` | Current emergency contact, or `null` (Phase 2a) |
| POST `/emergency-contact` | Set/replace emergency contact |
| DELETE `/emergency-contact` | Remove emergency contact (step-up: `password` in body; Phase 2a) |
| GET `/emergency-contact/confirm/{token}` **(Signed-link, Public)** | Emergency contact confirms user is alive |
| GET `/delivery/access/{token}` **(Signed-link, Public)** | Recipient views delivered content |
| GET `/backup/export` | Export encrypted backup |
| POST `/backup/import` | Import encrypted backup |
| GET `/admin/users` **(Admin)** | Support/abuse tooling |
| POST `/admin/users/{id}/suspend` **(Admin)** | Suspend an account |

## 3. Fully specified endpoints (representative set)

### POST `/auth/register`

- **Purpose:** create a new account and establish the initial key hierarchy.
- **Auth:** Public.
- **Headers:** `Content-Type: application/json`.
- **Request body:**
  ```json
  {
    "email": "string, required, valid email",
    "password": "string, required, min 12 chars, zxcvbn>=3",
    "locale": "en|ar",
    "password_wrapped_master_key": { "ciphertext": "base64 (48 bytes: key+GCM tag)", "nonce": "base64 (12 bytes)", "kdf_salt": "base64 (16 bytes)" },
    "recovery_wrapped_master_key": { "ciphertext": "base64", "nonce": "base64", "kdf_salt": "base64" },
    "server_wrap_master_key_plaintext": "base64, required — see 06_Cryptography.md §5, TLS only, discarded after wrapping"
  }
  ```
  (Each wrapped copy carries its own GCM nonce and Argon2id salt — `07_Database_Design.md`
  §encrypted_keys, `06_Cryptography.md` §7.)
- **Validation:** email uniqueness, password strength server-side re-check (never trust
  client-side-only), exact byte-length checks on all key material fields.
- **Possible errors:** `422 VALIDATION_FAILED`, `409 EMAIL_IN_USE` *(resolved for Phase 1
  sign-off: the collision is reported directly, with enumeration blunted by the 3/hour/IP
  registration rate limit — see `19_Decision_Log.md` Decision #10; a stale `pending`
  registration for the same email is replaced instead of erroring, since its shown-once
  Recovery Key is unrecoverable)*.
- **Response:** `201`, user summary DTO (status `pending` until
  `/vault/recovery-key/acknowledge`), session cookie set.
- **Rate limits:** 3/hour/IP.
- **Permissions:** none required (public).
- **Audit:** `user.registered`.
- **Notes:** the Recovery Key itself is never part of this request — it's generated and shown
  client-side (Flow 1); only the wrapped Master Key copies are transmitted.

### POST `/auth/login`

- **Purpose:** authenticate and establish a session.
- **Auth:** Public.
- **Request body:** `{ "email": string, "password": string, "totp_code": "string, required if 2FA enabled" }`.
- **Validation:** rate-limited before validation runs (cheap check first) to reduce load from
  scripted attempts.
- **Possible errors:** `401 INVALID_CREDENTIALS` (identical message whether email doesn't
  exist or password is wrong), `423 ACCOUNT_LOCKED`, `401 TOTP_REQUIRED`, `401 TOTP_INVALID`.
- **Response:** `200`, user summary DTO + `password_wrapped_master_key`
  (`{ ciphertext, nonce, kdf_salt, wrap_version }`, so the client can re-derive the PDK and
  unwrap the Master Key locally), session cookie set. After a password reset the stored wrap
  is stale by design (AUTH-10): the client detects the failed unwrap and routes to recovery.
- **Rate limits:** 5/min/IP, 5/15min/account (`05_Security_Architecture.md` §7).
- **Audit:** `auth.login_success` / `auth.login_failed`.

### POST `/recipients`

- **Purpose:** create a recipient under the authenticated user.
- **Auth:** Required.
- **Request body:** `{ "name_enc": base64, "relationship_enc": base64, "email": string|null, "phone": string|null, "preferred_methods": ["email"|"sms"|"link"] }`.
  `name_enc`/`relationship_enc` are sealed fields (`06_Cryptography.md` §15 — envelope,
  AAD and Metadata Key derivation).
- **Validation:** sealed fields must be well-formed envelopes of ≤ 512 decoded bytes
  (structure only — the server can't read them); `email` RFC-valid; `phone` E.164;
  `preferred_methods` a non-empty, distinct subset of `email|sms|link`; reachability: `email`
  requires an email, `sms` requires a phone, and at least one of email/phone is always
  required (a `link`-only recipient must still be reachable).
- **Possible errors:** `422 VALIDATION_FAILED`, `403 ACCOUNT_NOT_ACTIVE` (registration not
  finalized — applies to every vault endpoint), `403 QUOTA_EXCEEDED` (if a future recipient
  count cap is added — none in v1, noted for forward-compatibility).
- **Response:** `201`, `{ "recipient": RecipientDto }` where RecipientDto is
  `{ id, name_enc, relationship_enc, email, phone, preferred_methods, is_emergency_contact, created_at, updated_at }`.
  `GET /recipients` returns `{ "recipients": [RecipientDto] }`, oldest first.
- **Rate limits:** standard 120/min authenticated tier.
- **Permissions:** owner-only (policy-scoped to `user_id`).
- **Audit:** `recipient.created` (`recipient_id`, `preferred_methods` — never names or
  contact details).

### PATCH `/recipients/{id}` · DELETE `/recipients/{id}` (Phase 2a)

- **PATCH body:** any subset of the POST fields; omitted fields keep their stored value,
  `email`/`phone` may be set to `null`. Reachability is re-checked against the *merged* state.
  Response `200`, `{ "recipient": RecipientDto }`. **Audit:** `recipient.updated`
  (`recipient_id`, `changed_fields` — names of changed columns only).
- **DELETE body:** `{ "password": string }` — step-up re-auth
  (`05_Security_Architecture.md` §3). `401 INVALID_CREDENTIALS` on a wrong password. Soft
  delete; the recipient's vault items are soft-deleted with it in the same transaction
  (Phase 2b, `19_Decision_Log.md` Decision #18), and those items' attachments with them.
  Response `200 { "ok": true }`. **Audit:** `recipient.deleted` (`recipient_id`,
  `item_count`). Restorable for 30 days from `GET /trash` (Phase 2c).
- **Both:** `{id}` resolves only within the caller's own recipients — anyone else's ID (or a
  non-numeric one) is `404 NOT_FOUND`, never `403`. DELETE uses the `step-up` limiter
  (10/min/user).

### GET · POST · DELETE `/emergency-contact` (Phase 2a)

- **GET:** `{ "emergency_contact": EmergencyContactDto | null }`, where
  EmergencyContactDto is `{ name_enc, email, phone, linked_recipient_id, updated_at }`.
- **POST body:** `{ "name_enc": sealed, "email": string (required — the EC-3 link goes
  here), "phone": E.164|null, "recipient_id": int|null }`. Creates or replaces the single
  emergency contact (EC-4). `recipient_id` marks one of the caller's recipients as also
  being the emergency contact (EC-1); every other recipient's flag is cleared. Another
  user's recipient → `422` on `recipient_id`. Response `200`. **Audit:**
  `emergency_contact.set` (`reason`: `created`|`replaced`, `linked_recipient_id`).
- **DELETE body:** `{ "password": string }` — step-up, because with no emergency contact a
  missed grace period goes straight to delivery (`11_CheckIn_Engine.md` §6). `404
  EMERGENCY_CONTACT_NOT_FOUND` if none is set. **Audit:** `emergency_contact.removed`.

### POST `/vault/unlock` (Phase 2a)

- **Purpose:** the Master Key lives only in browser memory, so a page reload leaves a valid
  session with a locked vault. This re-verifies the password and returns the
  password-wrapped Master Key for local unwrap — see `19_Decision_Log.md` Decision #12.
- **Request body:** `{ "password": string }`.
- **Response:** `200`, `{ "password_wrapped_master_key": { key_type, ciphertext, nonce, kdf_salt, wrap_version } }`.
  A stale wrap (after a password reset) fails the client-side unwrap, which routes to
  recovery — exactly as after login.
- **Possible errors:** `401 INVALID_CREDENTIALS`, `429 RATE_LIMITED`.
- **Rate limits:** `step-up` limiter, 10/min/user.
- **Audit:** `vault.unlocked` / `vault.unlock_failed`.

### POST `/recipients/{id}/items`

- **Purpose:** create an encrypted vault item addressed to one recipient.
- **Auth:** Required, owner-only, active account.
- **Request body:**
  ```json
  {
    "id": "client-generated UUIDv4, lowercase canonical form",
    "item_type": "message",
    "title_enc": "base64 envelope, ≤ 1024 decoded bytes",
    "content_enc": "base64 envelope, ≤ 262144 decoded bytes",
    "content_key_wrapped": "base64 envelope, exactly 61 decoded bytes",
    "aad_version": 1
  }
  ```
  The three ciphertexts are envelopes `0x01 ‖ nonce(12) ‖ ciphertext ‖ tag(16)`, each with
  its own nonce — there is no separate `nonce` field. Keys, AAD and the reason the client
  picks the ID: `06_Cryptography.md` §9, §13; `19_Decision_Log.md` Decisions #13–#15.
- **File bundles (Phase 2c):** `"item_type": "file_bundle"` creates a titled container for
  files. It has no body: `content_enc` must be absent or `null` (sending one is `422`), and
  the response's `content_enc` is `null`. Files are then added with the attachment
  endpoints below (`19_Decision_Log.md` Decision #27). Messages may carry attachments too.
- **Validation:** structure only — the server can't read any of it. `id` must be a
  canonical UUIDv4; `item_type` must be `message` or `file_bundle`;
  each envelope must be valid base64 with version byte `0x01`, within its size cap, and
  `content_key_wrapped` exactly 61 bytes; `aad_version` must be `1`.
- **Possible errors:** `422 VALIDATION_FAILED`; `404 NOT_FOUND` (recipient isn't the
  caller's, is deleted, or doesn't exist — never `403`, which would confirm existence);
  `409 ITEM_ID_CONFLICT` (the caller already has an item with this `id`, deleted ones
  included); `413 PAYLOAD_TOO_LARGE` (`content_enc` over its cap); `403 ITEM_LIMIT_REACHED`
  (100 live items per recipient); `403 ACCOUNT_NOT_ACTIVE`.
- **Response:** `201`, `{ "item": VaultItemDto }`, where VaultItemDto is
  `{ id, recipient_id, item_type, title_enc, content_key_wrapped, content_enc, aad_version, position, created_at, updated_at }`
  (`id` is the UUID; the numeric primary key is never exposed). New items are appended:
  `position` = current maximum + 1.
- **Rate limits:** standard tier.
- **Audit:** `vault_item.created` (`item_id`, `recipient_id`, `item_type` — never content,
  never ciphertext).

### GET `/recipients/{id}/items` (Phase 2b)

- **Response:** `200`, `{ "items": [VaultItemSummaryDto] }` ordered by `position`, then
  creation order. VaultItemSummaryDto is VaultItemDto **without `content_enc`** — enough to
  decrypt and show titles; bodies are fetched one at a time.
- **Possible errors:** `404 NOT_FOUND` (recipient scoping as above), `403 ACCOUNT_NOT_ACTIVE`.
- Soft-deleted items are never listed. No audit entry (read-only).

### POST `/recipients/{id}/items/reorder` (Phase 2b)

- **Request body:** `{ "order": ["uuid", …] }` — the **complete** list of that recipient's
  live item IDs, in the wanted order.
- **Validation:** distinct canonical UUIDs; the set must equal the recipient's current live
  items exactly — a missing, extra, deleted, or other-recipient/other-user ID is
  `422 VALIDATION_FAILED` on `order` (so a stale client can't silently drop an item from
  the ordering, and nothing reveals whether a foreign ID exists).
- **Response:** `200`, `{ "items": [VaultItemSummaryDto] }` in the new order (`position`
  rewritten 1…n). Ciphertext is untouched — ordering never needs re-encryption.
- **Audit:** `vault_item.reordered` (`recipient_id`, `item_count`).

### GET · PATCH · DELETE `/vault-items/{uuid}` (Phase 2b)

- **All three:** `{uuid}` resolves only within the caller's own live items whose recipient
  is also live; anything else — another user's item, a deleted item, a malformed ID — is
  `404 NOT_FOUND`. `403 ACCOUNT_NOT_ACTIVE` applies. Standard rate tier.
- **GET:** `200`, `{ "item": VaultItemDto }` including `content_enc`. No audit entry.
- **PATCH body:** `{ "title_enc", "content_enc", "content_key_wrapped", "aad_version": 1 }`
  — **all required**. An edit is a full replacement because every edit rotates the content
  key (`06_Cryptography.md` §10), which re-encrypts title and body. `id`, `item_type` and
  the recipient can't be changed (they're bound into the AAD; moving an item means creating
  it under the other recipient and deleting this one). Same envelope validation and
  `413 PAYLOAD_TOO_LARGE` as create, plus `422` on `content_key_wrapped` if it is
  byte-identical to the stored one — an unrotated key. For a `file_bundle` the body is
  `{ "title_enc", "content_key_wrapped", "aad_version": 1 }` — a rename under a rotated
  key; `content_enc` must be absent. Attachments are untouched by an edit: they have their
  own keys. Response `200`,
  `{ "item": VaultItemDto }`. **Audit:** `vault_item.updated` (`item_id`, `recipient_id`).
- **DELETE:** no body and **no step-up password** — a session that can PATCH can already
  overwrite the content, so a password here would protect nothing (`19_Decision_Log.md`
  Decision #17); the UI asks for explicit confirmation instead. Soft delete; the ID stays
  reserved. The item's completed attachments are soft-deleted with it, with the same
  `deleted_at`; unfinished uploads on it are discarded. Response `200 { "ok": true }`.
  **Audit:** `vault_item.deleted` (`item_id`, `recipient_id`, `attachment_count`). It can
  be restored for 30 days from `GET /trash`, then it is purged.

### Attachments (Phase 2c)

Decisions #21–#27 in `19_Decision_Log.md`; format in `06_Cryptography.md` §12. All
endpoints: Sanctum session, CSRF header on writes, active account
(`403 ACCOUNT_NOT_ACTIVE`). `{uuid}` resolves only to the caller's own attachment whose
item and recipient are both live — anything else is `404 NOT_FOUND`.

**AttachmentDto:** `{ id, item_id, filename_enc, mime_type_enc, attachment_key_wrapped,
size_bytes, chunk_count, received_chunks, status, aad_version, created_at, updated_at }`.
`id` is the client-generated attachment UUID and `item_id` the item's UUID; `status` is
`uploading` or `complete`; the three `*_enc`/`*_wrapped` values are base64 envelopes;
`size_bytes` is ciphertext bytes. The storage path is never included.

**StorageDto:** `{ limit_bytes, used_bytes, trashed_bytes }` — the quota
(`legacyvault.attachment_quota_bytes`, 10,485,760 by default), everything currently
counted against it, and the part of that held by deleted files awaiting purge.

#### GET `/vault/storage`

- **Response:** `200 { "storage": StorageDto, "undo_window_days": 30 }`. The client never
  hard-codes the limit or the undo window.

#### GET `/vault-items/{uuid}/attachments`

- **Response:** `200 { "attachments": [AttachmentDto], "storage": StorageDto }` — completed
  attachments only, oldest first. No audit entry.

#### POST `/vault-items/{uuid}/attachments` — declare an upload

- **Request body:**
  ```json
  {
    "id": "client-generated UUIDv4 (also the X-Upload-Id)",
    "filename_enc": "base64 envelope, ≤ 1024 decoded bytes",
    "mime_type_enc": "base64 envelope, exactly 157 decoded bytes",
    "attachment_key_wrapped": "base64 envelope, exactly 61 decoded bytes",
    "size_bytes": 2097239,
    "chunk_count": 3,
    "aad_version": 1
  }
  ```
- **Validation:** envelope structure; `chunk_count` between 1 and
  `ceil(quota / 1,048,605)`; `size_bytes` must be a size that many chunks can have:
  `(chunk_count − 1) × 1,048,605 + 30 ≤ size_bytes ≤ chunk_count × 1,048,605`.
- **Quota:** `used_bytes + size_bytes` must not exceed the limit, checked and reserved in
  one transaction → otherwise `413 QUOTA_EXCEEDED`. Nothing has been uploaded at that point.
- **Possible errors:** `422 VALIDATION_FAILED`, `413 QUOTA_EXCEEDED`,
  `409 ATTACHMENT_ID_CONFLICT` (the caller already has an attachment with this `id`,
  deleted ones included), `403 ATTACHMENT_LIMIT_REACHED` (20 live attachments per item),
  `404 NOT_FOUND`, `429 RATE_LIMITED`.
- **Response:** `201 { "attachment": AttachmentDto, "storage": StorageDto }` with
  `status: "uploading"`, `received_chunks: 0`.
- **Rate limits:** 20/hour/user (`05_Security_Architecture.md` §7).
- **Audit:** none yet — `attachment.uploaded` is written when the upload completes.

#### POST `/attachments/{uuid}/chunks` — send the next chunk

- **Headers:** `Content-Type: application/octet-stream`, `X-Chunk-Index` (zero-based),
  `X-Chunk-Total`, `X-Upload-Id`.
- **Request body:** the raw chunk envelope — `0x01 ‖ nonce(12) ‖ ciphertext ‖ tag(16)`.
- **Validation:** `X-Upload-Id` equals the attachment ID and `X-Chunk-Total` equals the
  declared `chunk_count` (else `422`); `X-Chunk-Index` equals `received_chunks` — chunks
  arrive strictly in order, each exactly once (else `409 CHUNK_SEQUENCE_ERROR`, also for
  any chunk sent to a completed attachment); the body is exactly 1,048,605 bytes for every
  chunk but the last and exactly the remainder of `size_bytes` for the last, and starts
  with the version byte `0x01` (else `422`). That is a check of the *ciphertext container*
  only; plaintext inspection is impossible (`05_Security_Architecture.md` §14–15).
- **Response:** `200 { "received_chunk": int }`. For the last chunk:
  `200 { "received_chunk": int, "attachment": AttachmentDto, "storage": StorageDto }` with
  `status: "complete"`.
- **Resume:** after a failed request, `GET /attachments/{uuid}` and continue from
  `received_chunks`.
- **Rate limits:** standard tier.
- **Audit:** `attachment.uploaded` on completion (`attachment_id`, `item_id`,
  `recipient_id`, `size_bytes`, `chunk_count` — never a filename).

#### GET `/attachments/{uuid}` · GET `/attachments/{uuid}/content`

- **GET `/attachments/{uuid}`:** `200 { "attachment": AttachmentDto }` — also for an
  upload in progress.
- **GET `…/content`:** the stored ciphertext, streamed: `Content-Type:
  application/octet-stream`, `Content-Disposition: attachment; filename="{uuid}.bin"`,
  `Content-Length: size_bytes`, `Cache-Control: no-store`. `404` while the upload is
  unfinished. The client splits the stream at multiples of 1,048,605 bytes and decrypts
  each chunk. No audit entry (read-only).

#### DELETE `/attachments/{uuid}`

- **Completed attachment:** soft delete; restorable for 30 days, and it keeps counting
  against the quota until purged or erased. `200 { "ok": true, "storage": StorageDto }`.
  **Audit:** `attachment.deleted` (`attachment_id`, `item_id`, `size_bytes`).
- **Unfinished upload:** abort — the row and the partial file are removed immediately and
  the reservation is released. Same response. **Audit:** `attachment.upload_aborted`.
- No step-up password, as for items (Decision #17).

### Recently deleted: restore and erase (Phase 2c)

Decision #28. The undo window is `legacyvault.trash.undo_window_days` (30).

#### GET `/trash`

- **Response:**
  ```json
  {
    "undo_window_days": 30,
    "recipients": [ { …RecipientDto, "deleted_at": "…", "purge_at": "…", "item_count": 2 } ],
    "items": [ { …VaultItemSummaryDto, "deleted_at": "…", "purge_at": "…" } ],
    "attachments": [ { …AttachmentDto, "recipient_id": 7, "deleted_at": "…", "purge_at": "…" } ],
    "storage": StorageDto
  }
  ```
  Only entries still inside the window, and only the top of each deleted tree: deleted
  recipients; deleted items whose recipient is live; deleted attachments whose item is
  live. `item_count` is how many items come back with the recipient.

#### POST `/trash/recipients/{id}/restore` · `/trash/items/{uuid}/restore` · `/trash/attachments/{uuid}/restore`

- Restores the entry together with the children deleted in the same operation (identical
  `deleted_at`). A restored recipient loses `is_emergency_contact` if another recipient
  carries it now.
- **Possible errors:** `404 NOT_FOUND` (not the caller's, not deleted, past the window, or
  its parent is not live); `403 ITEM_LIMIT_REACHED` / `403 ATTACHMENT_LIMIT_REACHED` when
  the live parent is already full.
- **Response:** `200 { "recipient": RecipientDto }` / `{ "item": VaultItemSummaryDto }` /
  `{ "attachment": AttachmentDto }`.
- No step-up password. **Audit:** `recipient.restored` (`recipient_id`, `item_count`),
  `vault_item.restored` (`item_id`, `recipient_id`, `attachment_count`),
  `attachment.restored` (`attachment_id`, `item_id`).

#### DELETE `/trash/recipients/{id}` · `/trash/items/{uuid}` · `/trash/attachments/{uuid}`

- Erases the deleted entry and everything under it now: attachment files, then rows.
  Irreversible; it is what frees quota without waiting for the window to end.
- **Request body:** `{ "password": string }` — step-up re-auth
  (`05_Security_Architecture.md` §3), limiter `step-up`. `401 INVALID_CREDENTIALS` on a
  wrong password.
- **Response:** `200 { "ok": true, "storage": StorageDto }`. `404` as for restore.
- **Audit:** `recipient.purged` / `vault_item.purged` / `attachment.purged` with
  `reason: "user_requested"` and the counts of what went with it.

The scheduled purge (`php artisan vault:purge-deleted`, hourly — Decision #29) writes the
same events with `actor_type: system` and `reason: "window_expired"`, plus
`attachment.upload_expired` for abandoned uploads.

### POST `/vault/integrity-events` (Phase 2d)

Decision #31. The browser reports a decryption/format check that failed on one of the
caller's own items, attachments or sealed fields, so it lands in the audit log
(`06_Cryptography.md` §9). The server cannot verify a report; it bounds and labels it.

- **Request** (auth, active account, CSRF; limiter `integrity-reports`, 30/hour/user):
  ```json
  {
    "events": [
      { "kind": "item", "part": "item.content", "reason": "authentication_failed",
        "surface": "preview", "item_id": "3f0c…" },
      { "kind": "attachment", "part": "attachment.chunk", "reason": "length_mismatch",
        "surface": "preview", "item_id": "3f0c…", "attachment_id": "9b21…" },
      { "kind": "sealed_field", "part": "recipient.name", "reason": "unreadable",
        "surface": "recipients", "recipient_id": 7 }
    ]
  }
  ```
  - `events`: 1–10 entries.
  - `kind` ∈ `item | attachment | sealed_field`; `part` must belong to the kind:
    `item.key | item.title | item.content`;
    `attachment.key | attachment.name | attachment.mime | attachment.chunk`;
    `recipient.name | recipient.relationship | emergency_contact.name`.
  - `reason` ∈ `authentication_failed | malformed | unknown_version | length_mismatch | unreadable`.
  - `surface` ∈ `preview | list | editor | download | trash | recipients`.
  - IDs: `item_id` (UUIDv4) for `item`; `item_id` + `attachment_id` for `attachment`;
    `recipient_id` (integer) for `recipient.*`; none for `emergency_contact.name`. They must
    name the caller's own rows (soft-deleted included); the attachment must belong to the
    item. Unknown keys are ignored and never stored — there is no free-text field.
- **Response:** `202 { "recorded": 2 }` — the number of new audit rows. A repeat of the
  same (kind, IDs, part) within 24 h is accepted and not recorded again.
- **Errors:** `422 VALIDATION_ERROR` (any event invalid, or an ID that isn't the caller's —
  nothing is recorded for that request); `429 RATE_LIMITED` (request limiter, or more than
  50 recorded events in 24 h).
- **Audit:** one `integrity.check_failed` row per new event, `actor_type: user`, metadata
  `kind`, `part`, `reason`, `surface`, `item_id` / `attachment_id` / `recipient_id`
  (for items and attachments, `recipient_id` is read from the database row) and
  `event_source: "client"`.

### GET `/checkin/confirm/{token}` (Signed-link)

- **Purpose:** one-click check-in from a reminder notification, without requiring a full login.
- **Auth:** Signed-link (HMAC-signed, expiring, single-use — `06_Cryptography.md` §17), not a
  session cookie.
- **Validation:** signature valid, not expired, not already consumed, token's embedded
  user/checkin binding matches.
- **Possible errors:** `410 LINK_EXPIRED`, `409 LINK_ALREADY_USED`, `400 INVALID_TOKEN`.
- **Response:** `200`, confirmation page data (or redirect to a confirmation SPA route);
  `checkins` row updated, state reset to `active`.
- **Rate limits:** 10/hour per token family (not per IP — a link can legitimately be opened
  from a notification on any network).
- **Audit:** `checkin.confirmed_via_link`.

### GET `/emergency-contact/confirm/{token}` (Signed-link, Public)

- **Purpose:** emergency contact confirms the user is alive, pausing the delivery process.
- **Auth:** Signed-link, Public (emergency contact has no account).
- **Validation:** same signed-link checks as above; additionally validates the check-in state
  is currently `emergency_verification` (a stale/replayed link against a since-resolved state
  is rejected, not silently accepted).
- **Possible errors:** `410 LINK_EXPIRED`, `409 STATE_NO_LONGER_PENDING`.
- **Response:** `200`, simple confirmation page; `checkins.state` → `active`,
  `users.status` unaffected.
- **Rate limits:** single-use, not rate-limit-relevant.
- **Audit:** `checkin.emergency_contact_confirmed_alive`.

### GET `/delivery/access/{token}` (Signed-link, Public)

- **Purpose:** recipient views their delivered, decrypted content.
- **Auth:** Signed-link, Public, plus a lightweight per-method verification step (see
  `13_Delivery_Engine.md` §Recipient Verification — e.g. last-4-digits confirmation or
  email-link possession, decided per delivery method).
- **Validation:** signature valid, not expired (delivery access links are longer-lived than
  check-in links — e.g. 90 days, matching the retention window in
  `05_Security_Architecture.md` §21), token bound to a specific `recipient_id`.
- **Possible errors:** `410 LINK_EXPIRED`, `403 VERIFICATION_FAILED`.
- **Response:** `200`, decrypted content + attachment download links (each individually signed
  and short-lived).
- **Rate limits:** 20/hour per token, generous but bounded against scripted scraping of an
  intercepted link.
- **Audit:** `delivery.recipient_accessed` (timestamp, IP, user agent —
  `05_Security_Architecture.md` §17).

## 4. Versioning policy

`/api/v1` is frozen once Phase 2 ships to real users; breaking changes require `/api/v2` and a
documented migration window, never an in-place breaking change to `v1` (`AI_RULES.md` #12).

## 5. Admin endpoints

Scoped to `is_admin` policy (`05_Security_Architecture.md` §2), every action audit-logged with
`actor_type = admin`, and admin accounts require 2FA with no exception. Full admin surface
(suspend, view abuse reports, force-resend delivery notification) is specified in detail when
Phase 4 admin tooling is scoped — placeholder endpoints listed in §2 above pending that pass.
