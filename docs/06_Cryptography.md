# 06 — Cryptography

## 1. Goals

- Vault content is unreadable to the server, database, backups, or a shared-hosting-level
  compromise, **during normal operation** (user alive, checking in).
- Vault content becomes readable to the correct recipient, and only the correct recipient,
  automatically, if and only if the check-in/grace/emergency-contact process (`11_CheckIn_Engine.md`)
  confirms a trigger condition.
- The mechanism for the second point is honestly documented as a deliberate deviation from
  pure zero-knowledge, not hidden behind zero-knowledge marketing language.

## 2. Threat model summary (full detail in `18_Threat_Model.md`)

Protects against: database-only leak, backup leak, passive server-storage compromise,
network interception, a stolen session cookie (limited — see `18_Threat_Model.md`), a
malicious insider with database access but not application-server code-execution access.

Does **not** protect against: a full server compromise with code execution at the moment a
delivery trigger fires (attacker could intercept the plaintext at that instant — same as
compromising any system at its moment of legitimate decryption), a compromised user device
during active use, a user who loses both password and Recovery Key (unrecoverable by design,
not a bug).

## 3. Zero-Access Architecture (not zero-knowledge)

Per the resolved product decision: the server must be able to produce plaintext for recipients
automatically, with no user present. True zero-knowledge is incompatible with that
requirement. The model actually implemented is:

- **While the user is alive and active:** the server only ever stores and handles ciphertext
  and wrapped keys. No code path decrypts vault content for any routine operation (viewing the
  dashboard, editing, previewing — all decryption for the *user's own* preview happens
  client-side using keys already unwrapped in their browser session).
- **At verified delivery trigger only:** a single, narrowly-scoped, heavily audited service
  (`DeliveryTriggerService`, see `13_Delivery_Engine.md`) unwraps the Master Key using the
  server-held wrap (§5) and uses it to decrypt each recipient's content immediately before
  sending it out. The unwrapped key is held in process memory only, only for the duration of
  the delivery job, and is never persisted, logged, or cached.
- This is called **zero-access**, not zero-knowledge, in every document, in the UI's security
  explanation, and in any user-facing security page. Calling it "zero-knowledge" would be a
  false claim given point one doesn't hold at delivery time — Core Principle #2 in
  `00_Project_Vision.md` requires we not make that claim.

## 4. Key hierarchy

```
Password  ──Argon2id──►  Password-Derived Key (PDK)
                              │
                              ├─wraps──► Master Key  ◄──wraps── Recovery-Key-Derived Key (RDK)
                              │                                        ▲
                              │                                        │
                              └───────────────►  also wrapped by  ─────┘
                                                  Server Wrap Key (SWK)
                                                  (see §5, zero-access mechanism)

Master Key  (HKDF input only — never used directly as an AES key)
   │
   ├─derives──► Content-Wrap Key ──wraps──► Content Key (random, per vault_item version)
   │                                            └──encrypts──► item title + body (AES-256-GCM)
   │             └──wraps──► Attachment Key (random, per attachment)
   │                             └──encrypts──► filename, MIME type, file chunks (AES-256-GCM)
   └─derives──► Metadata Key ──encrypts──► sensitive metadata fields
```

- **Master Key:** 256-bit random key, generated client-side at registration
  (`crypto.getRandomValues`), never transmitted in plaintext, ever.
- **Password-Derived Key (PDK):** derived from the user's password via Argon2id (§6). Wraps
  the Master Key. Recomputed client-side at every login to unwrap the Master Key into browser
  memory for the session.
- **Recovery-Key-Derived Key (RDK):** derived from a separately generated high-entropy Recovery
  Key (§9) via Argon2id. Wraps a second copy of the Master Key. Used only during recovery
  flows.
- **Server Wrap Key (SWK):** a server-held key (from `.env`, see `05_Security_Architecture.md`
  §18), used to wrap a third copy of the Master Key. This is the zero-access mechanism — see
  §5.
- **Content Keys:** a fresh random 256-bit key per vault item *version*
  (`crypto.getRandomValues`), stored wrapped (AES-256-GCM) under the **Content-Wrap Key** =
  HKDF-SHA256(ikm = Master Key, salt = empty, info = `"legacy-vault/v1/content-wrap-key"`,
  32 bytes). Random-and-wrapped rather than derived-per-item: see `19_Decision_Log.md`
  Decision #13. A single item's key material is scoped to that item, and the Master Key
  itself is only ever HKDF input — it never encrypts anything directly.
- **Metadata Key:** HKDF-derived from the Master Key, one per user (§15).
- **Attachment Keys:** a random 256-bit key per attachment, stored wrapped under the same
  Content-Wrap Key (the AAD label `attachment.key` separates it from an item key). It
  encrypts that attachment's filename, MIME type and chunks and is never reused for
  another file (§12, `19_Decision_Log.md` Decision #21).

## 5. The Server Wrap Key mechanism, in detail

This is the part that makes automatic decrypted delivery possible, and it deserves to be
spelled out precisely rather than hand-waved:

1. At registration, alongside the password-wrapped and recovery-key-wrapped Master Key copies,
   the client also encrypts the Master Key with a public key whose matching private key is the
   Server Wrap Key equivalent — in practice, implemented as envelope encryption where the
   server, on receiving the plaintext Master Key over the (TLS-protected) registration
   request **once, only at this step**, immediately wraps it with the SWK and discards the
   plaintext. *(Note: this is the one moment the server does see the plaintext Master Key —
   documented here explicitly rather than obscured, since it's the honest cost of the
   zero-access model. It happens over an authenticated, TLS-protected connection, at account
   creation only, and the plaintext is never written to disk or logs — only the SWK-wrapped
   ciphertext is persisted.)*
2. The SWK-wrapped Master Key is stored in `encrypted_keys` (`07_Database_Design.md`),
   distinct from the password-wrapped and recovery-wrapped copies.
3. The SWK itself lives only in the application's environment configuration, never in the
   database, never in application logs.
4. The **only** code path permitted to read the SWK and use it to unwrap a Master Key is
   `DeliveryTriggerService::executeDelivery()`, invoked exclusively by the verified
   check-in-engine trigger (`11_CheckIn_Engine.md` §State Machine, terminal state
   `delivery_triggered`). This is enforced by code review convention (`AI_RULES.md` #13) and,
   where practical, by restricting the SWK-loading config value to only be read in that one
   service class.
5. Every invocation of this path is audit-logged before, during (per-recipient), and after
   completion, including which recipients received which items and when.

**Why this is an acceptable trade-off:** the user explicitly wants recipients to receive
*readable* content with no action required from anyone at time of death. That requirement is
incompatible with pure zero-knowledge. The alternative (Shamir secret-sharing across the
server + emergency contact, or a pre-distributed recipient-held key — see
`19_Decision_Log.md` Decision on Key Escrow) were considered and rejected for v1 as
meaningfully more complex and, for the recipient-held-key option, more failure-prone (a
recipient losing a key they were handed years earlier). This is revisitable — flagged in
`17_Roadmap.md` as a v2 candidate if threshold sharing proves worth the complexity.

## 6. Argon2id parameters

Two distinct uses, two distinct parameter sets — never share parameters between "hash a
password for auth" and "derive a key from a password for encryption," even though both use
Argon2id, because their performance/security trade-offs differ slightly:

| Use | Memory | Iterations | Parallelism | Output length |
|---|---|---|---|---|
| Password hash (auth, `password_hash()`/Sanctum) | 19 MiB (19456 KiB) | 2 | 1 | N/A (PHC string) |
| Password-Derived Key (PDK, client-side, wraps Master Key) | 64 MiB | 3 | 4 | 256 bits |
| Recovery-Key-Derived Key (RDK, client-side) | 64 MiB | 3 | 4 | 256 bits |

The auth hash uses OWASP's baseline recommended minimum for server-side password hashing
(cheap enough to not be a DoS vector on shared hosting's limited CPU). The client-side key
derivations use higher memory cost because they run once per login/recovery in the user's own
browser, where the CPU budget is theirs, not the shared server's — this asymmetry is
intentional given the shared-hosting CPU constraint (`04_System_Architecture.md` §1).

## 7. Salt length

- All Argon2id salts: 16 bytes (128 bits), generated fresh per use with a CSPRNG, stored
  alongside the resulting hash/wrapped-key record (salts are not secret, uniqueness is what
  matters).

## 8. Nonce generation

- AES-256-GCM nonces: 12 bytes (96 bits), generated via CSPRNG per encryption operation,
  **never reused** for a given key. Because content keys are per-item (§4), nonce reuse risk
  is scoped to "one nonce collision within one item's lifetime of edits," which is why every
  edit to an item's content generates a fresh nonce (and, for defense-in-depth, edits rotate
  to a fresh per-item content key rather than reusing the same key across every historical
  edit — see §Key Rotation).
- A nonce never lives in a column of its own. Every ciphertext is stored as the envelope
  `version (0x01) ‖ nonce (12) ‖ ciphertext ‖ tag (16)`, so each ciphertext carries exactly
  its own nonce and two ciphertexts can never share one (`19_Decision_Log.md` Decision
  #14). A content key encrypts exactly two messages in its lifetime — the item's title and
  its body — each with an independent random nonce.

## 9. AES-256-GCM details

- All bulk encryption (item content, attachments, exported backups) uses AES-256-GCM: a
  256-bit key, 96-bit nonce, 128-bit authentication tag.
- The GCM tag is verified before any plaintext is used downstream — a failed tag check is
  treated as tampering, not a silent fallback, and is audit-logged as a potential-integrity
  event (`05_Security_Architecture.md` §17).
- AAD (additional authenticated data): item ID + user ID + a version byte are bound into the
  ciphertext as AAD, so ciphertext cannot be replayed under a different item/user context even
  if somehow relocated in the database (defense-in-depth beyond normal access control).
- **Vault items (Phase 2b):** AAD = UTF-8
  `"legacy-vault|v1|{part}|user:{user_id}|recipient:{recipient_id}|item:{uuid}"`, where
  `part` is `item.key` (the wrapped content key), `item.title` or `item.content`, and
  `uuid` is the item's client-generated UUIDv4 — client-generated precisely so it is known
  before the first encryption (`19_Decision_Log.md` Decision #15). The recipient ID is
  bound as well: an item row re-pointed at another recipient fails to decrypt rather than
  being delivered to the wrong person (VAULT-5). The three parts have different labels, so
  a title can't be swapped for a body.
- **Attachments (Phase 2c):** AAD = UTF-8
  `"legacy-vault|v1|{part}|user:{user_id}|recipient:{recipient_id}|item:{item_uuid}|attachment:{attachment_uuid}"`,
  `part` ∈ `attachment.key`, `attachment.name`, `attachment.mime`; a chunk uses
  `attachment.chunk` and appends `|chunk:{index}/{total}` (§12). Both UUIDs are
  client-generated (`19_Decision_Log.md` Decisions #15, #21).
- In the client, a failed tag check on item content is a thrown `ItemIntegrityError`, and on
  an attachment a thrown `AttachmentIntegrityError` — never a `null`, an empty string or a
  partial file a caller could mistake for real content. Each error carries its `part` and
  an enumerated `reason` (`authentication_failed`, `malformed`, `unknown_version`,
  `length_mismatch`).
- **Audit-logging a client-side failure (Phase 2d):** every place that decrypts (lists,
  editor, bundle page, trash, recipients, emergency contact, downloads, preview) hands the
  failure to `features/vault/integrityReporter.ts`, which sends IDs and those enumerated
  codes — never a message, ciphertext or plaintext — to `POST /vault/integrity-events`.
  The server records `integrity.check_failed` (`19_Decision_Log.md` Decision #31). The
  server cannot verify the claim (it cannot decrypt), so the entry is labelled
  `event_source: client`; the endpoint's bounds keep it from being used to flood or forge
  the log.

## 10. Key rotation

- **Content keys:** rotated on every saved edit of a vault item (a fresh random content key
  is generated and wrapped, and title and body are fully re-encrypted under it with fresh
  nonces), bounding how much ciphertext ever shares one key. The client has no "edit in
  place" path — saving an edit *is* `rotateItem()` — and the API accepts an update only as
  a complete replacement of key, title and body, rejecting a wrapped key identical to the
  stored one (`08_API_Specification.md`).
- **Master Key:** rotated whenever a user changes password in a way that involves full
  Recovery Key regeneration (Flow 7), and can be manually triggered from the security settings
  ("Rotate encryption key") which re-wraps everything without changing the underlying content
  keys' relationship to the Master Key (re-wrap Master Key copies only — cheaper than
  re-encrypting all content).
- **Server Wrap Key (SWK):** rotation requires re-wrapping every user's SWK-wrapped Master Key
  copy in a migration job — see `19_Decision_Log.md` and `14_Deployment.md` §Key Rotation
  Procedure. This is an infrequent, deliberate operational event (e.g. annually, or on
  suspected exposure), not a routine one.

## 11. Recovery Key

- Generated client-side at registration: 256 bits of CSPRNG entropy, presented to the user as
  a formatted string (e.g. grouped Base32/Crockford, human-transcribable, with a checksum
  segment so a typo is caught before a failed-unwrap round-trip).
- Never transmitted to or stored by the server in any recoverable form — only the
  RDK-wrapped Master Key derived from it is stored.
- Regenerated (old one invalidated) every time it's used for recovery (Flow 7) — a Recovery
  Key is effectively single-use across the "I actually needed to recover" scenario, even
  though nothing stops repeated display/re-save of the *current* one from the security
  settings page.

## 12. File encryption

- Attachments are chunk-encrypted client-side (streaming AES-256-GCM per chunk with chunk
  index bound into AAD, to support large-file upload without buffering the whole file in
  browser memory) using the per-attachment key (§4), before any bytes leave the browser.
- Server stores only ciphertext chunks plus the wrapped per-attachment key.

**Implementation (Phase 2c)** — `frontend/src/crypto/attachments.ts`
(`19_Decision_Log.md` Decisions #21, #22, #24):

| Stored value | Plaintext | Key | AAD part | Size |
|---|---|---|---|---|
| `attachment_key_wrapped` | 32-byte attachment key | Content-Wrap Key (§4) | `attachment.key` | exactly 61 bytes |
| `filename_enc` | original filename, ≤ 255 characters and ≤ 995 UTF-8 bytes | attachment key | `attachment.name` | ≤ 1,024 bytes |
| `mime_type_enc` | MIME type, right-padded with `0x00` to 128 bytes | attachment key | `attachment.mime` | exactly 157 bytes |
| the file on disk | the file, cut into 1 MiB chunks | attachment key | `attachment.chunk` + `\|chunk:{index}/{total}` | chunk envelopes concatenated |

- **Chunks:** 1,048,576 plaintext bytes each (the last one 1…1,048,576); each is its own
  envelope `0x01 ‖ nonce(12) ‖ ciphertext ‖ tag(16)` with a fresh random nonce, so a full
  chunk is exactly 1,048,605 bytes on disk and chunk *i* starts at `i × 1,048,605`.
- **Index and total in the AAD.** A chunk authenticates only at its own position in a file
  of exactly that many chunks. Reordering, duplicating, dropping any chunk (including the
  last — truncation), appending one, or moving a chunk to another attachment all fail the
  GCM check. The total is `chunk_count` as stored by the server; changing that number makes
  every chunk fail.
- **Streaming.** Encryption reads the file one slice at a time and uploads each chunk
  before reading the next; decryption reads the response stream and decrypts chunk by
  chunk; each authenticated chunk is copied straight into a Blob and its buffer wiped, so
  the whole file is held once (as the Blob handed to the browser's download) — never twice,
  and never ciphertext and plaintext together.
- **Hard failure.** Any chunk failing its tag, a short or over-long stream, or a metadata
  envelope failing, throws `AttachmentIntegrityError` and nothing is handed to the user —
  there is no partial download.
- The server validates the container only — version byte and exact chunk sizes — and never
  opens anything (`AI_RULES.md` #13).
- Cross-language known-answer vector: `frontend/src/crypto/attachments.test.ts` ⇄
  `backend/tests/Unit/AttachmentVectorTest.php` (`15_Testing.md` §2).

## 13. Message encryption

- Rich-text item content is serialized as **sanitized HTML** (UTF-8; allow-list in
  `05_Security_Architecture.md` §9), then encrypted as a single AES-256-GCM blob with the
  per-item content key. The title is a plain UTF-8 string encrypted the same way under the
  same key.

**Implementation (Phase 2b)** — `frontend/src/crypto/items.ts`:

| Stored value | Plaintext | Key | AAD part | Size |
|---|---|---|---|---|
| `content_key_wrapped` | 32-byte content key | Content-Wrap Key (§4) | `item.key` | exactly 61 bytes |
| `title_enc` | title, ≤ 200 characters | content key | `item.title` | ≤ 1,024 bytes |
| `content_enc` | sanitized HTML | content key | `item.content` | ≤ 262,144 bytes |

- All three are envelopes (§8), base64 on the wire, raw bytes in the database.
- `createItem()` generates the UUID and the first content key; `rotateItem()` re-encrypts an
  existing item under a new key (§10); `decryptItem()` / `decryptItemTitle()` throw
  `ItemIntegrityError` on any failure (§9).
- The server validates envelope *structure* only and never opens one outside the delivery
  trigger (`AI_RULES.md` #13). At delivery it derives the Content-Wrap Key from the
  SWK-unwrapped Master Key with `hash_hkdf('sha256', …)`, exactly as for the Metadata Key.
- Cross-language known-answer vector pinned in `frontend/src/crypto/items.test.ts` and
  `backend/tests/Unit/VaultItemVectorTest.php` (`15_Testing.md` §2).

## 14. Attachment encryption

Covered in §12. Additionally: attachment metadata (original filename, MIME type) is itself
encrypted — a database-only leak should not reveal "this user has a file named
`will_and_testament_final.pdf`," which is itself sensitive metadata. Both are sealed under
the attachment's own key, not the user-wide Metadata Key (§12, `19_Decision_Log.md`
Decision #24). The MIME type is padded to a fixed length before sealing so its ciphertext
length does not give the type away.

**What stays visible to the server:** the file's exact ciphertext size and chunk count
(hence its plaintext size — `size_bytes` is needed for the quota, which must be enforced
without decrypting anything), the length of the filename, which item and recipient the file
belongs to, and when it was uploaded or deleted. An earlier version of this section listed
"size" among the encrypted metadata; that was never compatible with a server-side quota
and has been corrected.

## 15. Metadata encryption

- Fields encrypted at rest even though they're "just metadata": original attachment filename,
  recipient relationship label (optional — name/phone/email are needed in plaintext-ish form
  for delivery routing, see below), item titles/subjects. (Item titles are encrypted with
  their item's content key, not the Metadata Key — §13, `19_Decision_Log.md` Decision #14.)
- Fields that **cannot** be encrypted because the server must act on them operationally:
  recipient email/phone (needed to actually send delivery), check-in timestamps, account
  email (needed for login). These are called out explicitly in `07_Database_Design.md` per
  table so it's never ambiguous which columns are ciphertext and which are plaintext
  operational data.

**Implementation (Phase 2a):**

- **Metadata Key** = HKDF-SHA256(ikm = Master Key, salt = empty, info =
  `"legacy-vault/v1/metadata-key"`, 32 bytes). One key per user for all short metadata
  fields; the Master Key is uniformly random, so the `info` label alone gives domain
  separation. The server reproduces it only inside the delivery trigger, after
  SWK-unwrapping the Master Key (§5) — `hash_hkdf('sha256', …)` yields identical bytes.
- **Sealed-field envelope:** `version (0x01) ‖ nonce (12) ‖ AES-256-GCM ciphertext ‖ tag
  (16)`, base64 on the wire, raw bytes in `VARBINARY(512)` columns. Fresh nonce on every
  seal, including every re-save of an unchanged value.
- **AAD** = UTF-8 `"legacy-vault|v1|{field}|user:{user_id}"`, where `field` is one of
  `recipient.name`, `recipient.relationship`, `emergency_contact.name`. A value can't be
  moved to another user or another field undetected. Record IDs are deliberately *not*
  bound — see `19_Decision_Log.md` Decision #11.
- A failed open (bad version, malformed envelope, GCM/AAD failure) is surfaced as an
  integrity warning in the UI, never as empty text, and reported to the audit log (§9) as
  `kind: sealed_field`, `reason: unreadable` (Phase 2d).
- Cross-language known-answer vector pinned in both `frontend/src/crypto/crypto.test.ts`
  and `backend/tests/Unit/SealedFieldVectorTest.php` (`15_Testing.md` §2).

## 16. Integrity verification

- GCM tags (§9) cover per-blob integrity/authenticity.
- The "preview" flow (Flow 2 step 7) decrypts the *actual stored ciphertext*, not a
  separately-cached plaintext copy, specifically so a bug that corrupts stored ciphertext is
  caught by the user's own preview before it ever matters at delivery time.
- **As built (Phase 2d, `19_Decision_Log.md` Decision #30):** the preview re-fetches the
  item list, every item and every file, and decrypts all of it with the context of the
  recipient being previewed — including **every chunk of every file**, into a sink that
  keeps nothing, so a truncated or damaged file is caught here and not on the recipient's
  first download. Nothing is read from the editor's or list's caches, and the decrypted
  preview is not kept after the page closes. A damaged entry is shown as "would not be
  delivered" and reported (§9).

## 17. Replay protection

- Every single-use link (check-in confirmation from a notification, emergency-contact
  confirmation, recipient delivery-access link, password reset) is a signed token (HMAC or
  Laravel signed URL) with an embedded expiry and a server-side "consumed" flag checked
  atomically (`SELECT ... FOR UPDATE` or an equivalent atomic update) so the same link cannot
  be used twice, including in a race between two near-simultaneous requests.

## 18. Recovery process

Full step-by-step in `02_User_Flows.md` Flow 7. Cryptographic core: unwrap Master Key with
RDK derived from the entered Recovery Key, re-wrap with a fresh PDK from the (possibly new)
password, issue a new Recovery Key, invalidate the old one (§11).

## 19. Password reset flow

Full step-by-step in `02_User_Flows.md` Flow 6. Cryptographic core: password reset changes
the *authentication* credential only. It cannot and must not restore vault access, because the
server never had the old PDK and cannot derive it from a new password. This is presented to
the user as expected behavior, not an error state (`03_UI_UX_Guidelines.md` copy note).

## 20. Changing password (already unlocked)

Full step-by-step in `02_User_Flows.md` Flow 8. No Recovery Key involved because the Master
Key is already unwrapped in-session; it's simply re-wrapped with a new PDK.

## 21. Exporting backup

Bundle contains: ciphertext items/attachments, wrapped key material (password- and
recovery-wrapped copies — **not** the SWK-wrapped copy, which is meaningless/dangerous outside
the server context and is excluded from exports), recipient metadata. The bundle itself is
additionally wrapped for transport, so a stolen export file is no more exposed than the
database itself.

## 22. Importing backup

Reverses §21: user unwraps with password or Recovery Key client-side, re-encrypts under the
current account's active key hierarchy (fresh content keys), re-establishes the SWK-wrapped
copy via the same registration-time envelope mechanism (§5) against the *current* account's
server relationship, not the old one.

## 23. Future key rotation

Open items tracked in `17_Roadmap.md`: evaluate threshold secret-sharing as an alternative to
the SWK model once there's an emergency-contact UX mature enough to support it reliably;
evaluate periodic forced SWK rotation cadence based on real operational experience.

## 24. Cryptography do's

- Do use vetted libraries (WebCrypto in-browser, `libsodium`/`openssl` PHP extensions
  server-side) — never a hand-rolled primitive.
- Do use fresh nonces and fresh per-item keys as specified.
- Do bind context (user ID, item ID, version) into AAD.
- Do treat a GCM tag failure as tampering, always.
- Do keep the two Argon2id parameter sets (§6) distinct and documented.

## 25. Cryptography don'ts

- Don't reuse a nonce with a given key, ever.
- Don't log key material, plaintext vault content, or passwords, anywhere, including in error
  traces or debug output.
- Don't implement a "disable encryption for testing" flag (`AI_RULES.md` #6) — test against
  fixed vectors instead (`15_Testing.md`).
- Don't let any code path outside `DeliveryTriggerService` read the SWK.
- Don't call this architecture "zero-knowledge" anywhere in code, docs, or product copy — it's
  zero-access, and the distinction matters (§3).
