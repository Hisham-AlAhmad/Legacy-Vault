# 18 — Threat Model

Each scenario states the assumed attacker capability, the answer, and the specific mechanism
that makes the answer true — not just an assertion.

## Scenario 1 — If the database leaks

**Can attackers read messages?**

No, for any account currently `active` (user alive, checking in normally).

**Why:** `vault_items.content_enc`, `attachments.filename_enc`/file bytes, and
`recipients.name_enc`/`relationship_label` are all AES-256-GCM ciphertext
(`06_Cryptography.md` §9, §14, §15). The keys needed to decrypt them — content keys, wrapped
by the Master Key — are themselves only present in the database in wrapped (encrypted) form
(`encrypted_keys` table). The password-wrapped copy requires the user's password (never
stored, only Argon2id-hashed, which is one-way). The recovery-wrapped copy requires the
Recovery Key (never stored anywhere, server or otherwise). The only copy the *server* can
unwrap on its own is the SWK-wrapped copy — and unwrapping it requires the SWK, which lives in
environment configuration, **not in the database**. A database-only leak (e.g. a SQL injection
dump, a misconfigured backup exposure, a leaked mysqldump file) does not include `.env`.

**What a database-only leak does expose:** email addresses, recipient email/phone (plaintext
by necessity, `06_Cryptography.md` §15), check-in timing metadata, audit log metadata, and
for attachments their count, exact sizes and filename lengths (needed for the server-side
quota — `19_Decision_Log.md` Decision #24; filenames and MIME types themselves are
encrypted). This is a real exposure, just not vault content — stated plainly rather than
glossed over. A leak of the attachment directory exposes the same sizes and nothing more:
the files are named by server-generated UUIDs and contain only ciphertext.

## Scenario 2 — If Hostinger (the host) gets hacked

This is broader than Scenario 1 — it implies potential access to the filesystem, including
`.env`, not just the database.

**Can they decrypt files? Can they decrypt messages?**

If the compromise includes read access to `.env` (and therefore the SWK), then yes, for every
account, an attacker with both the database and the SWK could unwrap every `server_wrapped_master`
key and decrypt every vault. **This is the honest cost of the zero-access model**
(`06_Cryptography.md` §3) and is stated as such, not minimized. It is the reason the SWK is
treated as the single most sensitive secret in the system (`05_Security_Architecture.md` §18)
and why its access is restricted to one code path (`06_Cryptography.md` §5) — restricting
*application-level* misuse, though it does not defend against full host-level compromise that
reads environment configuration directly. A true zero-knowledge design (recipient-pre-holds-key
model, `06_Cryptography.md` §5 alternatives considered) would resist even this scenario, at
the cost of the automatic-decrypted-delivery requirement the product explicitly needs — this
trade-off was made deliberately and is revisitable (`17_Roadmap.md` post-v1 candidates).

**Can they forge deliveries? Can they modify recipients?**

A host-level compromise with write access could modify `recipients` rows (email/phone are
plaintext) to redirect delivery, or directly invoke the delivery code path. Mitigations:
audit logging (`05_Security_Architecture.md` §17) would record any such manipulation with
timestamps, giving a forensic trail, but audit logs stored in the same compromised database
are not tamper-proof against this level of access either — full protection against this
requires the external monitoring/off-host backup posture in `14_Deployment.md` §6, §8 (an
off-host copy of audit logs and backups an on-host-only attacker can't also alter).

## Scenario 3 — If a user's laptop gets infected

**Can malware read decrypted content?**

Yes, if the malware runs while the user has an active session with the Master Key unwrapped in
browser memory (`10_Frontend_Architecture.md` §3) — this is true of essentially any
client-side-encryption design; the browser has to have the plaintext to let the user read/edit
it. This is not a gap specific to Legacy Vault's design, it's the general boundary of what
client-side encryption can protect against.

**How do we reduce the damage?**

- The Master Key is held only in memory, never written to disk-backed storage
  (`localStorage`/`sessionStorage`/IndexedDB) — a malware scan of disk artifacts after the
  session ends finds nothing (`AI_RULES.md` #2).
- Session/idle timeouts (`05_Security_Architecture.md` §3) bound how long a key remains
  resident if the user walks away.
- CSP (`05_Security_Architecture.md` §6) reduces the chance of a *web-based* XSS-style
  in-browser compromise reaching the crypto module, though this scenario is about device-level
  malware, which CSP cannot address — that's an OS/endpoint security problem outside this
  product's boundary, and is disclosed as such rather than implied to be covered.

## Scenario 4 — If someone steals cookies

**What protections exist?**

- Cookies are `HttpOnly` (unreadable to JS, so a pure XSS payload can't read them directly),
  `Secure` (never sent over plain HTTP), `SameSite=Strict` (not sent cross-site)
  (`05_Security_Architecture.md` §4).
- A stolen session cookie (e.g. via a network-level attack on an unencrypted network, or a
  device-level compromise that reads browser storage directly) grants the *authenticated
  session*, not the Master Key — the Master Key is separately held in JS memory
  (§3 above) and is not retrievable from the cookie alone. An attacker with just the cookie can
  perform authenticated actions (view dashboard metadata, change settings) but cannot decrypt
  vault content without also somehow obtaining the unwrapped Master Key from the live browser
  process, which is a materially harder bar than cookie theft alone.
- Step-up re-authentication (`05_Security_Architecture.md` §3) on sensitive actions (password
  change, recovery key regeneration) limits what a stolen-cookie-only attacker can actually do
  even at the account level.
- Session revocation (Flow 10) lets a user immediately invalidate a stolen session once
  noticed, server-side, not just client-side.

## Scenario 5 — If Google OAuth is compromised (future feature)

**Can the vault still be decrypted?**

No. OAuth (when added, `01_Product_Requirements.md` AUTH-5) authenticates *account access*
only — it is explicitly not a source of key material in this design. The Master Key is wrapped
by a Password-Derived Key and a Recovery-Key-Derived Key (`06_Cryptography.md` §4), neither of
which involves Google in any way. A compromised Google account tied to Legacy Vault login would
let an attacker log in (same blast radius as a stolen password, mitigated the same ways: 2FA,
session policy) but would not, on its own, unwrap the Master Key — the attacker would still
need the user's actual Legacy Vault password or Recovery Key to derive the wrapping key. This
must remain true if/when OAuth is implemented — it is a design constraint on that future work,
not an assumption to revisit casually.

## Scenario 6 — Insider threat (someone with legitimate database or admin access)

**Can a database administrator read vault content?**

Not from the database alone (Scenario 1 reasoning applies equally to an insider with the same
access level).

**Can an application admin (support role) read vault content?**

No — admin tooling (`08_API_Specification.md` §5) has no code path to vault content; the
`is_admin` policy grants account-management actions (suspend, view metadata), not a
decrypt-on-demand capability. If an admin needed to invoke `DeliveryTriggerService` outside
its normal trigger (there is no legitimate reason to), it would require direct code-level
access to the restricted service, not something reachable through the admin API surface, and
would be audit-logged with `actor_type = admin` and a mandatory reason if such an override
mechanism is ever built (`11_CheckIn_Engine.md` §8) — currently, no such override exists for
delivery itself, only for pausing the check-in engine.

## Scenario 7 — Recipient forwards or loses their delivery-access link

Since the link is single-recipient-scoped and time-limited (`13_Delivery_Engine.md` §4), a
forwarded link exposes only that one recipient's content, for at most the 90-day access
window, not the whole vault. Recipient verification (§4 of that document) adds a secondary
check for the highest-exposure delivery method. This is a smaller blast radius than, e.g., a
single shared decryption key handed to all recipients would be — a direct benefit of
per-recipient independent encryption (VAULT-5).

## Summary table

| Scenario | Vault content exposed? | Mechanism that limits/prevents it |
|---|---|---|
| DB-only leak | No | SWK not in DB; password/recovery wraps unreachable |
| Full host compromise (incl. `.env`) | Yes, potentially | Honest cost of zero-access model; see Decision Log |
| Infected user device (active session) | Yes, for that session | Inherent to client-side crypto; bounded by session/memory-only key handling |
| Stolen session cookie alone | No (account access only) | Master Key not derivable from cookie; HttpOnly/Secure/SameSite |
| Compromised OAuth (future) | No | OAuth never a key-wrapping factor, by design constraint |
| Insider with DB/admin access | No (without also having SWK) | Same as DB-only leak; admin surface has no decrypt path |
| Lost/forwarded delivery link | Only that one recipient's content, time-boxed | Per-recipient scoping, short-lived signed tokens |
