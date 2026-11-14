# Phase 2b — Vault items (messages): session log

Date: 2026-11-14 · Scope: `docs/17_Roadmap.md` sub-phase 2b only (VAULT-5, VAULT-6
text/links, VAULT-9). Attachments, quota, undo/purge (2c), preview (2d), dashboard (2e)
and export/import (2f) were not started.

## 1. Outcome

A user can write, edit, reorder and delete rich-text messages for each recipient. Every
message is encrypted in the browser under its own random key, the key is replaced on every
edit, and the ciphertext is bound to the user, the recipient and the item ID. The server
stores and returns ciphertext and never decrypts it.

| Check | Result |
|---|---|
| Backend tests | **61 passed**, 486 assertions (42 before 2b + 14 feature + 5 vector) |
| Frontend tests | **70 passed** (23 before 2b + 18 item crypto + 29 sanitizer) |
| `npm run build` (tsc + vite) | clean |
| `npm run lint` | 0 errors; 3 warnings, all pre-existing in files 2b didn't write (`vaultKey.tsx`, `routes/index.tsx`) |
| Browser click-through, en + ar, reload → lock → unlock | passed (§8) |
| Raw `SELECT * FROM vault_items` | no readable title or body (§7) |
| Recipient A's item in recipient B's context | fails to decrypt (§7) |

Not done / not clean — see §10: Laravel Pint is not clean (the codebase never was), the
MySQL migration was checked by generated DDL only, and one pre-existing CSP problem was
found that needs a decision.

## 2. Step 0 — git

The project was put under git before any 2b work: root `.gitignore`, `git init -b main`,
the existing code committed as ten feature commits plus one fix, tagged `phase-2a`, pushed
to `https://github.com/Hisham-AlAhmad/Legacy-Vault`.

- `git ls-files | grep -iE "\.env$|sqlite|\.key$"` prints nothing; the real
  `SERVER_WRAP_KEY`, `APP_KEY` and `DB_PASSWORD` values were also searched for across every
  tracked file and appear in none.
- One mistake, caught before the first push: the ignore rule `auth.json` (meant for
  Composer credentials) also hid `frontend/src/i18n/{en,ar}/auth.json`. The rule is now
  `backend/auth.json` and the two files are in a follow-up commit (`3365a89`, "Track auth
  locale files hidden by an over-broad ignore rule"), so the "auth" commit on its own is
  missing those two files.
- Because the code was committed at its final 2a state, files shared by several features
  (`routes/api.php`, the locale files, `AppServiceProvider`) sit in the earliest commit
  that needs them. The early commits are a reviewable history, not individually buildable
  checkpoints.

## 3. Decisions (all in `docs/19_Decision_Log.md`)

| # | Gap | Decision | Why (short) |
|---|---|---|---|
| 13 | Content key derived or wrapped? | Random key per item version, wrapped by an HKDF-derived **Content-Wrap Key** | Agrees with `07`/`08`/`13`; Master Key rotation only re-wraps 61-byte blobs; the Master Key stays HKDF-input-only |
| 14 | One `nonce` column, two ciphertexts | Every ciphertext is an envelope `0x01 ‖ nonce ‖ ct ‖ tag`; `nonce` column removed; **title uses the content key** | A nonce can't be shared or separated; title rotates with the body and gets item-ID binding |
| 15 | AAD needs an item ID the server assigns | **Client-generated UUIDv4**; AAD = `legacy-vault|v1|{part}|user:{id}|recipient:{id}|item:{uuid}` | One round trip, binding from the first write; recipient ID bound too, so a re-pointed row fails instead of being misdelivered |
| 16 | No sanitizer or editor | **DOMPurify** + **TipTap 3**, fixed allow-list | Works under the CSP with `injectCSS: false`, no CSS-in-JS, per-block `dir="auto"` |
| 17 | Endpoints unspecified | UUID-addressed; list omits bodies; PATCH replaces the whole item; separate reorder; **delete without step-up**; size and count caps | An edit always rotates the key; a session that can PATCH can already destroy content |
| 18 | Delete semantics | Soft delete only; recipient delete cascades to items with the same timestamp; no restore/purge yet | Undo window and purge are 2c; identical timestamps let 2c restore them together |

None of these weakens a property the docs promise, so none was escalated. Two go slightly
beyond the docs: the recipient ID in the AAD (#15) and the per-recipient item cap (#17).

Smaller choices not worth a numbered entry:

- `item_type` is a `VARCHAR`, not a MySQL `ENUM`, and only `message` is accepted (same
  SQLite-compatibility reasoning as `recipients.preferred_methods`).
- `content_enc` is `binary()` in the migration and widened to `LONGBLOB` by a
  driver-conditional `ALTER TABLE` on MySQL/MariaDB, because Laravel maps a length-less
  `binary()` to a 64 KiB `BLOB`.
- The client builds the AAD from the recipient whose page is open, not from the
  `recipient_id` the server returns — otherwise the binding would only protect against a
  server that lies consistently.
- The decrypted message body is cached with `gcTime: 0`; it leaves memory-held query
  cache when the editor closes.
- `API_PROXY_TARGET` env var for the Vite dev proxy (port 8000 was occupied on this
  machine).

## 4. What was built

### Backend (`backend/`)

New:

| File | Purpose |
|---|---|
| `database/migrations/2026_11_14_100000_create_vault_items_table.php` | The table (§6) |
| `app/Models/VaultItem.php` | Model, soft deletes, constants (UUID pattern, wrapped-key size, types) |
| `app/Policies/VaultItemPolicy.php` | Owner **and** the item's recipient is one of the owner's live recipients |
| `app/DTOs/Vault/VaultItemCiphertext.php`, `NewVaultItemData.php`, `VaultItemDto.php` | Input and output DTOs (full and summary shapes) |
| `app/Http/Requests/StoreVaultItemRequest.php`, `UpdateVaultItemRequest.php`, `ReorderVaultItemsRequest.php` | Validation |
| `app/Http/Requests/Concerns/ValidatesItemCiphertext.php` | Shared envelope rules + the 413 size guard |
| `app/Actions/Vault/{List,Create,Get,Update,Delete,Reorder}VaultItem(s)Action.php` | One class per use case |
| `app/Http/Controllers/Api/V1/VaultItemController.php` | Thin controller |
| `tests/Feature/VaultItemTest.php` | 14 tests |
| `tests/Unit/VaultItemVectorTest.php` | 5 tests, cross-language vector |

Modified:

| File | Change |
|---|---|
| `app/Models/User.php`, `Recipient.php` | `vaultItems()` relations |
| `app/Actions/Vault/DeleteRecipientAction.php` | Soft-deletes the recipient's items in the same transaction; `item_count` in the audit entry |
| `app/Policies/RecipientPolicy.php` | `view` ability (item list) |
| `app/Providers/AppServiceProvider.php` | `{vaultItem}` route binding, owner-scoped, by UUID |
| `app/Rules/SealedField.php` | `SealedField::exactly($bytes)` for fixed-size envelopes |
| `app/Services/AuditLogger.php` | Allow-list: `item_id`, `item_type`, `item_count` |
| `config/legacyvault.php` | `vault_items` caps |
| `lang/{en,ar}/legacyvault.php` | Six new strings each |
| `routes/api.php` | Six routes under `auth:sanctum` + `account.active` |
| `tests/Support/FakeClientVault.php` | PHP mirror of the item crypto, for real-crypto tests |

### Frontend (`frontend/`)

New:

| File | Purpose |
|---|---|
| `src/crypto/items.ts` | `createItem`, `rotateItem`, `decryptItem`, `decryptItemTitle`, `deriveContentWrapKey`, `itemAad`, `ItemIntegrityError`, `ItemTooLargeError` |
| `src/crypto/items.test.ts` | 18 tests |
| `src/features/vault/sanitizeHtml.ts` (+ `.test.ts`, 29 tests) | The allow-list sanitizer |
| `src/features/vault/RichTextEditor.tsx` (+ `.css`) | TipTap editor, toolbar, link dialog |
| `src/features/vault/itemsApi.ts` | Sanitize → encrypt → API, and API → decrypt → sanitize |
| `src/features/vault/itemSchema.ts` | zod schema (title/body present, title length) |
| `src/features/vault/vaultQueries.ts` | Shared query keys and hooks |
| `src/features/vault/RecipientItemsPage.tsx` (+ `.css`) | Per-recipient list: write, edit, move, delete |
| `src/features/vault/ItemEditorPage.tsx` (+ `.css`) | Editor page (lazy route chunk) |
| `src/features/vault/WritingToBanner.tsx` (+ `.css`) | The sticky "You are writing to" banner |
| `src/features/vault/DeleteItemDialog.tsx` | Confirmation with the title restated |
| `src/components/ButtonLink.tsx` | A router link styled as a button |

Modified: `src/crypto/constants.ts` (Content-Wrap Key label, item limits),
`src/lib/api/types.ts` (item record types), `src/routes/index.tsx` (three routes),
`src/features/vault/RecipientCard.tsx` ("Messages" link), `RecipientsPage.tsx` (uses the
shared `requireMasterKey`), `src/components/Button.css`, `src/i18n/{en,ar}/vault.json`
(`editor.*` and `items.*`, 68 new keys each), `vite.config.ts`, `package.json`,
`package-lock.json`.

### Docs

`05` §9 and §17 (sanitizer, audit events), `06` §4, §8, §9, §10, §13, §15, `07`
(vault_items, deletion strategy), `08` (index + five endpoint specs), `10` §6 and new §7a,
`13` §2 step 3, `15` §2, `17` (2b Done, 2c Next, open items), `19` (Decisions #13–#18),
`README.md`, this file, `.claude/handoff/handoff.md`.

## 5. API

All under `/api/v1`, Sanctum session, CSRF header, active account required
(`403 ACCOUNT_NOT_ACTIVE` otherwise). Foreign, deleted or malformed IDs are always
`404 NOT_FOUND`.

| Method & path | Purpose | Audit |
|---|---|---|
| GET `/recipients/{id}/items` | List (no bodies) | — |
| POST `/recipients/{id}/items` | Create | `vault_item.created` |
| POST `/recipients/{id}/items/reorder` | Reorder | `vault_item.reordered` |
| GET `/vault-items/{uuid}` | One item with body | — |
| PATCH `/vault-items/{uuid}` | Replace key + title + body | `vault_item.updated` |
| DELETE `/vault-items/{uuid}` | Soft delete | `vault_item.deleted` |

Create — request:

```json
POST /api/v1/recipients/1/items
{
  "id": "bccaeff2-df3a-4c41-83e2-7536b5daa533",
  "item_type": "message",
  "title_enc": "AQ6tm2mb1nivpK8A8OrIDog9LW62HJ6w…",
  "content_enc": "Ad1jmK3AAFyA2ztFIcRZK4dClT8RBEyZ…",
  "content_key_wrapped": "AZx1bYiPHojbdTf7GZxbo5fcCvrMCLra…",
  "aad_version": 1
}
```

Response `201`:

```json
{
  "item": {
    "id": "bccaeff2-df3a-4c41-83e2-7536b5daa533",
    "recipient_id": 1,
    "item_type": "message",
    "title_enc": "AQ6tm2mb…",
    "content_key_wrapped": "AZx1bYiP…",
    "aad_version": 1,
    "position": 1,
    "created_at": "2026-11-14T14:50:32+00:00",
    "updated_at": "2026-11-14T14:50:32+00:00",
    "content_enc": "Ad1jmK3A…"
  }
}
```

List — `GET /api/v1/recipients/1/items` → `{ "items": [ … ] }`, same objects without
`content_enc`, ordered by `position`.

Update — `PATCH /api/v1/vault-items/{uuid}` with
`{ "title_enc", "content_enc", "content_key_wrapped", "aad_version": 1 }` (all required) →
`200 { "item": … }`. Sending the stored `content_key_wrapped` again:

```json
{ "error": { "code": "VALIDATION_FAILED", "message": "Some fields need attention.",
  "fields": { "content_key_wrapped": ["An edited item must be encrypted with a new key."] } } }
```

Reorder — `POST /api/v1/recipients/1/items/reorder` with `{ "order": ["uuid-b", "uuid-a"] }`
(the complete list) → `200 { "items": [ … ] }` with `position` rewritten 1…n.

Delete — `DELETE /api/v1/vault-items/{uuid}` (no body) → `200 { "ok": true }`.

Other errors: `409 ITEM_ID_CONFLICT`, `413 PAYLOAD_TOO_LARGE`, `403 ITEM_LIMIT_REACHED`,
`422 VALIDATION_FAILED`. Full spec: `docs/08_API_Specification.md`.

## 6. Database

Migration `2026_11_14_100000_create_vault_items_table`:

```
vault_items
  id                   BIGINT UNSIGNED PK        internal only
  uuid                 CHAR(36)                  client-generated UUIDv4, public ID, bound into AAD
  user_id              FK → users (cascade)
  recipient_id         FK → recipients (cascade)
  item_type            VARCHAR(20)               'message' (file_bundle in 2c)
  title_enc            VARBINARY(1024)           envelope
  content_enc          LONGBLOB NULL             envelope (BLOB on SQLite)
  content_key_wrapped  VARBINARY(61)             envelope
  aad_version          TINYINT UNSIGNED          1
  position             SMALLINT UNSIGNED DEFAULT 0
  created_at, updated_at, deleted_at
  UNIQUE (user_id, uuid)
  INDEX  (recipient_id, position)
```

Compared with the old `docs/07`: `uuid` added, `nonce` removed, `title_enc` and
`content_key_wrapped` resized, `item_type` a string, indexes changed. No existing table
was altered.

## 7. Exit check evidence

A user was registered through the browser and created, edited and deleted messages for two
recipients (Layla Haddad, id 1; عمر خليل, id 2). Then the SQLite file was read directly
with PDO, bypassing the application. `SELECT * FROM vault_items ORDER BY id` (binary
columns shown as length, first 24 bytes in hex, and first 48 bytes as ASCII with
non-printables as `.`):

```
--- id=1 uuid=bccaeff2-df3a-4c41-83e2-7536b5daa533 user_id=1 recipient_id=1 item_type=message aad_version=1 position=2
    created_at=2026-11-14 14:50:32 updated_at=2026-11-14 14:50:34 deleted_at=NULL
    title_enc              49 bytes  hex=010ead9b699bd678afa4af00f0eac80e883d2d6eb61c9eb0…  ascii=....i..x.........=-n....m.5+I...QA..lhw..D.@y:.A
    content_key_wrapped    61 bytes  hex=019c756d888f1e88db7537fb199c5ba397dc0afacc08bada…  ascii=..um.....u7...[.........5Z....t.0.../m..u...\.i.
    content_enc           335 bytes  hex=01dd6398adc0005c80db3b4521c4592b8742953f11044c99…  ascii=..c....\..;E!.Y+.B.?..L.X.'I.j"4.Ij....a....K..\
--- id=2 uuid=2fa9e5e4-18c2-44ab-9977-b7dc9767dae1 user_id=1 recipient_id=1 item_type=message aad_version=1 position=1
    created_at=2026-11-14 14:50:35 updated_at=2026-11-14 14:50:37 deleted_at=2026-11-14 14:50:37
    title_enc              40 bytes  hex=019f6a37ff61dbacb1e651b465fddf14dc4ec49469ed06e3…  ascii=..j7.a....Q.e....N..i...Biy...-.Q.~.s...
    content_key_wrapped    61 bytes  hex=019048501beb52824ce28afef8257e32c762ff949ee19ec8…  ascii=..HP..R.L....%~2.b.........F<(......[...........
    content_enc            61 bytes  hex=01f4879c0859ea7aaea05575155db7a5219cdf964cc97c4f…  ascii=.....Y.z..Uu.]..!...L.|O..t....y:..`B3..$....3Fz
--- id=3 uuid=d886b954-5969-4870-b295-2c485f7dcb77 user_id=1 recipient_id=2 item_type=message aad_version=1 position=1
    created_at=2026-11-14 14:50:39 updated_at=2026-11-14 14:50:46 deleted_at=NULL
    title_enc              57 bytes  hex=01fa3b0a207590be81c23711ed4e914780f5fad07843503b…  ascii=..;. u....7..N.G....xCP;u...H.....LY...+<.v.Fm..
    content_key_wrapped    61 bytes  hex=01d7bdbc76409da7401295c770160bec8ba851e34a7e2b9a…  ascii=....v@..@...p.....Q.J~+......r.VTf<.]...~..D.R..
    content_enc           110 bytes  hex=01f578a621bfd9d68e63a2c658dbad96694fd53f50ddeeb4…  ascii=..x.!....c..X...iO.?P....d..bs..s.S.9..t...P.J)h
--- id=4 uuid=a8a46dc7-4c04-4adf-8067-642929df4559 user_id=1 recipient_id=2 item_type=message aad_version=1 position=2
    created_at=2026-11-14 14:50:43 updated_at=2026-11-14 14:50:44 deleted_at=2026-11-14 14:50:44
    title_enc              41 bytes  hex=01dea35f86743be1dd254d4a66643fcd7c30bd5b52c52fd0…  ascii=..._.t;..%MJfd?.|0.[R./.......)|]....8..F
    content_key_wrapped    61 bytes  hex=01a4b215fe1f3b3f7d8fe4e5832dff5a5101cc302e01d5ed…  ascii=......;?}....-.ZQ..0......W.s..W..x..=uf.....R..
    content_enc            56 bytes  hex=017bd416b15e30275931264167b10be8ba721f733c49dba3…  ascii=.{...^0'Y1&Ag....r.s<I...;.'H.............>.@..`
```

The whole table (all four rows, every column, full length) was then searched for the
plaintext that had been typed. None of it is present:

```
"Layla" no · "dearest" no · "Read this first" no · "notary" no · "example.com" no
"href" no · "<p" no · "Second note" no · "Omar scratch" no · "edited" no
"only for you" no · "رسالتي" no · "إلى عمر" no · "أخي العزيز" no · "معدّل" no
```

Every blob begins with the version byte `01`; rows 2 and 4 are the soft-deleted messages
(still ciphertext, `deleted_at` set). Row 1 was edited once and row 3 twice.

The matching audit rows carry IDs only, for example
`vault_item.updated {"item_id":"bccaeff2-…","recipient_id":1}`.

**Recipient A's items cannot be decrypted in B's context**, shown three ways:

1. *Live, in the browser:* row 1 (Layla's) was re-pointed with
   `UPDATE vault_items SET recipient_id = 2 WHERE uuid = 'bccaeff2-…'`. Omar's page then
   listed two entries: his own message and **"Unreadable message"** with the integrity
   warning and no Edit button. Layla's title was not shown. The row was put back afterwards.
2. *Backend test* `test_one_recipients_item_cannot_be_used_or_decrypted_in_anothers_context`:
   the same ciphertext opens for the right recipient and returns failure for another
   recipient, another user, another item ID, and after the same database re-pointing.
3. *Frontend tests* "rejects the wrong item, the wrong recipient and the wrong user" and
   "rejects one recipient's item replayed under another recipient": `ItemIntegrityError`
   is thrown.

## 8. Running-app verification

Driven with Playwright against `php -S` (scratch SQLite) and `npm run dev`. All of these
were asserted, not just clicked:

- Register → Recovery Key acknowledgement → two recipients.
- New message: empty submit shows both validation errors; bold via Ctrl+B; a link entered
  as `javascript:alert(1)` is refused, `example.com/notary` becomes
  `https://example.com/notary`; an English paragraph resolves LTR and an Arabic-first
  paragraph RTL in the same message.
- The banner reads "You are writing to: Layla Haddad" and is still at the top of the
  viewport after scrolling to the bottom of the page.
- Toolbar: arrow keys move between buttons, focus ring visible; in Arabic the arrow
  direction is mirrored.
- Edit, second message, unsaved-changes dialog, move up, delete with the title restated.
- Second recipient with an Arabic name: create, edit, create + delete.
- Switch to Arabic: `dir="rtl"`, Arabic banner, edit and save; an English name inside the
  Arabic banner sentence renders correctly.
- Reload on the editor: "Unlock your vault" appears, no editor or banner is rendered, a
  wrong password is rejected, the right one restores the decrypted title, bold text and link.

The click-through found three bugs, all fixed before commit: the link dialog nested a
`<form>` inside the message form; saving an edit evicted the item's cache entry while its
form was mounted, so the page never navigated back; and list notices were stored as
translated text, so they stayed in the old language after a switch.

**Under the CSP:** the production build was served with the exact header from
`SecurityHeaders.php`. With the header as documented, login does not work at all — see
§10.1; that is not a 2b regression. With `'wasm-unsafe-eval'` added for the test, the
editor was used and a message saved with **zero `<style>` elements in the document, zero
`style` attributes inside the editor, and no violation from any 2b code**. One violation
remains, `script-src blocked eval`, raised from the main bundle before the editor chunk
loads; it comes from a dependency that was already in the app, and nothing visibly breaks.

## 9. Dependencies added

| Package | Version | Kind | Why |
|---|---|---|---|
| `@tiptap/react` | 3.34.0 | runtime | Editor (Decision #16) |
| `@tiptap/starter-kit` | 3.34.0 | runtime | The node/mark set, including link and underline |
| `@tiptap/pm` | 3.34.0 | runtime | ProseMirror peer dependency |
| `dompurify` | 3.4.17 | runtime | Sanitizer (`05` §9) |
| `jsdom` | 29.1.1 | dev | Real DOM for the sanitizer tests. 31.x needs Node ≥ 22.22; this machine has 22.20 |

No backend dependency was added. The `package.json` diff is exactly these five lines.

## 10. Open questions, limitations, deferred work

### Needs your decision

1. **The documented CSP blocks Argon2 (pre-existing, Phase 1).** `script-src 'self'`
   forbids WebAssembly compilation, and the client derives keys with Argon2id in
   WebAssembly (`hash-wasm`). Served under that header, the SPA cannot register, log in or
   unlock. It has not shown up because the header is only attached to Laravel's API
   responses, and the SPA's HTML is served by Vite or the static host. The usual fix is
   `script-src 'self' 'wasm-unsafe-eval'`, which permits WebAssembly but not JavaScript
   `eval`. I did not change it: it is a security-policy change outside 2b. Recorded in
   `17_Roadmap.md`.
2. **`npm audit`: 9 advisories (3 moderate, 6 high), all in dependencies that predate 2b**
   — vite, react-router, zod, vitest, postcss, nanoid, source-map-js. `npm audit fix` is
   available. Left alone so this phase's dependency diff stays reviewable.
3. **Your local MySQL database is two phases behind.** `php artisan migrate --pretend`
   shows the 2a migrations (recipients, emergency contacts) and the new vault_items
   migration all pending on the `legacy_vault` database. I did not migrate it. Run
   `php artisan migrate` when you want to use the app against MySQL.
4. **`.claude/fable-prompt-phase-2b.md` is committed** (in the first commit). Say so if you
   would rather it were not in the repository.

### Known limitations

- **MySQL was not executed.** The `legacy_vault` user can't create a scratch database, so
  the migration was verified on MySQL 8 only by its generated DDL (`--pretend`), which
  includes the `LONGBLOB` widening. It runs for real on SQLite, in tests and in the live run.
- **Laravel Pint is not clean** on the new files (empty-body braces, `!` spacing,
  docblock alignment) — nor on the existing ones. The new code follows the style the
  codebase already had. Running Pint would reformat much of Phases 1–2a.
- **Rollback is not prevented by AAD.** Someone with database write access can restore an
  older version of the *same* item. Same class as `18_Threat_Model.md` Scenario 2.
- **Integrity failures are shown in the UI but not yet written to the audit log** — that
  is 2d scope (`17_Roadmap.md`), as for sealed fields.
- **The interface language resets to English on reload.** Pre-existing; the locale isn't
  persisted client-side.
- **No automated UI tests.** The Playwright script lived in a scratch folder and is not in
  the repository; `15_Testing.md` lists Playwright E2E as a layer that doesn't exist yet.
- **Main bundle is about 1 MB** (415 kB gzipped) before 2b's editor chunk; most of it
  predates 2b. The editor adds a separate 412 kB lazy chunk.

### Deferred to 2c (`19_Decision_Log.md` Decision #18)

Undo window and restore for items and recipients; the scheduled hard-purge command;
`file_bundle` items and attachments; attachment keys (expected to follow Decision #13);
UI copy that can promise permanent erasure.

### Deferred to 2d

Preview — it must render only `sanitizeHtml()` output; reporting client-side integrity
failures to the audit log.

## 11. Commits

Step 0 (tag `phase-2a`): ten feature commits and one fix, `15da34d` … `3365a89`.

Phase 2b:

| Commit | Subject |
|---|---|
| `4852d86` | docs: resolve Phase 2b spec gaps (Decisions #13-#18) |
| `527e59a` | feat: add vault_items table and VaultItem model |
| `f1e59f2` | feat: vault items API (create, list, get, update, reorder, delete) |
| `dc4a827` | feat: client-side vault item crypto with key rotation and AAD binding |
| `79ecffe` | feat: rich-text sanitizer and editor for vault messages |
| `869b21f` | feat: messages UI - per-recipient list and editor with writing-to banner |
| (this commit) | docs: Phase 2b session log, handoff, roadmap/README status — tagged `phase-2b` |
