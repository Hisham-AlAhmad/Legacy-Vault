# Task: implement Phase 2b — Vault items (messages) — in Legacy Vault

You are continuing work on **Legacy Vault** (`C:\Users\hisla\Downloads\Repos\Legacy Vault`):
a privacy-first digital legacy platform. It is a dead man's switch around a client-side
encrypted vault. Backend is Laravel 12 (`backend/`), frontend is React 19 + TypeScript + Vite
(`frontend/`). The `docs/` folder is the source of truth.

## Where the project stands

- **Phase 1 (Foundation): done.** Auth, the full client-side key hierarchy (Master Key,
  PDK/RDK wraps, Recovery Key), Server Wrap Key, TOTP 2FA, sessions/devices, password reset,
  Recovery-Key vault recovery, rate limits/CSP/headers, audit log, en/ar i18n with RTL.
- **Phase 2a (Recipients & emergency contact): done.** Recipients CRUD with names sealed
  under an HKDF Metadata Key (`docs/06_Cryptography.md` §15), step-up delete, emergency
  contact, password-gated vault unlock (`VaultUnlockGate`, `src/lib/vaultKey.tsx`).
- **Phase 2b: not started.** No `vault_items` migration, model, API or UI exists yet.
- The README claims 42 backend tests and 23 frontend tests pass. Run them first to confirm
  this baseline before you change anything.

## Your scope: Phase 2b only

From `docs/17_Roadmap.md` §Phase 2 sub-phases:

> Per-item content keys, encrypted title + rich-text body (sanitized), CRUD under a
> recipient, editor with persistent "You are writing to" banner (`03_UI_UX_Guidelines.md`
> §7), content-key rotation on edit (`06_Cryptography.md` §10) (VAULT-5, VAULT-6
> text/links, VAULT-9).

Out of scope: attachments, quota and the hard-purge job (2c); preview (2d); dashboard (2e);
export/import (2f). Do not start 2c. `AI_RULES.md` #15 applies at sub-phase level.

## Before writing any code

0. Do **Step 0 (put the project under git)** further down this prompt first.
1. Read `AI_RULES.md` in full. Its 17 rules are non-negotiable: no plaintext secrets, no
   tokens in web storage, DTOs + Form Requests at every boundary, real crypto in tests, en/ar
   parity, accessible + RTL-correct UI, CSS in separate files, no long-running processes
   (the host is shared hosting), security events go to the audit log.
2. Read in this order: `docs/00`, `01` (VAULT-5/6/9), `06_Cryptography.md` (§4, §8–10, §13,
   §15–16), `07_Database_Design.md` (vault_items + Deletion strategy), `08_API_Specification.md`
   (`/recipients/{id}/items`), `05_Security_Architecture.md` (sanitizer + CSP), `03` §6–7,
   `09`, `10`, `16_Coding_Standards.md`, `19_Decision_Log.md`.
3. Study how 2a was built and copy its patterns. Backend: `app/Actions/Vault/*`,
   `app/DTOs/Vault/*`, `app/Rules/SealedField.php`, `RecipientPolicy`, `EnsureAccountActive`,
   `AuditLogger`, and the tests in `tests/Feature/RecipientTest.php` and
   `tests/Unit/SealedFieldVectorTest.php`. Frontend: `src/crypto/*`,
   `src/features/vault/*`, `src/lib/vaultKey.tsx`.

## Resolve these spec gaps first, then proceed

The docs contradict each other or say nothing on the points below. For each one, propose
the fix, record it as a new numbered entry at the end of `docs/19_Decision_Log.md` (same
format as #11 and #12), update the affected docs, and only then implement it. If a choice
would weaken a security property the docs promise, stop and ask me rather than choosing.

1. **Content key: derived or random-and-wrapped?** `06` §4 says content keys are "derived
   per-item (HKDF from Master Key + a random per-item salt/ID)". `07` has a
   `content_key_wrapped` column ("wrapped by Master Key"). §10 also requires a *fresh* key
   on every edit. Choose one model and make `06` and `07` agree.
2. **Nonce reuse.** `vault_items` has a single `nonce` column, but `title_enc` and
   `content_enc` are two separate ciphertexts. Two encryptions under one key with one nonce
   is catastrophic for GCM. Fix this, for example with a nonce per ciphertext or a
   self-describing blob format like the sealed-field format. Also decide whether the title
   uses the content key or the Metadata Key.
3. **AAD needs the item ID, but the server assigns it.** Decision #11 says vault item content
   must be bound to item ID + user ID + version (`06` §9). Unlike recipients, items cannot
   skip the ID. Decide how the client knows the ID at encryption time, for example a
   client-generated UUID or a server-reserved ID. Explain the choice against the options
   Decision #11 rejected.
4. **Sanitizer and editor.** `05` requires a documented sanitizer with a strict allow-list
   (DOMPurify is suggested). Neither a sanitizer nor a rich-text editor is installed. Pick
   libraries that work under the existing CSP, need no CSS-in-JS (rule #17) and support RTL
   text. Then define the allow-list (VAULT-6 needs text + links: decide `rel`/`target` on
   links and which URL schemes are allowed). Sanitize both before encrypting and after
   decrypting. Check the `package.json` diff after installing.
5. **Missing endpoint specs.** `08` fully specifies only `POST /recipients/{id}/items`.
   Specify the list/get/update/delete endpoints (and reorder if you implement `position`)
   in `08` before building them, including error codes and the active-account and step-up
   requirements.
6. **Delete semantics.** `deleted_at` exists, but the undo window and hard purge belong to
   2c. Define what 2b's delete does and record what 2c must finish.

## Requirements for the implementation

- **Backend:** migration matching the updated `07` (works on SQLite dev and MySQL prod);
  model, policy (an item belongs to the user *and* to that recipient), Actions, DTOs, Form
  Requests with sealed-blob validation, controllers under `api/v1` behind auth +
  `EnsureAccountActive`. Audit-log create/update/delete without any content. Never decrypt
  on the server (rule #13). When a recipient is deleted, define and test what happens to
  that recipient's items.
- **Frontend crypto** (`src/crypto`): typed functions to create, encrypt, decrypt and rotate
  item content with the AAD binding. A failed GCM tag check is a hard error and is never
  silently ignored.
- **UI:** a per-recipient items list plus the editor, reached from the recipient card. The
  "You are writing to: [Name]" banner stays visible while editing. Every string goes in
  both en and ar. Make the editor keyboard-accessible with visible focus and correct RTL
  for mixed-direction text (reuse `bidi.ts`). Lock the editor behind `VaultUnlockGate`.
  After any mutation, invalidate the React Query cache.
- **Tests (real crypto, no mocks):**
  - Backend feature tests: CRUD; cross-user and cross-recipient access denied; inactive
    account blocked; validation; audit entries.
  - A cross-language test vector for the item ciphertext format, like
    `SealedFieldVectorTest`.
  - Frontend Vitest:
    - encrypt/decrypt round trip
    - tamper and wrong-AAD rejection (wrong item, wrong user)
    - a fresh key and fresh nonces on every edit
    - sanitizer strips scripts, event handlers and `javascript:` URLs
    - en/ar locale parity still passes
- **Exit check for 2b:** a user creates, edits and deletes messages for two recipients.
  Show (paste the rows) that a raw `SELECT * FROM vault_items` contains no readable title
  or body, and that recipient A's items cannot be decrypted in B's context.

## Verify before you report done

- `cd backend && php -d extension=sodium -d extension=zip vendor/phpunit/phpunit/phpunit`.
  Everything passes, old and new.
- `cd frontend && npx vitest run`, `npm run build` (tsc + vite) and `npm run lint`, all
  clean.
- Run the app (`php artisan serve` + `npm run dev`) and click through the whole flow in
  both English and Arabic, including after a page reload, which must lock the vault and
  require unlock.
- Report actual results. If something fails or you skip a step, say so plainly.

## Step 0: put the project under git (do this before any 2b work)

This folder is not a git repository yet. Set it up first, so the 2b changes start from a
clean baseline.

**GitHub remote URL:** `https://github.com/Hisham-AlAhmad/Legacy-Vault`

1. Create a root `.gitignore`. It must exclude at least:
   - `backend/.env` and every other `.env*` except `.env.example`
   - `*.sqlite`, `vendor/`, `node_modules/`, `dist/`
   - `backend/storage/logs/*`, `backend/storage/framework/views/*`, `backend/bootstrap/cache/*.php`
   - `.phpunit.result.cache`
   - `.claude/settings.local.json`
   Keep the existing `backend/.gitignore` and `frontend/.gitignore`.
2. `git init -b main`, then stage everything and check `git status` before committing.
   **Check that no secrets are staged.** `backend/.env` holds `SERVER_WRAP_KEY` and
   `APP_KEY` (`AI_RULES.md` #7). Also run
   `git ls-files | grep -iE "\.env$|sqlite|\.key$"` and confirm it prints nothing. If
   anything secret is staged, unstage it and fix the ignore rules before committing.
3. **Commit the existing code feature by feature, never as one commit.** Stage files with
   explicit paths (`git add <paths>`, never `git add .` or `git add -A`) and make one commit
   per feature. A sensible order:
   1. repo setup: root `.gitignore`, `README.md`, `AI_RULES.md`
   2. `docs/` engineering handbook
   3. backend scaffold: Laravel skeleton, config, base migrations
   4. frontend scaffold: Vite/React/TS setup, design tokens, base components, i18n setup
   5. crypto core: `frontend/src/crypto/*`, `backend/app/Services/Crypto/*`, their tests
   6. auth, registration and recovery key, backend + frontend + tests
   7. 2FA, sessions/devices, login history
   8. password reset and Recovery-Key vault recovery
   9. security baseline: headers, CSP, rate limits, audit logging, locale middleware
   10. Phase 2a: recipients and emergency contact, vault unlock, active-account gate
   Each commit keeps backend and frontend files for that feature together, along with
   their tests and locale strings. Before each commit, run `git diff --cached --stat` to
   check it holds only that feature. If a file is shared by several features, put it in
   the earliest commit that needs it. When everything is committed, `git status` must be
   clean (apart from ignored files). Tag the last commit `phase-2a`.
4. Connect the remote. If the URL above is filled in, run `git remote add origin <url>`
   and push the commits with `git push -u origin main --tags`. If the URL is still the
   placeholder, ask me for it, keep committing locally in the meantime, and push once I
   reply. If the push fails on authentication, stop and tell me the exact error. Don't
   try to work around credentials.
5. **During 2b, keep committing per feature and push after each one.** One commit per
   logical unit, for example:
   - decision log entries and doc fixes
   - migration and model
   - API: actions, DTOs, requests, controllers, plus their tests
   - crypto module and its tests
   - sanitizer and editor
   - items UI and i18n
   - final docs
   Stage explicit paths only. Write each message as a short imperative subject that names
   the feature, plus a body saying what and why. Run the relevant tests before each
   commit, and never commit a broken build. Push (`git push`) after each commit so GitHub
   shows progress feature by feature. Never force-push, amend pushed commits, or rewrite
   history.

## When you finish: document everything

Documentation is part of the deliverable. 2b is not done until all of these are written:

1. **Update the living docs in the same change** (`AI_RULES.md` #9):
   - `06`, `07`, `08`, `05` (sanitizer), and `10` wherever the implementation adds to or
     differs from what they say.
   - New Decision Log entries for every gap resolved above, plus any other non-obvious
     choice.
   - `docs/17_Roadmap.md`: mark 2b **Done** and 2c **Next**.
   - `README.md`: update the status line, the feature summary and the test counts.
2. **Write a session log** at `docs/progress/phase-2b.md`:
   - what was built, file by file (new and modified), grouped backend / frontend / docs
   - every decision made and why, linking to the Decision Log
   - new API endpoints with example request and response
   - DB schema changes and the migration name
   - dependencies added, with versions
   - test results with counts, and the raw-DB exit-check evidence
   - anything deferred to 2c or later, known limitations, and open questions for me
3. **Write a handoff** at `.claude/handoff/handoff.md` so a fresh session with no memory can
   resume. Include the goal, current state, exact next step (start of 2c), commands to run,
   gotchas you hit, and anything half-finished.
4. Commit the documentation as its own commit (`docs: Phase 2b session log, handoff,
   roadmap/README status`). Tag the final state `phase-2b` and push
   (`git push origin main --tags`).
5. End your final message with a short summary that points to those two files and lists
   the commits you made.
