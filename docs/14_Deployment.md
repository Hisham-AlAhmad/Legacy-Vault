# 14 — Deployment (Hostinger Shared Hosting)

## 1. Environment layout

```
/home/<user>/
├── legacyvault-app/            # Laravel app, OUTSIDE public_html
│   ├── app/, routes/, ... 
│   ├── storage/                 # attachments (ciphertext), logs, framework cache
│   └── .env                     # never web-accessible, never in git
└── public_html/                 # web root
    ├── index.php                 # Laravel front controller (points to ../legacyvault-app)
    ├── assets/                   # Vite build output (frontend static assets)
    └── .htaccess                  # HTTPS redirect, security headers, deny dotfiles
```

Keeping the Laravel application code outside `public_html` (with only `index.php` and public
assets inside it) is the standard shared-hosting hardening pattern — it means `.env`,
`storage/`, and application source are never reachable by a direct URL even if web server
config has a gap.

### 1a. Security headers for the static SPA (`public_html/.htaccess`)

The SPA's `index.html` and its assets are static files, so Laravel never sees those
requests: the headers have to be set by the web server. Add this to `public_html/.htaccess`
(requires `mod_headers`, which Hostinger enables). The CSP value must be identical to the one
in `backend/app/Http/Middleware/SecurityHeaders.php` — change both together
(`05_Security_Architecture.md` §6):

```apache
<IfModule mod_headers.c>
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
</IfModule>
```

Verify after every deploy by opening the app with the browser console open: there must be no
`securitypolicyviolation` messages, and registration/login must complete. (Phase 4's pre-launch
checklist, `05_Security_Architecture.md` §25, includes this.)

## 2. Environment variables (`.env`)

All secrets from environment variables (`AI_RULES.md` #7): `APP_KEY`, `DB_*`, `SERVER_WRAP_KEY`
(the SWK, `06_Cryptography.md` §5 — the single most sensitive value in this file),
`MAIL_*`/email provider credentials, `SMS_PROVIDER_*` credentials, `VAPID_*` keys. `.env` is
never committed; `.env.example` documents every required key with placeholder values.

## 3. Cron configuration

Single cron entry (`04_System_Architecture.md` §5):

```
* * * * * php /home/<user>/legacyvault-app/artisan schedule:run >> /dev/null 2>&1
```

`routes/console.php` (or `Kernel.php` depending on Laravel version) schedules, in order:

```
Schedule::command('checkins:evaluate')->everyFiveMinutes();
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute();
Schedule::command('sessions:cleanup')->daily();
Schedule::command('storage:recalculate-usage')->hourly();
Schedule::command('backup:run')->dailyAt('02:00');
```

*As built through Phase 2c* (`backend/routes/console.php`): the queue drain (every minute),
`accounts:purge-unacknowledged` (hourly) and **`vault:purge-deleted` (hourly,
`withoutOverlapping`)** — the permanent erasure of soft-deleted recipients, items and
attachment files past the 30-day undo window, plus uploads abandoned for 24 hours
(`19_Decision_Log.md` Decision #29). It works in batches of 100 and stops after 40 seconds,
so it never outlives its slot on shared hosting. `storage:recalculate-usage` is not needed:
usage is computed from `attachments.size_bytes` on each request (Decision #26).

**Attachment storage (Phase 2c).** Encrypted files live on the private `attachments` disk,
`storage/app/private/attachments` by default (`ATTACHMENTS_DISK_ROOT` overrides it), which is
under `legacyvault-app/`, outside `public_html` (§1) — never under `public/` and never
symlinked by `storage:link`. The directory must be writable by PHP. Include it in the
off-host backup (§6): it holds ciphertext only, but without it the attachments are lost.
`ATTACHMENT_QUOTA_BYTES` (default 10,485,760) sets the per-user quota. An encrypted chunk is
sent as a raw request body of at most 1,048,605 bytes, within PHP's default `post_max_size`
(8 MB), which bounds raw bodies too; `upload_max_filesize` does not apply (no multipart).

## 4. Build & release process

1. CI builds the Vite frontend (`10_Frontend_Architecture.md` §10) → static assets.
2. CI runs backend + frontend test suites (`15_Testing.md`) — release blocked on failure.
3. Deploy via git pull (or rsync) to the app directory outside `public_html`, `composer
   install --no-dev --optimize-autoloader`, `php artisan migrate --force`,
   `php artisan config:cache`, `php artisan route:cache`, copy built frontend assets into
   `public_html/assets`.
4. Zero-downtime isn't fully achievable on basic shared hosting; deploys are scheduled during
   low-traffic windows, and the maintenance-mode check-in extension procedure
   (`11_CheckIn_Engine.md` §7) runs automatically as a pre-deploy CI step, not a manual one, to
   remove the risk of forgetting it.

## 5. Migrations

Run via `php artisan migrate --force` post-deploy. Destructive migrations (column
drops/renames affecting `06_Cryptography.md`-relevant columns) require a written rollback plan
in the PR description, per `AI_RULES.md` #12 backward-compatibility rule.

## 6. Backups

Nightly `backup:run` command (`05_Security_Architecture.md` §21): dumps MySQL, bundles with
`storage/app` (encrypted attachments), encrypts the bundle itself for transport, pushes to
off-host storage (a separate account/service from the primary Hostinger plan, so a single
hosting-account compromise doesn't also compromise the backups). Retention: 30 daily + 12
monthly. Restore procedure tested at least once pre-launch and after any significant schema
change.

## 7. Key rotation procedure (SWK)

Documented as a runbook, not just a concept (`06_Cryptography.md` §10): generate new SWK,
run a migration job that unwraps every `server_wrapped_master` row with the old SWK and
re-wraps with the new one inside a DB transaction per user (batched, resumable — this can't
run in one shared-hosting cron tick for a large user base, so it's designed as a chunked,
resumable job), only then update `.env` and remove the old SWK from anywhere it was held
during the migration (in-memory only, never written to disk mid-migration).

## 8. Monitoring & alerting on shared hosting

Since there's no persistent process to run a monitoring agent, monitoring is external:
third-party uptime checks against public endpoints, and a lightweight internal "heartbeat"
row (`audit_logs` or a dedicated `heartbeats` table) written by the cron sweep every tick —
external monitoring alerts if no heartbeat has been written in >10 minutes, which is the
shared-hosting-appropriate proxy for "cron/scheduler has stalled"
(`05_Security_Architecture.md` §22).

## 9. TLS / domain

HTTPS via Hostinger's provided SSL (or Let's Encrypt if self-managed), forced redirect,
HSTS (`05_Security_Architecture.md` §20). Domain/DNS management is out of scope for this
document beyond noting it's a prerequisite.

## 10. Scaling trigger

If usage outgrows shared hosting (sustained cron-tick overrun, storage limits, or MySQL
connection limits under load), the documented upgrade path is VPS/Cloud hosting per
`19_Decision_Log.md`'s hosting decision — at which point `04_System_Architecture.md` §1 and
this document are revised together, introducing Redis-backed queues and a persistent worker,
rather than letting the two drift out of sync.
