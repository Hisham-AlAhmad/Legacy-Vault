# CLAUDE.md — Legacy Vault

Dead man's switch around a client-side encrypted vault. Laravel 12 (`backend/`), React 19 + TypeScript + Vite (`frontend/`), deployed to Hostinger shared hosting.

## Read first
1. `AI_RULES.md` — 17 non-negotiable rules. Follow them exactly.
2. `docs/` is the source of truth. Reading order is in `AI_RULES.md`; roadmap in `docs/17_Roadmap.md`; coding standards in `docs/16_Coding_Standards.md`.
3. `docs/19_Decision_Log.md` — numbered decisions. If a spec gap needs a choice, add a new numbered entry and update docs BEFORE writing code. Ask the owner if a choice would weaken a promised security property.
4. `.claude/handoff/handoff.md` — session state (git-ignored). Use the handoff skills to read/write it.

## Workflow
- One roadmap sub-phase at a time; a big phase may be split into sub-phases.
- Code, tests and docs change together (AI_RULES #8, #9). Arabic and English strings stay in sync (#10).
- Commit per feature with explicit paths (never `git add -A`), push after each commit, tag each finished phase (`phase-2c`, ...), then write `docs/progress/phase-X.md` and a new handoff.
- No plaintext secrets, no server-side decryption, no `localStorage` tokens, CSS in its own files (see AI_RULES).
- Shared hosting: no long-running processes; scheduled work is cron + short jobs (#16).

## Commands (Windows, Git Bash)
- Backend tests (use XAMPP PHP; `composer` is not on PATH):
  `cd backend && /c/xampp/php/php.exe -d extension=sodium -d extension=zip vendor/phpunit/phpunit/phpunit`
- Frontend: `cd frontend && npx vitest run`, `npm run build`, `npm run lint`, `npx tsc --noEmit`.
- Expected baseline at the 2c tag: backend 92 tests, frontend 92 tests, build clean, lint 0 errors (3 old warnings).
- The dev database is the WSL Ubuntu MySQL that `backend/.env` reaches on 127.0.0.1:3306 (Windows' port is `wslrelay.exe`). Apply new migrations there (`artisan migrate --pretend` first); PHPUnit always uses in-memory SQLite.
- Registration is limited to 3/hour/IP; repeated browser runs need `artisan cache:clear` in between.

## Local environment gotchas
- `backend/.env` points at a local MySQL (`legacy_vault`); that user cannot create databases. Do not migrate the owner's DB for tests. Override with real env vars, which beat `.env`: `DB_CONNECTION=sqlite DB_DATABASE=<scratch file>`. Inspect MySQL DDL with `artisan migrate --pretend`.
- Ports 8000 and 8010 are held by foreign processes: do not kill them. Run the API on 8031: `php -d extension=sodium -S 127.0.0.1:8031 -t public public/index.php`; frontend: `API_PROXY_TARGET=http://127.0.0.1:8031 npm run dev`. Stop servers you start.
- Bash tool: heredocs containing apostrophes fail. Write scripts to a file and run them.
- Stop `vite preview` / shells inside `dist/` before `npm run build` (EBUSY).
- Node is 22.20: keep `jsdom` on `^29`. Use `npx -y npm@latest audit fix` (installed npm 11.1.0 crashes on it).
- Playwright is not in the project: install in the scratchpad and launch Chromium from `%LOCALAPPDATA%\ms-playwright\chromium-1248\chrome-win64\chrome.exe`.

## Gotchas in the code
- `frontend/src/lib/zodConfig.ts` (zod `jitless`) must stay the FIRST import in `main.tsx`.
- The CSP must be identical in `backend/app/Http/Middleware/SecurityHeaders.php` and `docs/14_Deployment.md` §1a.
- Soft-deleting a recipient cascades to its items with the same `deleted_at`; restore relies on that.
- Item crypto patterns live in `frontend/src/crypto/items.ts` and `backend/tests/Support/FakeClientVault.php` (PHP mirror of client crypto).
- Do not nest `<form>` elements or evict a mounted query's cache in `onSuccess`; both broke saving before.
