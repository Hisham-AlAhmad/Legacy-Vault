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

Master Key
   │
   ├─derives per-item──► Content Key (per vault_item) ──encrypts──► item content (AES-256-GCM)
   ├─derives per-item──► Attachment Key (per attachment) ──encrypts──► attachment bytes
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
- **Content/Attachment/Metadata Keys:** derived per-item (HKDF from Master Key + a random
  per-item salt/ID) rather than reusing the Master Key directly for bulk encryption, so a
  single item's key material is scoped and the Master Key itself is used minimally.

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

## 9. AES-256-GCM details

- All bulk encryption (item content, attachments, exported backups) uses AES-256-GCM: a
  256-bit key, 96-bit nonce, 128-bit authentication tag.
- The GCM tag is verified before any plaintext is used downstream — a failed tag check is
  treated as tampering, not a silent fallback, and is audit-logged as a potential-integrity
  event (`05_Security_Architecture.md` §17).
- AAD (additional authenticated data): item ID + user ID + a version byte are bound into the
  ciphertext as AAD, so ciphertext cannot be replayed under a different item/user context even
  if somehow relocated in the database (defense-in-depth beyond normal access control).

## 10. Key rotation

- **Content keys:** rotated on each substantive edit of a vault item (a fresh per-item content
  key is derived and the item is fully re-encrypted), bounding how much ciphertext ever shares
  one key.
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

## 13. Message encryption

- Rich-text item content is serialized (e.g. sanitized HTML or a JSON document structure),
  then encrypted as a single AES-256-GCM blob with the per-item content key.

## 14. Attachment encryption

Covered in §12. Additionally: attachment metadata (original filename, MIME type, size) is
itself encrypted (§15) — a database-only leak should not reveal "this user has a file named
`will_and_testament_final.pdf`," which is itself sensitive metadata.

## 15. Metadata encryption

- Fields encrypted at rest even though they're "just metadata": original attachment filename,
  recipient relationship label (optional — name/phone/email are needed in plaintext-ish form
  for delivery routing, see below), item titles/subjects.
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
  integrity warning in the UI, never as empty text. Reporting it to the audit log (§9) lands
  with Phase 2d.
- Cross-language known-answer vector pinned in both `frontend/src/crypto/crypto.test.ts`
  and `backend/tests/Unit/SealedFieldVectorTest.php` (`15_Testing.md` §2).

## 16. Integrity verification

- GCM tags (§9) cover per-blob integrity/authenticity.
- The "preview" flow (Flow 2 step 7) decrypts the *actual stored ciphertext*, not a
  separately-cached plaintext copy, specifically so a bug that corrupts stored ciphertext is
  caught by the user's own preview before it ever matters at delivery time.

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
