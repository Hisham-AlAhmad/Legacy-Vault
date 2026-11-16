# 19 — Decision Log

Format: Decision, Pros, Cons, Alternatives considered, Decision made. New entries append to
the end; never edit history away — if a decision is reversed later, add a new entry that
supersedes it and links back.

---

## Decision #1 — Key escrow model: Server Wrap Key ("zero-access"), not pure zero-knowledge ✅

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

## Decision #2 — Laravel (PHP 8.3+) for backend ✅

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

## Decision #3 — React + TypeScript for frontend ✅

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

## Decision #4 — MySQL for the database ✅

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

## Decision #5 — AES-256-GCM for content encryption ✅

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

## Decision #6 — Recovery Key model (vs. security questions, vs. no-recovery) ✅

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

## Decision #7 — 100 MB per-user attachment limit (v1) ✅

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

## Decision #8 — Shared hosting (Hostinger) as the v1 deployment target ✅

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

## Decision #9 — Public multi-tenant product (not personal-only) as v1 scope ✅

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

## Decision #10 — Registration email-collision handling (enumeration trade-off) ✅

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

## Decision #11 — Sealed metadata fields: one HKDF Metadata Key, AAD bound to user + field (not record ID) ✅

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

## Decision #12 — `POST /vault/unlock` is password-gated, not a plain authenticated GET ✅

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

---

## Decision #13 — Content keys are random and wrapped, under an HKDF-derived Content-Wrap Key ✅

**Context:** `06_Cryptography.md` §4 said content keys are "derived per-item (HKDF from
Master Key + a random per-item salt/ID)", while `07_Database_Design.md` has a
`content_key_wrapped` column, `08_API_Specification.md` sends `content_key_wrapped`, and
`13_Delivery_Engine.md` says "decrypt each item's content key (wrapped by Master Key)".
§10 additionally requires a *fresh* key on every edit. Phase 2b has to pick one model.

**Options considered:**
1. Random 256-bit content key per item version, wrapped (AES-256-GCM) by a
   **Content-Wrap Key** = HKDF-SHA256(Master Key, info `legacy-vault/v1/content-wrap-key`)
   (chosen).
2. Random content key wrapped directly by the Master Key (the literal reading of `07`).
3. Content key = HKDF(Master Key, random per-item salt), no wrapped key stored (the literal
   reading of `06` §4).

**Pros of #1:** matches three of the four documents; a fresh key per edit is just "generate
and wrap again"; a future *real* Master Key rotation only re-wraps 61-byte key blobs
instead of re-encrypting every item (#3 would force a full re-encrypt); the Master Key
stays what it is everywhere else in the system — HKDF input keying material only, never an
AES-GCM key itself, exactly like the Metadata Key (§15) — so no random-nonce budget is
ever spent under the Master Key.

**Cons of #1:** one more derived key to document, and one more ciphertext (the wrapped
key) per item than #3.

**Cons of #2:** uses the Master Key directly as a GCM key for an unbounded number of
random-nonce wraps over the account's lifetime. **Cons of #3:** contradicts `07`/`08`/`13`
and makes Master Key rotation proportional to total content size.

**Decision:** Option 1. `06` §4/§10/§13 and `07` now say the same thing. Attachment keys
(Phase 2c) are expected to follow the same model; `07` already describes them as wrapped.
No promised security property changes: per-item keys, a fresh key per edit, and
"server can unwrap only via the delivery trigger" all still hold.

---

## Decision #14 — Every item ciphertext is a self-describing envelope; the title uses the content key ✅

**Context:** `vault_items` had one `nonce` column but two ciphertexts (`title_enc`,
`content_enc`) plus the wrapped key. Encrypting twice under one key with one nonce breaks
AES-GCM completely (keystream reuse and authentication-key recovery). `06` §15 also lists
"item titles/subjects" as metadata without saying which key seals them.

**Options considered:**
1. One nonce column per ciphertext.
2. Each ciphertext carries its own nonce in the sealed-field envelope
   `version (0x01) ‖ nonce (12) ‖ ciphertext ‖ tag (16)` (chosen).

and, for the title: (a) the per-item content key (chosen), or (b) the user-wide Metadata
Key.

**Pros of #2:** the format is already implemented, validated (`SealedField`) and pinned by
a cross-language vector; a nonce can never be separated from, or shared between,
ciphertexts; no schema change is needed if another encrypted part is added later. The
`nonce` column is removed.

**Pros of (a):** an item is one self-contained unit — unwrap one key, open title and body
— which is what the delivery trigger wants; the title rotates with the body on every edit
(§10); the title gets the full item-ID binding of §9 rather than the weaker user+field
binding of Decision #11. Each content key therefore encrypts exactly two messages (title,
body) with independent random nonces and *different AAD labels* (`item.title`,
`item.content`), so the two can't be swapped for each other either.

**Cons of (a):** listing items costs one key unwrap per item (microseconds).
**Cons of (b):** thousands of titles accumulate under one long-lived key, and titles would
not rotate on edit.

**Decision:** #2 with (a). `aad_version` stays as a plaintext column (it must equal the
envelope version byte) so a future format migration can find old rows without parsing
blobs.

---

## Decision #15 — Vault items get a client-generated UUID, bound into AAD with user and recipient ✅

**Context:** Decision #11 promised that vault item content gets the full binding of `06`
§9 (item ID + user ID + version). The BIGINT primary key is assigned by the server after
the create request, so the client can't know it while encrypting.

**Options considered:**
1. Client-generated UUIDv4 (`crypto.randomUUID()`), stored in a `uuid` column and used as
   the item's public identifier (chosen).
2. Server-reserved ID: `POST /vault-items/reserve` returns an ID, then a second request
   uploads the ciphertext.
3. Create, then immediately re-encrypt with the assigned ID via a second PATCH.
4. Leave the item ID out of the AAD, as Decision #11 did for metadata.

**Pros of #1:** one round trip; no orphaned reservations to garbage-collect on a host with
no workers (`AI_RULES.md` #16); the binding exists from the first byte ever stored. The
BIGINT `id` stays as the internal primary key (FK target for `attachments`, table
conventions unchanged); it is simply never exposed.

**Why this differs from Decision #11**, which rejected client UUIDs for recipients as "a
second identifier scheme for marginal benefit": there the benefit *was* marginal, because
a database-write attacker could already redirect delivery through the plaintext
`email`/`phone`. Here the bound value is the content itself, the documents explicitly
require the binding, and #2/#3 are exactly the "two writes, with a window" shapes #11
rejected. #4 would weaken a promised property, so it was never a candidate.

**AAD** (UTF-8): `legacy-vault|v1|{part}|user:{user_id}|recipient:{recipient_id}|item:{uuid}`
with `part` ∈ `item.key`, `item.title`, `item.content`. Binding the **recipient ID** goes
beyond §9 and is deliberate: re-pointing a row's `recipient_id` (the one database edit
that would misdeliver a message — VAULT-5, `15_Testing.md` §4) makes every part of the
item fail its GCM check instead of decrypting for the wrong person.

**Cons of #1:** the server must treat the ID as untrusted input: canonical lowercase
UUIDv4 only, `UNIQUE(user_id, uuid)` (scoped per user so the constraint can't be used to
probe other accounts), `409 ITEM_ID_CONFLICT` on reuse — including reuse of a soft-deleted
item's ID. Moving an item to another recipient is not an in-place operation (it needs a
re-encrypt, i.e. create + delete).

**Known limit (unchanged by this decision):** AAD can't stop a database-write attacker
from rolling a whole row back to an *older version of the same item*. That's the
`18_Threat_Model.md` Scenario 2 class of attack; audit log + off-host backups remain the
mitigation.

**Decision:** Option 1.

---

## Decision #16 — Rich text: TipTap editor + DOMPurify sanitizer, with a fixed allow-list ✅

**Context:** `05_Security_Architecture.md` §9 requires a documented sanitizer with a strict
allow-list for vault rich text; neither a sanitizer nor an editor was installed. They must
work under the CSP (`style-src 'self'`, no inline script/style), need no CSS-in-JS
(`AI_RULES.md` #17), and handle RTL and mixed-direction text.

**Options considered (editor):**
1. TipTap 3 (`@tiptap/react`, `@tiptap/starter-kit`, built on ProseMirror) (chosen).
2. Lexical.
3. Quill.
4. A hand-rolled `contenteditable` + `document.execCommand` editor.

**Pros of #1:** headless — ships no styles of its own; the one runtime `<style>` injection
is switched off (`injectCSS: false`) and the few ProseMirror rules live in
`RichTextEditor.css`, so nothing violates the CSP; content is parsed through a schema, so
the editor can only ever hold node types we enabled (a second allow-list in front of the
sanitizer); correct keyboard behaviour and shortcuts out of the box. **Cons:** the largest
dependency in the frontend — it is loaded lazily, only on the editor route.

**Cons of #2:** comparable, but more packages and more glue for the same result.
**Cons of #3:** injects styles and leans on inline `style` attributes — CSP-hostile.
**Cons of #4:** `execCommand` is deprecated and inconsistent across browsers; writing a
correct editor is its own project.

**Sanitizer:** DOMPurify (suggested by `05` §9; widely audited, actively maintained —
`16_Coding_Standards.md` §5). A dedicated instance with this allow-list:

- **Tags:** `p br strong em u s h2 h3 ul ol li blockquote a`. Everything else is removed;
  for unknown *formatting* tags the text is kept, for `script`/`style`/`iframe`-class tags
  the content is dropped too (DOMPurify default).
- **Attributes:** `href` on `a`; `dir` on block elements; `rel` and `target` on `a` — but
  the last three are never taken from input: the sanitizer *sets* them. No `class`, `id`,
  `style`, `data-*`, `aria-*`, or event handlers survive.
- **Links:** `href` must start with `https:`, `http:` or `mailto:`. Anything else
  (`javascript:`, `data:`, `vbscript:`, relative and protocol-relative URLs) loses the
  `href`, and an `a` without an `href` is unwrapped to plain text. Every surviving link is
  forced to `rel="noopener noreferrer nofollow"` and `target="_blank"`: the recipient's
  page must never be navigated away or handed to the opened site as `window.opener`, and
  no referrer should reveal the delivery URL.
- **Direction:** every block element is forced to `dir="auto"`, so each paragraph takes
  its direction from its own first strong character — an English paragraph in an Arabic
  message (or the reverse) renders correctly wherever the HTML is later shown, including
  the Phase 3 recipient page.

**Where it runs:** on the editor's HTML immediately **before encrypting**, and on the
decrypted HTML immediately **after decrypting**, before anything else touches it. The
second pass is the one that matters at delivery: it doesn't trust that what was stored was
produced by this client. `dangerouslySetInnerHTML` stays banned in 2b (nothing renders
stored HTML outside the editor until the 2d preview, which must use the same function).

**Dev dependency:** `jsdom`, so the sanitizer tests run against a real DOM under Vitest.

**Decision:** TipTap + DOMPurify as above; allow-list pinned by tests.

---

## Decision #17 — Vault item endpoints: UUID-addressed, whole-item replacement, delete without step-up ✅

**Context:** `08_API_Specification.md` fully specified only `POST /recipients/{id}/items`,
and that spec predates Decisions #13–#15.

**Decisions:**
- **Addressing:** `/recipients/{id}/items` for list/create/reorder (recipient ID in the
  path), `/vault-items/{uuid}` for get/update/delete. Both resolve only inside the caller's
  own rows, so anything else is `404 NOT_FOUND` — the same rule and code as recipients
  (the earlier draft's `RECIPIENT_NOT_FOUND` is dropped for consistency with what 2a
  shipped).
- **List omits bodies.** `GET /recipients/{id}/items` returns titles and wrapped keys
  only; the body ciphertext is fetched per item. A list of a hundred 256 KiB bodies is not
  something to send on every page view.
- **PATCH replaces the whole item** — `title_enc`, `content_enc` and `content_key_wrapped`
  are all required together. There is no partial content update, because every edit
  rotates the content key (`06` §10) and a new key means everything is re-encrypted. The
  server can't verify rotation, but it rejects the one case it can see: a wrapped key
  byte-identical to the stored one (`422`).
- **Reorder is its own endpoint** (`POST /recipients/{id}/items/reorder`, the complete
  ordered list of that recipient's item IDs), so ordering never requires re-encryption.
- **Delete has no step-up password.** Step-up protects actions a stolen session couldn't
  otherwise do damage with (`05` §3); a session that can PATCH an item can already
  overwrite its content, so a password on DELETE would add friction and no protection. The
  UI still requires an explicit confirmation dialog. Recipient deletion keeps its step-up.
- **Caps:** title envelope ≤ 1,024 bytes (200 characters), body envelope ≤ 262,144 bytes
  (256 KiB) — over that is `413 PAYLOAD_TOO_LARGE` — and 100 live items per recipient
  (`403 ITEM_LIMIT_REACHED`). Text is not counted against the 100 MB attachment quota
  (`07` sums `attachments.size_bytes` only), so without these caps message storage would
  be unbounded on shared hosting.
- **`item_type`:** only `message` is accepted until Phase 2c adds `file_bundle`.

---

## Decision #18 — What "delete" means in Phase 2b (and what 2c must finish) ✅

**Context:** `07_Database_Design.md` §Deletion strategy promises a soft delete with a short
undo window, then a hard purge by a scheduled job. Both the undo window and the purge job
are Phase 2c scope.

**Phase 2b behaviour:**
- `DELETE /vault-items/{uuid}` sets `deleted_at`. The item disappears from every API
  response immediately and can never be delivered (the delivery trigger must read through
  the same soft-delete scope). Its ciphertext row stays in the database.
- Deleting a **recipient** soft-deletes that recipient's live items in the same
  transaction, with the *same* `deleted_at` timestamp, and records `item_count` in the
  `recipient.deleted` audit entry. Without this the items would be orphaned: unreachable
  through the API, but still present for a delivery query that forgot to join.
- A deleted item's UUID stays reserved (`409` on reuse).
- There is **no restore endpoint yet**, and nothing hard-deletes. What remains on disk is
  ciphertext only, so this is not a plaintext exposure — but it does mean "deleted" is not
  yet "erased", and the UI copy says "removed", not "permanently erased".

**Undo window settled (owner, 2026-11-15) ✅:** 30 days.

**Completed in Phase 2c (2026-11-16) ✅:** undo window, restore, erase-now and the hourly
purge shipped as Decisions #27–#29 (`docs/progress/phase-2c.md`); the UI now says
"permanently erased".

**Phase 2c must:** add the undo window and a restore action (restoring a recipient also
restores the items whose `deleted_at` matches the recipient's — that is why the timestamps
are identical); add the scheduled hard-purge command for recipients, items and attachment
files past the window; purge an item's attachments with it; and only then let the UI
promise permanent erasure.

---

## Decision #19 — Attachment quota is 10 MB (supersedes #7's 100 MB) ✅

**Context:** Decision #7 set a 100 MB per-user attachment limit. The product owner has
reduced it to 10 MB for v1.

**Pros:** keeps shared-hosting disk use smaller and more predictable per user, and keeps
the product about "meaningful messages and key files" rather than file storage.
Everything else in Decision #7's reasoning stands.

**Cons:** 10 MB fits documents, scans and photos, but not meaningful video or audio.
Users with larger legacy content are not served in v1.

**Future plans (owner's direction):** more storage may be offered later through a
**subscription or a one-time payment**. Neither is built in v1 and neither is specified
here — billing, plan tiers and a per-user limit are post-v1 work (`01_Product_Requirements.md`
§11). The only commitment now is that the limit must stay changeable:

- the quota is a single configuration value (`legacyvault.attachment_quota_bytes`,
  default 10 MiB = 10,485,760 bytes), added with the attachments in Phase 2c — never a
  literal at a call site, in the client or on the server;
- the server enforces it (`NFR-3`) and returns the limit and the current usage to the
  client, so the UI never hard-codes the number either;
- a later per-user or per-plan limit then replaces that one lookup, with no change to the
  enforcement code.

**Alternatives considered:** keep 100 MB (rejected by the owner); a per-user quota column
now (premature — there is no plan data to put in it).

**What changes:** `01` (VAULT-7, DASH-4, NFR-3, §11 and a note above the vault
requirements), `02` Flow 3 edge case, `05` §7, `08` attachment upload, `17` roadmap. Decision
#7 is left as written, as history. Decision #17's mention of "the 100 MB attachment quota"
likewise now means this 10 MB quota. No code changed: no quota code exists yet, and
text messages are bounded separately by Decision #17's caps.

**Detail settled (owner, 2026-11-15) ✅:** the 10 MB counts **ciphertext bytes** (what is
stored, slightly larger than the file). It is enforced server-side without decrypting
anything.

**Decision:** 10 MB, configurable, with paid plans explicitly left for later.

---

## Decision #20 — CSP allows `'wasm-unsafe-eval'` (WebAssembly only) so Argon2id can run ✅

**Context:** `05_Security_Architecture.md` §6 specified `script-src 'self'`. The browser
derives keys with Argon2id, implemented as WebAssembly (`hash-wasm`). Browsers treat
compiling WebAssembly as needing permission under CSP, so with `script-src 'self'` alone
registration, login and vault unlock cannot work. This was found during Phase 2b
verification: it had gone unnoticed because the header was only attached to Laravel's API
responses while the SPA's HTML came from Vite or a static host.

**Options considered:**
1. Add `'wasm-unsafe-eval'` to `script-src` (chosen).
2. Add `'unsafe-eval'`.
3. Replace the WebAssembly Argon2id with a pure-JavaScript implementation.
4. Leave the policy and don't send it with the SPA's HTML.

**Pros of #1:** a one-token change; the browser permits compiling WebAssembly and nothing
else — JavaScript `eval()` / `new Function()`, inline scripts and other origins remain
blocked; no change to the crypto.

**Cons of #1:** a small widening. Script that had somehow been injected could now
instantiate WebAssembly from bytes it controls. It still could not run in the first place,
since inline and third-party script remain blocked, so this is defence-in-depth shaved
rather than a new door. Accepted.

**Cons of #2:** allows arbitrary string-to-code execution — far broader than needed; it
would defeat much of the policy. **Cons of #3:** pure-JS Argon2id at 64 MiB × 3 passes is
many times slower and still needs a non-trivial dependency; it would be a crypto change to
satisfy a header. **Cons of #4:** drops the CSP defence-in-depth for the one page that
handles keys — contradicts §6.

**Also decided:** the header must be sent with the SPA's HTML (production:
`public_html/.htaccess`, `14_Deployment.md` §1a) and kept identical to the Laravel
middleware's; and libraries must not need `eval` — zod's startup `new Function()` probe
(reported as a violation even though zod recovers) is switched off with
`z.config({ jitless: true })`. A test pins the Laravel header.

**Decision:** Option 1, with the two follow-ups above. Supersedes the `script-src 'self'`
wording that `05` §6 carried until now.

---

## Decision #21 — Attachment keys: random per attachment, wrapped by the Content-Wrap Key, AAD bound to user, recipient, item and attachment ✅

**Context:** `06_Cryptography.md` §4 said only that attachment keys are "expected to follow
the content-key model". Phase 2c has to pin the key, its wrapping key and the AAD.

**Options considered (wrapping key):**
1. Wrap under the existing HKDF **Content-Wrap Key** (Decision #13), separated from item
   keys by the AAD label (chosen).
2. A second HKDF key, `legacy-vault/v1/attachment-wrap-key`.
3. Encrypt files directly under the item's content key.

**Pros of #1:** one wrapping key for the delivery trigger to derive; the AAD label
(`attachment.key` vs `item.key`) already makes a wrapped attachment key unusable as an item
key and the reverse, so a second HKDF label would add a key to manage and no separation
that isn't there already. **Cons of #1:** all wraps share one random-nonce budget — at 96
bits that is safe far beyond anything one account can create.

**Cons of #3:** every message edit rotates the content key (`06` §10), which would force
re-encrypting and re-uploading every file on every text edit.

**Decided:**
- **Attachment Key:** 256 random bits per attachment (`crypto.getRandomValues`), stored as
  `attachment_key_wrapped` — the usual envelope, exactly 61 bytes. It encrypts that
  attachment's filename, MIME type and chunks, and nothing else. It is never rotated:
  replacing a file means uploading a new attachment (new ID, new key) and deleting the old.
- **Attachment ID:** a client-generated UUIDv4, for the same reason as Decision #15 — the
  ID must exist before the first byte is encrypted. Same rules: canonical lowercase only,
  `UNIQUE(user_id, uuid)`, `409 ATTACHMENT_ID_CONFLICT` on reuse (a deleted attachment's ID
  stays reserved until it is purged). It doubles as the `X-Upload-Id`.
- **AAD** (UTF-8):
  `legacy-vault|v1|{part}|user:{user_id}|recipient:{recipient_id}|item:{item_uuid}|attachment:{attachment_uuid}`
  with `part` ∈ `attachment.key`, `attachment.name`, `attachment.mime`; chunks append
  `|chunk:{index}/{total}` to the `attachment.chunk` form (Decision #22). The recipient is
  bound for the same reason as for items: a file re-pointed at another recipient's item
  fails to decrypt instead of being delivered to the wrong person (VAULT-5).

No promised property is weakened; the recipient binding goes beyond `06` §9.

---

## Decision #22 — Chunk format: 1 MiB plaintext chunks, each an envelope, AAD carries index and total ✅

**Context:** `06` §12 asks for "streaming AES-256-GCM per chunk with chunk index bound into
AAD". Index alone does not detect a dropped *last* chunk (truncation).

**Decided:**
- **Chunk size:** 1,048,576 plaintext bytes (1 MiB). Every chunk except the last is exactly
  full; the last holds 1…1,048,576 bytes. An empty file is refused by the client.
- **Chunk ciphertext:** the standard envelope `0x01 ‖ nonce(12) ‖ ciphertext ‖ tag(16)`,
  i.e. plaintext + 29 bytes: a full chunk is exactly **1,048,605** bytes. A fresh random
  nonce per chunk (`06` §8). An attachment key therefore encrypts `chunk_count + 2`
  messages in its life.
- **Chunk AAD:**
  `legacy-vault|v1|attachment.chunk|user:…|recipient:…|item:…|attachment:…|chunk:{index}/{total}`
  (zero-based index). Binding the **total** as well as the index is what makes all of
  these fail authentication: reordering (wrong index), duplicating, dropping a middle
  chunk, dropping the last chunk or appending one (wrong total on *every* chunk), and
  moving a chunk between attachments. A "final-chunk flag" is implied: the final chunk is
  the one with `index = total − 1`. Index and total are written as canonical decimals (no
  sign, no leading zeros).
- **Decoder rule:** before decrypting, the client checks that the stream is exactly
  `size_bytes` long and that `size_bytes` is possible for `chunk_count`; a short stream or
  trailing bytes is an integrity error.
- **`chunk_count`** (`07`) is that total. The client decrypts with the `chunk_count` the
  server reports; if the server (or someone with database access) changes it, every chunk
  fails. `size_bytes` is the total ciphertext: `(chunk_count − 1) × 1,048,605 + last`.
- **Stored file:** the chunk envelopes concatenated in order, nothing else. Because all
  chunks but the last have one fixed size, chunk *i* starts at `i × 1,048,605` — no index
  table is needed.
- **Maximum chunk count:** `ceil(quota_bytes / 1,048,605)` — 10 with the 10 MiB quota. It
  is derived from the quota, not a second number to keep in sync.
- **Why 1 MiB:** small enough for shared-hosting request limits (PHP's default
  `post_max_size` is 8 MB) and for holding one chunk in memory on both sides; large enough
  that a full quota is ten requests. The size is a format constant (client
  `crypto/constants.ts`, server `Attachment::CHUNK_PLAINTEXT_BYTES`), pinned by the
  cross-language vector — changing it is a format change.

**Alternative considered:** a counter-derived nonce (prefix ‖ index), as in STREAM. Rejected
for v1: random nonces are what every other ciphertext here uses, the per-key message count
is tiny, and index/total in the AAD already gives the ordering guarantees.

---

## Decision #23 — Attachment files live on a private disk under server-generated names and are served only through an authenticated route ✅

**Decided:**
- **Where:** a dedicated Laravel disk `attachments`, root `storage/app/private/attachments`
  (overridable with `ATTACHMENTS_DISK_ROOT`). In production that is
  `/home/<user>/legacyvault-app/storage/…`, outside `public_html` (`14_Deployment.md` §1),
  so no URL maps to it. The disk has no `url` and is not served by Laravel's storage route.
- **Names:** `u{user_id}/{server-generated UUIDv4}.bin` — one fixed path per attachment
  row for its whole life. There is no temporary name and no rename on completion:
  `completed_at` alone says whether the file is finished, so a rename and a database
  update can never disagree and leave a file no row points to. Nothing from the client —
  not the filename, not even the client-chosen attachment ID — is ever part of a path
  (`05` §11). `storage_path` is never returned by the API.
- **Foreign keys restrict, they do not cascade:** `attachments.vault_item_id` and
  `attachments.user_id` are `ON DELETE RESTRICT`. A parent row cannot be deleted while an
  attachment row exists, so no database cascade can ever remove a row and leave its file
  behind; the purge code deletes files and attachment rows first, explicitly.
- **Download:** `GET /attachments/{uuid}/content`, behind the same auth, active-account and
  owner-scoped binding as everything else. It streams the ciphertext file with
  `Content-Type: application/octet-stream`, `Content-Disposition: attachment;
  filename="{uuid}.bin"`, `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`.
  The real filename is attached in the browser after decryption — the server cannot know it.
- **In the browser:** the decrypted file is handed to the user as a download and is never
  rendered in the app's origin. The Blob is always typed `application/octet-stream`
  whatever the stored MIME type says, so a decrypted `.html` or `.svg` cannot execute as
  the app.

---

## Decision #24 — Attachment metadata is sealed under the attachment key; what the server still sees ✅

**Decided:**
- `filename_enc`: envelope under the attachment key, AAD part `attachment.name`, plaintext
  ≤ 255 characters **and** ≤ 995 UTF-8 bytes, so the envelope is ≤ 1,024 bytes (the client
  shortens an over-long name on character boundaries, keeping the extension).
- `mime_type_enc`: envelope under the attachment key, AAD part `attachment.mime`. The MIME
  string is **right-padded with `0x00` to exactly 128 bytes** before sealing, so every
  `mime_type_enc` is exactly 157 bytes. Without padding the ciphertext length would give
  the type away (`application/pdf` and `image/jpeg` differ in length). An empty, over-long
  or malformed type is stored as `application/octet-stream`. On decrypt the field must be
  exactly 128 bytes — printable ASCII followed only by `0x00` to the end; anything else is
  an integrity error, not a type.
- Using the attachment key (not the user-wide Metadata Key) follows Decision #14: one key
  opens the whole attachment, and the metadata gets the full ID binding.

**What the server can still see** (stated plainly, `00` §4):
- that an attachment exists, which item and recipient it belongs to, and when it was
  uploaded, deleted or restored;
- its **exact ciphertext size and chunk count** — from which the plaintext size follows to
  the byte (`size − 29 × chunk_count`). `06` §14 used to list "size" among the encrypted
  metadata; that was never compatible with a server-side quota (`07` already marked
  `size_bytes` plaintext, and the owner settled quota-by-ciphertext-bytes in Decision
  #19). §14 is corrected. Size padding would hide it at the cost of quota; not in v1;
- the length of the filename, to the byte (not padded — a known, minor leak, the same as
  item titles).

It cannot see the filename, the MIME type or any content.

**Known limit:** nothing authenticates *which* attachments an item has. Someone with
database write access can remove an attachment row (or hide it by soft-deleting it) and
the item still decrypts — the file is silently missing from delivery. Same class as the
rollback limit in Decision #15 (`18_Threat_Model.md` Scenario 2: audit log and off-host
backups are the mitigation).

**`05` §14 corrected:** "MIME validated server-side by content inspection" is impossible on
ciphertext. The server validates the *container* (envelope version byte, exact chunk
sizes); the type is whatever the client sealed, and is treated as untrusted at delivery.

---

## Decision #25 — Upload protocol: declare, then send chunks in order; resume by asking; abort by deleting ✅

**Context:** `08` specified one endpoint with `X-Chunk-Index`, `X-Chunk-Total` and
`X-Upload-Id`, but nowhere to send the sealed filename, MIME type and wrapped key, and no
way to check the quota before bytes arrive.

**Decided:**
1. **Declare** — `POST /vault-items/{uuid}/attachments` (JSON):
   `{ id, filename_enc, mime_type_enc, attachment_key_wrapped, size_bytes, chunk_count,
   aad_version }`. The server validates the envelopes, checks that `size_bytes` is possible
   for `chunk_count`, and **reserves `size_bytes` against the quota** in the same
   transaction that creates the row (the user's row is locked, so two uploads cannot both
   squeeze under the limit). Over quota → `413 QUOTA_EXCEEDED`, before one content byte is
   sent. Response `201` with the attachment (`status: "uploading"`) and the storage numbers.
2. **Send chunks** — `POST /attachments/{uuid}/chunks`, `Content-Type:
   application/octet-stream`, raw envelope bytes, headers `X-Chunk-Index`, `X-Chunk-Total`,
   `X-Upload-Id`. Strictly in order: the index must equal the number of chunks already
   stored, the total must equal the declared `chunk_count`, the upload ID must equal the
   attachment ID, the body must be exactly the size that chunk must have (so the bytes
   accepted can never exceed what was reserved) and must start with the version byte.
   Wrong order, a repeated chunk, or a chunk after completion → `409 CHUNK_SEQUENCE_ERROR`;
   wrong size or malformed → `422`. Response `200 { received_chunk }`; the last chunk's
   response also carries the completed attachment and the storage numbers.
   **Concurrency:** the attachment row is locked (`SELECT … FOR UPDATE`) for the whole
   request, and `received_chunks` is advanced in that same transaction. The chunk is
   written at its exact offset (`index × 1,048,605`) with `fopen`/`fseek`/`fwrite` under a
   file lock — never with an append helper — after cutting off anything beyond that
   offset, so a chunk whose transaction never committed is overwritten, not duplicated.
   A file shorter than the offset is `409`. The upload is marked complete only if the
   file is then exactly `size_bytes` long.
3. **Resume** — `GET /attachments/{uuid}` returns `received_chunks`. After a network
   failure the client asks, then continues from that chunk (re-encrypting it — any valid
   encryption of chunk *i* is acceptable). Resuming is possible while the page still holds
   the file; after a reload the upload is simply abandoned.
4. **Abort** — `DELETE /attachments/{uuid}` on an unfinished upload hard-deletes the row
   and the partial file at once and releases the reservation (there is nothing worth
   undoing). It takes the same row lock as a chunk request, so a chunk still in flight
   can't write to a file whose row is gone. **Audit:** `attachment.upload_aborted`.
5. **Abandoned uploads** — an unfinished upload not touched for **24 hours**
   (`legacyvault.attachments.abandoned_upload_ttl_hours`) is removed by the purge command
   (Decision #29). Until then it holds its reservation.
6. **Limits:** 20 declared uploads per hour per user (`05` §7 — limiter `uploads`); 20
   live attachments per item (`403 ATTACHMENT_LIMIT_REACHED`); chunk requests fall under
   the general 120/min tier.

**Why two endpoints instead of the one in `08`:** putting three base64 envelopes in
request headers of the first chunk is the only way to keep one endpoint, and then the
quota check happens while the first megabyte is already being received.

---

## Decision #26 — Quota accounting: every stored byte counts until it is actually gone ✅

**Context:** Decision #19 fixed 10 MiB of ciphertext, one config value, server-enforced,
reported to the client.

**Decided:** a user's usage is the sum of `attachments.size_bytes` over **every row that
still exists**: completed attachments, uploads in progress (their declared size — the
reservation of Decision #25), and **soft-deleted attachments until they are purged**
(they still occupy the disk, whether deleted on their own or with their item or
recipient). `attachments` carries `user_id` directly so this is one indexed sum, not a
join through `vault_items` as `07` first sketched.

- `GET /vault/storage` returns `{ limit_bytes, used_bytes, trashed_bytes }`; the same
  object rides along on the attachment list, declare and final-chunk responses.
  `trashed_bytes` is the part of `used_bytes` held by deleted files, so the UI can say why
  space is still taken.
- The limit is read from `legacyvault.attachment_quota_bytes` in exactly one place
  (`StorageQuota`), which is where a per-plan limit would later go.

**Consequence, and its fix:** without more, a user at the limit who deletes a file could
not upload its replacement for 30 days. So the recently-deleted list offers **"erase now"**
(Decision #28), which frees the space immediately.

---

## Decision #27 — `file_bundle` items, and attachments on any item ✅

**Decided:**
- An attachment belongs to exactly one vault item, of **either type**. A `message` is a
  title, a rich-text body and optionally files; a `file_bundle` is a title and files, with
  **no body** (`content_enc` is `NULL`, and sending one is a `422`).
- A bundle is created and renamed through the same item endpoints: create with
  `item_type: "file_bundle"`, `title_enc`, `content_key_wrapped`; `PATCH` with a new
  `title_enc` under a rotated key. The content key of a bundle encrypts exactly one
  message, the title.
- The item type cannot change after creation.
- **Deleting an item** soft-deletes its attachments in the same transaction with the
  **same `deleted_at`**, exactly as a recipient does to its items; **restoring an item**
  restores the attachments whose `deleted_at` equals the item's. An attachment deleted on
  its own earlier has a different timestamp and stays deleted. Unfinished uploads on a
  deleted item are discarded outright.
- An attachment is reachable only while its item and that item's recipient are live
  (route binding and policy both check the chain).
- An empty bundle is allowed (files are added after it exists); at delivery it is simply a
  titled entry with nothing attached.

---

## Decision #28 — Undo: a 30-day "recently deleted" list with restore and erase-now ✅

**Context:** Decision #18 left the undo window (30 days, owner), the restore action and
the purge to Phase 2c.

**Decided:**
- **Window:** `legacyvault.trash.undo_window_days` = 30. A deleted recipient, item or
  attachment can be restored while `deleted_at > now − 30 days`; once the window has
  passed it is no longer restorable (`404`) even if the purge job has not run yet, so the
  boundary does not depend on cron timing.
- **`GET /trash`** lists what can still be restored, each entry with `deleted_at` and
  `purge_at`: deleted recipients; deleted items whose recipient is live; deleted
  attachments whose item is live. Children that went down with their parent are not
  listed separately — they come back with the parent.
- **Restore:** `POST /trash/recipients/{id}/restore`, `/trash/items/{uuid}/restore`,
  `/trash/attachments/{uuid}/restore`.
  - A recipient comes back with the items whose `deleted_at` matches its own, and those
    items with their matching attachments. Items the user had deleted individually
    *before* deleting the recipient stay in the trash, now listed on their own.
  - An item or attachment can only be restored into a live parent (otherwise it is not
    listed and the call is `404`); the per-recipient item cap and per-item attachment cap
    still apply (`403`).
  - A restored recipient loses its `is_emergency_contact` flag if a different recipient
    carries it now.
  - No step-up password: restoring only reverses the user's own deletion, and a session
    that can restore could equally re-create. **Audit:** `recipient.restored`,
    `vault_item.restored`, `attachment.restored`.
- **Erase now:** `DELETE /trash/recipients/{id}`, `/trash/items/{uuid}`,
  `/trash/attachments/{uuid}` hard-delete immediately — rows and files, the same code path
  as the scheduled purge. This is what frees quota without waiting 30 days (Decision #26).
  Irreversible, so it **requires the step-up password** (`{ "password" }`, limiter
  `step-up`). Decision #17's argument for skipping step-up on item delete — "a session
  that can PATCH can already overwrite" — does not hold here: attachments can't be
  overwritten, so without erase-now a stolen session could only delete them *reversibly*.
  Erase-now must not hand it a permanent version of that.
  **Audit:** `recipient.purged` / `vault_item.purged` / `attachment.purged` with
  `reason: "user_requested"`.
- **Races:** restore and erase-now lock the row and re-check that it is still deleted and
  still inside the window before acting; the scheduled purge locks each row and re-checks
  that it is still past the window (Decision #29). A restore and a purge of the same row
  therefore can't both win.
- **Known limit:** `deleted_at` has one-second precision. An item deleted on its own in the
  very same second as its recipient is indistinguishable from one deleted with it, and
  comes back when the recipient is restored. Harmless (it errs towards keeping data), and
  not reachable through the UI, where deleting a recipient needs a password.
- **UI copy:** now that purge exists, deletion dialogs say the item can be restored for 30
  days and is then **permanently erased**; the number comes from the server.

**What "permanently erased" means, honestly:** the rows and files are gone from the live
database and disk. Off-host backups (`05` §21) still contain the *ciphertext* until they
age out of their own retention window. That is stated in `07` §Deletion strategy.

---

## Decision #29 — Purge: an hourly, time-boxed Artisan command ✅

**Decided:** `php artisan vault:purge-deleted`, scheduled **hourly** from
`routes/console.php` (`withoutOverlapping`), in this order:

1. unfinished uploads untouched for 24 hours (Decision #25) — file and row;
2. attachments with `deleted_at ≤ now − 30 days` whose item is not itself due for purge
   (those go with their item in step 3) — file, then row;
3. items past the window — every attachment file of the item, the attachment rows, the item;
4. recipients past the window — everything under them as in (3), then the recipient.

- **Short runs** (`AI_RULES.md` #16): rows are processed in batches of 100
  (`legacyvault.trash.purge_batch_size`) and the run stops after 40 seconds
  (`legacyvault.trash.purge_max_seconds`); whatever is left is picked up an hour later.
  No queue, no worker.
- **File first, row second:** if a file cannot be deleted the row is kept and retried next
  run, so a row never disappears while its bytes stay behind uncounted. A file already
  missing counts as deleted. A parent is kept while any file under it failed to delete.
- **Lock and re-check:** each row is purged in its own transaction: lock it, confirm it
  is still deleted and still past the window (a restore may have won in the meantime),
  delete files, delete rows.
- **Audit** (`actor_type: system`, the owner's `user_id`, IDs and counts only):
  `attachment.upload_expired`, `attachment.purged`, `vault_item.purged`
  (`attachment_count`), `recipient.purged` (`item_count`, `attachment_count`), each with
  `reason: "window_expired"`. Children purged with their parent are counted in the
  parent's entry rather than logged one by one.
- **Why hourly rather than daily:** the query is one indexed range scan per table, so it
  costs nothing when there is nothing to do; it keeps "30 days" meaning 30 days and about
  an hour, and stops abandoned uploads from holding quota for up to two days.

With #28 and #29 shipped, Decision #18 is complete.
