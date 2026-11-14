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

---

## Decision #13 — Content keys are random and wrapped, under an HKDF-derived Content-Wrap Key

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

## Decision #14 — Every item ciphertext is a self-describing envelope; the title uses the content key

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

## Decision #15 — Vault items get a client-generated UUID, bound into AAD with user and recipient

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

## Decision #16 — Rich text: TipTap editor + DOMPurify sanitizer, with a fixed allow-list

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

## Decision #17 — Vault item endpoints: UUID-addressed, whole-item replacement, delete without step-up

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

## Decision #18 — What "delete" means in Phase 2b (and what 2c must finish)

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

**Phase 2c must:** add the undo window and a restore action (restoring a recipient also
restores the items whose `deleted_at` matches the recipient's — that is why the timestamps
are identical); add the scheduled hard-purge command for recipients, items and attachment
files past the window; purge an item's attachments with it; and only then let the UI
promise permanent erasure.
