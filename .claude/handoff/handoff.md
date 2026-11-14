# Handoff: Legacy Vault — start of Phase 2c (Attachments & quota)
Last updated: 2026-11-14

## 1. Goal
- Building: Legacy Vault, a dead man's switch around a client-side encrypted vault. Laravel 12
  (`backend/`), React 19 + TS + Vite (`frontend/`). `docs/` is the source of truth; `AI_RULES.md`
  (17 rules) is non-negotiable. One sub-phase at a time (`docs/17_Roadmap.md`).
- "Done" means (for the next session): sub-phase **2c** — chunked client-side-encrypted
  attachment upload, encrypted filename/MIME, 100 MB server-side quota, download + decrypt,
  attachment delete, **and** the soft-delete undo window + restore + scheduled hard-purge job
  for recipients/items/files. Code + tests + docs, committed per feature and pushed.

## 2. Current state
- Works: Phase 1, 2a and **2b are done**, tagged `phase-2a` / `phase-2b`, pushed to
  `origin` (`https://github.com/Hisham-AlAhmad/Legacy-Vault`, branch `main`). Backend 61 tests,
  frontend 70 tests, `npm run build` clean, lint 0 errors (3 old warnings). Full browser
  click-through passed in en + ar including reload → lock → unlock.
- Broken: nothing in 2b. Pre-existing: the documented CSP (`script-src 'self'`) blocks the
  Argon2 WebAssembly — login fails if the SPA HTML is ever served with it. **Awaiting the
  owner's decision; do not change the CSP unasked.**
- Stopped at: 2b complete, working tree clean. 2c not started — no `attachments` migration,
  model, API or UI exists.

## 3. Files in play
- `docs/progress/phase-2b.md`: full 2b session log (files, API examples, evidence, open questions).
- `docs/19_Decision_Log.md`: Decisions #13–#18 define the item crypto and delete model; **#18
  lists exactly what 2c must finish**; #13 says attachment keys should follow the content-key model.
- `docs/06_Cryptography.md` §12, §14; `docs/07_Database_Design.md` §attachments + Deletion
  strategy; `docs/08_API_Specification.md` (`POST /vault-items/{id}/attachments`); `docs/05` §11, §14.
- `frontend/src/crypto/items.ts`: item crypto to copy patterns from (envelopes, AAD, thrown
  integrity errors). `frontend/src/crypto/index.ts`: `encryptFileStream` already exists, unused.
- `backend/app/Actions/Vault/*VaultItem*`, `app/Http/Requests/Concerns/ValidatesItemCiphertext.php`,
  `app/Policies/VaultItemPolicy.php`, `tests/Feature/VaultItemTest.php`: the 2b backend patterns.
- `backend/tests/Support/FakeClientVault.php`: PHP mirror of client crypto for real-crypto tests.
- `backend/app/Actions/Vault/DeleteRecipientAction.php`: cascades soft delete to items with the
  **same `deleted_at`** — restore must rely on that.

## 4. Changes made
- Repo put under git, existing code committed per feature, pushed: clean baseline.
- 2b: `vault_items` table, items API (list/create/get/replace/reorder/soft-delete), item crypto
  with per-version keys and AAD (user + recipient + item UUID), DOMPurify sanitizer, TipTap
  editor, messages UI, en/ar strings, docs: see the session log.
- `vault_items.item_type` accepts only `message`; `file_bundle` is reserved for 2c.

## 5. Failed attempts (DO NOT RETRY)
- Tried: shell heredocs containing apostrophes (Git Bash tool) / Failed because: the tool
  mis-parses them / Error: `unexpected EOF while looking for matching '` — write scripts to a
  file and run them instead.
- Tried: creating a scratch MySQL database as the app's DB user / Failed because: no privilege /
  Error: `1044 Access denied for user 'legacy_vault'@'localhost'`. Use `artisan migrate --pretend`
  to inspect MySQL DDL; never migrate the owner's real DB for tests.
- Tried: `jsdom@31` / Failed because: needs Node ≥ 22.22, machine has 22.20. Stay on `^29`.
- Tried: nesting a `<form>` inside the message form (link dialog) / invalid HTML, submit never
  reached the handler. Dialog content inside a form must not be a form.
- Tried: `queryClient.removeQueries` on a query whose component is still mounted / it unmounts
  the form mid-save. Use `gcTime: 0` instead.

## 6. Next steps
1. Read `AI_RULES.md`, then `docs/17_Roadmap.md` (2c row + "Open items carried out of 2b"),
   `docs/19_Decision_Log.md` #13 and #18, `docs/06` §12/§14, `docs/07` §attachments, `docs/08`
   attachments spec, `docs/05` §11/§14, and `docs/progress/phase-2b.md` §10.
2. Confirm the baseline:
   `cd backend && /c/xampp/php/php.exe -d extension=sodium -d extension=zip vendor/phpunit/phpunit/phpunit`
   (expect 61) and `cd frontend && npx vitest run` (expect 70), `npm run build`, `npm run lint`.
3. Ask the owner about the open questions in `docs/progress/phase-2b.md` §10 (CSP + WebAssembly,
   `npm audit` advisories, un-migrated local MySQL) if they are still unanswered.
4. Resolve 2c's spec gaps in the Decision Log first (attachment key model and AAD, chunk
   format, where files live on shared hosting, quota accounting, undo-window length, purge
   schedule), update the docs, then implement — same order as 2b.
5. Run the app for manual checks. Port 8000 was occupied on this machine, so:
   backend `DB_CONNECTION=sqlite DB_DATABASE=<scratch.sqlite> php -d extension=sodium -S 127.0.0.1:8031 -t public public/index.php`
   (after `artisan migrate --force` with the same env), frontend
   `API_PROXY_TARGET=http://127.0.0.1:8031 npm run dev`. Stop both when finished.
6. Commit per feature with explicit paths, push after each, tag `phase-2c` at the end.
