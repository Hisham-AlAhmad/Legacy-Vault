# 19 — Decision Log

Format: Decision, Pros, Cons, Alternatives considered, Decision made. New entries append to
the end; never edit history away — if a decision is reversed later, add a new entry that
supersedes it and links back.

---

## Decision #1 — Key escrow model: Server Wrap Key ("zero-access"), not pure zero-knowledge

**Context:** the spec asked for zero-knowledge architecture and automatic decrypted delivery
to recipients — mutually incompatible in the strict sense (`00_Project_Vision.md` §4).

**Options considered:**
1. Server-wrapped master key (chosen) — server holds a key-encrypted copy, unwraps only via
   one audited path at verified trigger time.
2. Threshold secret sharing (Shamir) between server + emergency contact(s).
3. Recipient pre-holds a decryption key, distributed out-of-band at vault creation.

**Pros of #1:** simplest to build and explain, most reliable (no dependency on an emergency
contact's key share surviving years without loss), matches the exact user requirement
("recipient sees decrypted content automatically").

**Cons of #1:** not true zero-knowledge — a full host compromise that also exposes the SWK
can decrypt any vault (`18_Threat_Model.md` Scenario 2). This is a real, disclosed trade-off.

**Cons of #2:** materially more complex; failure mode if a share-holder (emergency contact) is
unreachable at the exact moment needed; harder to explain to users; deferred to
`17_Roadmap.md` post-v1 candidates for reconsideration once there's real operational data on
emergency-contact reliability.

**Cons of #3:** recipients must correctly retain a secret for months/years before ever needing
it — high real-world loss rate, poor UX, "why did I get a mystery encrypted file years ago."

**Decision:** Option 1, documented everywhere as "zero-access," never marketed as
"zero-knowledge" (`06_Cryptography.md` §3, `00_Project_Vision.md` §4).

---

## Decision #2 — Laravel (PHP 8.3+) for backend

**Pros:** mature ecosystem, Sanctum gives cookie-based SPA auth cleanly, built-in scheduler +
queue abstractions map directly onto the shared-hosting cron pattern
(`09_Backend_Architecture.md` §5), first-class on virtually all PHP shared hosting including
Hostinger, large hiring/maintenance pool.

**Cons:** PHP's shared-nothing request model means no persistent in-process state
(irrelevant here since shared hosting rules that out anyway), Laravel's queue/scheduler
patterns need the cron-driven adaptation documented in `04_System_Architecture.md` §5 rather
than "just working" the way a VPS + Redis + daemon setup would.

**Alternatives considered:** Node/Express (would require a persistent Node process — not
natively supported on typical shared hosting the way PHP-FPM is), Django/Python (similar
persistent-process assumption issue on shared hosting).

**Decision:** Laravel — best fit for the PHP-native shared-hosting constraint.

---

## Decision #3 — React + TypeScript for frontend

**Pros:** ecosystem fit for the in-browser cryptography requirement (WebCrypto is a browser
API regardless of framework, but React + TS gives strong typing around the crypto module's
contract, `10_Frontend_Architecture.md` §6), shadcn/ui + Tailwind gives accessible primitives
out of the box (`03_UI_UX_Guidelines.md` §6, §8), builds to static assets deployable without a
Node runtime in production (`10_Frontend_Architecture.md` §10).

**Cons:** SPA-only means initial load/SEO considerations for the public marketing/landing
pages — mitigated by keeping the landing page itself simple/pre-renderable if needed, out of
scope for this decision.

**Alternatives considered:** Vue (comparable fit, team familiarity favored React), server-
rendered PHP Blade views (would complicate the client-side-only encryption requirement, since
vault crypto must happen entirely in the browser before any network request).

**Decision:** React + TypeScript.

---

## Decision #4 — MySQL for the database

**Pros:** universally available on shared hosting including Hostinger, mature JSON column
support (used for `audit_logs.metadata`, `notifications.payload`), well-understood
performance characteristics for the indexed query patterns this app needs (`07_Database_Design.md`).

**Cons:** no native row-level encryption feature comparable to some other engines — irrelevant
here since encryption happens at the application layer (`06_Cryptography.md`) rather than
relying on database-native encryption-at-rest as the primary control.

**Alternatives considered:** PostgreSQL (arguably nicer JSON/constraint features, but less
universally available on budget shared hosting plans than MySQL).

**Decision:** MySQL — availability constraint wins given the shared-hosting requirement.

---

## Decision #5 — AES-256-GCM for content encryption

**Pros:** authenticated encryption (confidentiality + integrity in one primitive), native
WebCrypto support in-browser (no polyfill needed), native OpenSSL/libsodium support
server-side for the SWK-wrap step, widely vetted, fast enough for client-side chunked file
encryption.

**Cons:** nonce reuse is catastrophic (mitigated by per-item keys + fresh nonces per operation,
`06_Cryptography.md` §8), authenticated-encryption ciphertext is ~16 bytes larger per block
than unauthenticated modes (irrelevant at this scale).

**Alternatives considered:** ChaCha20-Poly1305 (comparable security properties, chosen against
in favor of AES-GCM for broader native WebCrypto/hardware-acceleration support across target
browsers).

**Decision:** AES-256-GCM.

---

## Decision #6 — Recovery Key model (vs. security questions, vs. no-recovery)

**Pros:** stronger than security questions (high entropy, not guessable/socially-engineerable),
gives users a real recovery path without requiring server-side plaintext-key escrow for
routine password-forgot cases (only the SWK path, Decision #1, is server-recoverable, and only
at verified-trigger time).

**Cons:** if lost alongside the password, the vault is genuinely unrecoverable — a real user
risk that must be disclosed clearly at creation time (`03_UI_UX_Guidelines.md` §7).

**Alternatives considered:** security questions (much weaker, socially engineerable — rejected
outright), server-held plaintext recovery (defeats the point of client-side encryption —
rejected), no recovery at all (too harsh a failure mode for a mainstream product — rejected).

**Decision:** Recovery Key, single-use-then-regenerated on actual recovery use
(`06_Cryptography.md` §11).

---

## Decision #7 — 100 MB per-user attachment limit (v1)

**Pros:** keeps shared-hosting disk usage predictable across a public multi-tenant user base
at v1 scale, keeps the product framed around "meaningful messages and key files," not
"personal cloud storage replacement," matching Core Principle #7 (minimal recurring cost) and
the non-goal stated in `00_Project_Vision.md` §2.

**Cons:** users with large video/audio legacy content may find it restrictive.

**Alternatives considered:** unlimited (unpredictable hosting cost/risk on shared hosting —
rejected for v1), tiered paid storage (listed as a `FUTURE` item in
`01_Product_Requirements.md` — deferred, not rejected).

**Decision:** 100 MB flat limit for v1, revisit as a paid tier post-v1.

---

## Decision #8 — Shared hosting (Hostinger) as the v1 deployment target

**Pros:** lowest recurring cost (Core Principle #7), matches the explicit product requirement
("self-hostable on Hostinger").

**Cons:** no Redis, no persistent workers, no WebSockets — forces the cron-driven queue/
scheduler pattern (`04_System_Architecture.md` §5, `09_Backend_Architecture.md` §5) which adds
design constraints (idempotent, short-running jobs) that wouldn't exist on a VPS.

**Alternatives considered:** VPS/Cloud hosting (would simplify background job design
considerably — noted as the documented upgrade path if shared hosting's limits are hit,
`14_Deployment.md` §10, not chosen for v1 given the explicit cost/hosting preference).

**Decision:** Shared hosting for v1, with an explicit, documented upgrade trigger and path
rather than an implicit assumption it'll "just move to a VPS eventually" without a plan.

---

## Decision #9 — Public multi-tenant product (not personal-only) as v1 scope

**Pros:** matches the stated goal of building a real product others can use, not just a
personal tool.

**Cons:** requires admin/support tooling, abuse handling, ToS/privacy/data-retention policy
work (`17_Roadmap.md` Phase 4) that a personal-only tool wouldn't need — larger v1 surface
area.

**Alternatives considered:** personal/family-only (smaller v1 scope, but would require
revisiting core architecture — especially multi-tenancy assumptions in
`07_Database_Design.md` and `04_System_Architecture.md` §7 — if opened to the public later;
building it in from the start avoids that rework).

**Decision:** Public product from v1, with row-level multi-tenancy baked into the schema and
architecture from the start.

---

## Decision #10 — Registration email-collision handling (enumeration trade-off)

**Context:** `08_API_Specification.md` deferred to Phase 1 sign-off whether registration
returns a specific `409 EMAIL_IN_USE` (enumeration-prone) or a generic "check your email"
response (enumeration-proof, but incompatible with issuing the session cookie a successful
registration must set, and it would strand the client-side-generated key material in limbo).

**Options considered:**
1. Direct `409 EMAIL_IN_USE`, blunted by the 3/hour/IP registration rate limit (chosen).
2. Generic response + defer the collision notice to the email channel.

**Pros of #1:** honest immediate UX; keeps the registration flow synchronous (Flow 1 depends
on the account existing before the Recovery Key acknowledgment step); rate limit makes bulk
enumeration impractical (3 probes/hour/IP).

**Cons of #1:** a targeted attacker can confirm one specific address has an account at a
trickle rate. Accepted: login and password-reset remain enumeration-safe, so this is the
single, rate-limited exception, matching common practice for registration endpoints.

**Additional rule:** a `pending` (Recovery-Key-unacknowledged) registration for the same
email is silently replaced by a new registration rather than treated as a collision — its
shown-once Recovery Key is gone, so the stale record is unusable by design
(`02_User_Flows.md` Flow 1 edge case).

**Decision:** Option 1, implemented in `RegisterUserAction` with the rate limits from
`05_Security_Architecture.md` §7.

---

## Decision #11 — Sealed metadata fields: one HKDF Metadata Key, AAD bound to user + field (not record ID)

**Context:** Phase 2a encrypts recipient names, relationship labels and the emergency
contact's name (`06_Cryptography.md` §15). `06_Cryptography.md` §4 shows a single Metadata
Key derived from the Master Key, and §9 asks for item ID + user ID + version in the AAD.
Recipient and emergency-contact IDs are assigned by the server *after* the create request,
so the client can't bind them at creation time.

**Options considered:**
1. AAD = user ID + field name + version (chosen).
2. Create, then immediately re-seal with the assigned ID via a second PATCH.
3. Client-generated UUIDs as record identifiers, bound into AAD.

**Pros of #1:** one round trip; a value still can't be moved to another user or another
field (name ↔ relationship) undetected; works identically for every metadata table.

**Cons of #1:** an attacker with database *write* access could swap the sealed names of
two recipients belonging to the same user without breaking the tag. Accepted: that attacker
can already re-point `email`/`phone` (plaintext by necessity), which is strictly worse —
`18_Threat_Model.md` Scenario 2 already accepts that write access can redirect delivery
this way, with audit logging + off-host backups as the mitigation. Vault *item content* (Phase 2b) is different: it's created under a
known recipient and gets the full item-ID binding §9 specifies.

**Cons of #2:** two writes per create, with a window where the record is sealed weaker
anyway. **Cons of #3:** a second identifier scheme across the schema for marginal benefit.

**Decision:** Option 1, with the format pinned by a cross-language test vector.

---

## Decision #12 — `POST /vault/unlock` is password-gated, not a plain authenticated GET

**Context:** the Master Key lives only in browser memory (`10_Frontend_Architecture.md` §3),
so a page reload leaves a valid session but a locked vault. The client needs the
password-wrapped Master Key again to unlock.

**Options considered:**
1. `POST /vault/unlock { password }` — server re-verifies the password before releasing the
   wrap (chosen).
2. `GET` the wrap with just the session cookie; the client derives the PDK locally.
3. Force a full logout/login on every reload.

**Pros of #1:** a stolen session cookie alone can't fetch the password-wrapped Master Key,
which would otherwise let an attacker run an offline Argon2id guessing attack against the
password. Failed attempts are audited (`vault.unlock_failed`) and rate-limited (`step-up`,
10/min/user).

**Cons of #1:** one server-side hash verify per unlock — negligible.

**Cons of #2:** hands offline-attack material to anyone holding the cookie. **Cons of #3:**
needless friction, and it would also destroy the trusted-device session record.

**Decision:** Option 1. (The recovery-wrapped copy is still served to a plain session by
`/vault/recovery-key/verify`; that's acceptable because the Recovery Key has 256 bits of
entropy and can't be guessed offline.)
