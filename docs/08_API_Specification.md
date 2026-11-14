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
| GET `/recipients/{id}/items` | List vault items for a recipient |
| POST `/recipients/{id}/items` | Create vault item |
| PATCH `/vault-items/{id}` | Update vault item |
| DELETE `/vault-items/{id}` | Delete vault item |
| POST `/vault-items/{id}/attachments` | Upload attachment (chunked) |
| DELETE `/attachments/{id}` | Delete attachment |
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
  delete; response `200 { "ok": true }`. **Audit:** `recipient.deleted`.
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
- **Auth:** Required, owner-only.
- **Request body:** `{ "item_type": "message|file_bundle", "title_enc": base64, "content_enc": base64|null, "content_key_wrapped": base64, "nonce": base64, "aad_version": int }`.
- **Validation:** ciphertext size caps (defense against abuse, not a meaningful content check
  since the server can't read it), `recipient_id` ownership check.
- **Possible errors:** `422 VALIDATION_FAILED`, `404 RECIPIENT_NOT_FOUND` (scoped — a
  recipient belonging to another user returns 404, not 403, to avoid confirming existence),
  `413 PAYLOAD_TOO_LARGE`.
- **Response:** `201`, vault item DTO (metadata only — ciphertext echoed back only if the
  client needs confirmation of exact stored bytes, typically not needed since client already
  has the plaintext locally).
- **Rate limits:** standard tier.
- **Audit:** `vault_item.created` (metadata only, e.g. item id/recipient id — never content).

### POST `/vault-items/{id}/attachments`

- **Purpose:** upload an encrypted attachment, chunked.
- **Auth:** Required, owner-only.
- **Headers:** `Content-Type: application/octet-stream` per chunk, `X-Chunk-Index`,
  `X-Chunk-Total`, `X-Upload-Id`.
- **Request body:** raw encrypted chunk bytes.
- **Validation:** quota check (remaining of 100 MB) before accepting bytes, chunk sequencing
  validated (no gaps, no duplicate final chunk), server-side content-sniff of the *ciphertext
  container* only for gross malformation, not plaintext inspection (see
  `05_Security_Architecture.md` §15 on why plaintext scanning isn't possible here).
- **Possible errors:** `422 VALIDATION_FAILED`, `413 QUOTA_EXCEEDED`, `409 CHUNK_SEQUENCE_ERROR`.
- **Response:** `200` per chunk with `{ "received_chunk": int }`; final chunk response
  includes the completed attachment DTO.
- **Rate limits:** 20 upload-sessions/hour/user (`05_Security_Architecture.md` §7).
- **Audit:** `attachment.uploaded` on completion.

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
