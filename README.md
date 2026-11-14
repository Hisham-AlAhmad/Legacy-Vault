# Legacy Vault

A privacy-first digital legacy platform: a dead man's switch wrapped around a client-side
encrypted vault, with automatic per-recipient delivery. **Read [`AI_RULES.md`](AI_RULES.md)
first, then [`docs/`](docs/README.md) — the docs folder is the source of truth.**

Zero-**access**, not zero-knowledge: during normal operation the server holds only
ciphertext and wrapped keys; one audited code path (the delivery trigger) can unwrap — see
`docs/00_Project_Vision.md` §4.

## Repository layout

| Path | What it is |
|---|---|
| `docs/` | Engineering handbook (architecture, security, crypto, phases) |
| `backend/` | Laravel 12 API (`app/Actions`, `app/Services/Crypto`, `app/DTOs`, …) |
| `frontend/` | React 19 + TypeScript + Vite SPA (`src/crypto` is the security-critical core) |

## Status: Phase 1 done · Phase 2 (Core) in progress — sub-phases 2a and 2b done, 2c next

Registration/login with the full client-side key hierarchy (Master Key, PDK/RDK wraps,
Recovery Key), the Server Wrap Key mechanism end-to-end, TOTP 2FA + backup codes, trusted
devices/sessions, login history, password reset (account access only — vault stays locked,
by design) and Recovery-Key vault recovery, lockout/rate limits/CSP/security headers,
audit logging, en/ar i18n with RTL, dark design system.

Phase 1 exit criterion (`docs/17_Roadmap.md`) is covered by
`backend/tests/Feature/FullKeyLifecycleTest.php`: full registration → login →
password-change → reset → recovery round trip with real Argon2id + AES-256-GCM, no mocks.

Phase 2 is split into sub-phases 2a–2f (`docs/17_Roadmap.md` §Phase 2 sub-phases).
**2a — Recipients & emergency contact** is done: recipients CRUD with names/relationships
sealed client-side under an HKDF-derived Metadata Key (`docs/06_Cryptography.md` §15),
step-up-protected deletion, emergency contact set/replace/remove (optionally linked to a
recipient), password-gated vault unlock after a page reload, and an active-account gate on
all vault endpoints.

**2b — Vault items (messages)** is done: each recipient has their own messages (title +
rich-text body with links), written in an editor that keeps a "You are writing to: [Name]"
banner pinned on screen. Every message is encrypted in the browser under its own random
content key, which is replaced on every edit; key, title and body are separate ciphertexts
bound to the user, the recipient and the item ID, so a message moved to another recipient
in the database fails to decrypt instead of being misdelivered
(`docs/06_Cryptography.md` §9–10, §13). Rich text passes a strict allow-list sanitizer
before encryption and again after decryption (`docs/05_Security_Architecture.md` §9).
Messages can be reordered and deleted (soft delete — undo and permanent purge arrive with
2c). Details: [`docs/progress/phase-2b.md`](docs/progress/phase-2b.md).

## Local development (Windows / XAMPP)

Prereqs: PHP 8.2+ (production target 8.3+, `docs/14_Deployment.md`) with `sodium`, `zip`,
`pdo_sqlite` extensions enabled (uncomment `extension=sodium` and `extension=zip` in
`php.ini`, or pass `-d extension=sodium -d extension=zip` per command), Composer, Node 20+.

### Backend

```sh
cd backend
composer install
cp .env.example .env          # then set SERVER_WRAP_KEY (see .env.example) and APP_KEY
php artisan key:generate
php artisan migrate
php -S 127.0.0.1:8000 -t public public/index.php   # or: php artisan serve
```

Dev database is SQLite (`database/database.sqlite`); production is MySQL per
`docs/07_Database_Design.md` — the migrations are written for both.

`SERVER_WRAP_KEY` is base64 of exactly 32 random bytes and is the single most sensitive
secret in the system (`docs/05_Security_Architecture.md` §18):

```sh
php -r "echo base64_encode(random_bytes(32));"
```

### Frontend

```sh
cd frontend
npm install
npm run dev        # http://localhost:5173, proxies /api and /sanctum to :8000
```

If port 8000 is taken, run the API elsewhere and point the proxy at it:
`API_PROXY_TARGET=http://127.0.0.1:8031 npm run dev`.

### Tests

```sh
# Backend (61 tests — crypto vectors incl. cross-language sealed-field and vault-item vectors,
# auth flows, the Phase 1 exit-criterion lifecycle, recipients / emergency contact / vault
# unlock, vault items CRUD + access control + audit, locale parity)
cd backend && php -d extension=sodium -d extension=zip vendor/phpunit/phpunit/phpunit

# Frontend (70 tests — Argon2id reference KAT, wrap/unwrap round trips, tamper tests,
# sealed-field tests, vault item round trip / tamper / wrong-context / key rotation,
# rich-text sanitizer allow-list, Recovery Key checksum, en/ar locale parity)
cd frontend && npx vitest run
npm run build && npm run lint
```

## Working method

One phase at a time (`docs/17_Roadmap.md`), docs updated in the same change as any
implementation drift (`AI_RULES.md` #9). Next up: **Phase 2c — Attachments & quota**
(start from [`docs/progress/phase-2b.md`](docs/progress/phase-2b.md); the session handoff note is kept locally and is not in git).
