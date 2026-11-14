# 04 — System Architecture

## 1. Constraint that shapes everything: Hostinger shared hosting

This is the single biggest architectural constraint in the project, so it's stated first.
Shared hosting means:

- No root access, no custom services/daemons.
- No Redis (or only via a paid add-on many shared plans don't include — assume unavailable).
- No WebSockets / long-lived connections.
- Cron is available, but typically only at 1-minute-or-coarser granularity via cPanel-style
  cron, and PHP processes are killed at the end of a request — no `php artisan queue:work`
  running forever in the background.
- MySQL is available (per spec).
- Outbound HTTP is available (needed for push/email/SMS provider APIs).

Every component below is designed around these facts, not around "how we'd do it on a VPS."
If hosting is later upgraded (see `19_Decision_Log.md`), this doc and `14_Deployment.md` get
revised together — don't let implementation silently diverge from documented constraints.

## 2. High-level component map

```
┌─────────────────────────┐        ┌──────────────────────────────┐
│  Browser (React SPA/PWA) │◄──────►│  Laravel App (PHP-FPM,        │
│  - Vault crypto happens   │  HTTPS │  Hostinger shared hosting)     │
│    here, client-side      │        │  - Auth, API, business logic  │
│  - Never sends plaintext  │        │  - Never has plaintext vault  │
│    vault content up       │        │    keys outside the single    │
│                            │        │    audited delivery path      │
└─────────────────────────┘        └───────────────┬──────────────┘
                                                     │
                          ┌──────────────────────────┼───────────────────────┐
                          ▼                          ▼                       ▼
                 ┌────────────────┐        ┌──────────────────┐   ┌────────────────────┐
                 │  MySQL          │        │  Local encrypted   │   │  Cron (schedule:run) │
                 │  (all app data, │        │  file storage       │   │  drives check-ins,   │
                 │  ciphertext     │        │  (attachments,      │   │  reminders, queued    │
                 │  columns for    │        │  ciphertext only)   │   │  jobs, delivery       │
                 │  vault content) │        └──────────────────┘   │  trigger evaluation   │
                 └────────────────┘                                 └──────────┬───────────┘
                                                                                 ▼
                                                                    ┌────────────────────────┐
                                                                    │  Outbound providers:     │
                                                                    │  Email (SMTP/API),       │
                                                                    │  Web Push (VAPID),       │
                                                                    │  SMS (provider API)      │
                                                                    └────────────────────────┘
```

## 3. Tech stack (as specified) and why it fits the constraint

- **Frontend:** React + TypeScript + Vite + Tailwind + shadcn/ui + TanStack Query + React
  Router + i18next. All client-rendered/static-buildable — ships as static assets Hostinger
  can serve directly, no Node runtime needed in production.
- **Backend:** Laravel (PHP 8.3+) + Sanctum + Laravel Queues & Scheduler. PHP-FPM is exactly
  what shared hosting runs natively. Sanctum gives cookie-based SPA auth without needing a
  separate token-storage strategy that would tempt `localStorage` usage (forbidden, see
  `AI_RULES.md` #2).
- **Database:** MySQL — universally available on shared hosting, no separate provisioning.
- **Storage:** Local encrypted storage on the same filesystem as the app (not S3) — no extra
  service, no extra cost, consistent with "minimal recurring costs." Attachments are ciphertext
  on disk regardless of filesystem-level access; see `06_Cryptography.md` §Attachment
  Encryption.
- **Queues on shared hosting:** the `database` queue driver (not Redis), processed in short
  bursts triggered by cron (`queue:work --stop-when-empty --max-time=50`) inside the same
  scheduled minute-by-minute cron entry that runs `schedule:run`. See `09_Backend_Architecture.md`
  §Queue Strategy and `14_Deployment.md` §Cron Configuration for the exact commands.

## 4. Request lifecycle (typical authenticated write)

1. Browser encrypts payload client-side (where applicable) using keys held only in browser
   memory for the session.
2. `fetch` to Laravel API, cookie-based session auth (Sanctum), CSRF token attached.
3. Laravel: middleware stack (auth, CSRF, rate limit, input validation via Form Request) →
   Controller → Action/Service class → DTO in, DTO out.
4. Persistence via Eloquent (parameterized queries only, `AI_RULES.md` #3).
5. Response DTO serialized back to client.
6. Security-relevant actions additionally write an `audit_logs` row synchronously in the same
   transaction as the primary write (not queued — audit entries must not be lost to a failed
   background job).

## 5. Background processing model (the shared-hosting-specific part)

A single cron entry, firing every minute:

```
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

`schedule:run` fires Laravel's internal scheduler, which in turn:

- Runs the check-in evaluation sweep (see `11_CheckIn_Engine.md`) at whatever cadence
  `NFR-2` requires (≤5 minutes).
- Runs `queue:work --stop-when-empty` to drain pending jobs (notification sends, delivery
  processing) synchronously within that scheduled minute, since there's no persistent worker.
- Runs scheduled maintenance (session/token cleanup, storage-usage recalculation, backup
  export) at appropriate lower frequency (hourly/daily) via the same scheduler, not separate
  cron lines, to keep all timing logic in one place (`app/Console/Kernel.php` or
  `routes/console.php` depending on Laravel version — see `09_Backend_Architecture.md`).

This means: **no job in this system may assume it will run "eventually in the background"
within seconds.** Anything time-sensitive is designed around "will definitely run within the
next cron tick," which is minutes, not seconds. This directly shapes UX copy (`03_UI_UX_Guidelines.md`)
— e.g. "You'll receive a confirmation email shortly" rather than implying instant delivery.

## 6. Data flow: where ciphertext vs. plaintext exists

| Location | Plaintext vault content? |
|---|---|
| Browser memory during editing | Yes (necessarily) |
| Browser → Server request body | No — ciphertext only |
| MySQL columns (`vault_items`, etc.) | No — ciphertext only |
| Local file storage (attachments) | No — ciphertext only |
| Server memory during normal request handling | No |
| Server memory during the single audited delivery-trigger job | **Yes, momentarily**, to
  produce the plaintext handed to the recipient — see `06_Cryptography.md` §Zero-Access
  Architecture and `13_Delivery_Engine.md` for exactly how this is scoped, logged, and
  minimized in time |
| Server logs (application, web server, error logs) | Never — see `05_Security_Architecture.md`
  §Logging |

## 7. Multi-tenancy

Public product (per product-scope decision) — single application instance, single database,
row-level tenancy via `user_id` foreign keys throughout (not schema-per-tenant, which shared
hosting/MySQL-on-shared-hosting makes impractical at any scale). Every query touching
user-owned data is scoped by the authenticated user's ID at the query-builder/policy layer,
never trusted from client input. See `07_Database_Design.md` and `05_Security_Architecture.md`
§Authorization.

## 8. External dependencies

| Dependency | Purpose | Failure handling |
|---|---|---|
| Email provider (SMTP or transactional API) | Reminders, delivery, password reset | Retry via queue; alert admin after N failures (`12_Notification_System.md`) |
| Web Push (VAPID, no third-party service required) | Reminders | Best-effort; email is the fallback of record |
| SMS provider (e.g. Twilio-class API) | Optional paid reminders/escalation | Never the sole channel for a grace-period escalation — always paired with email |
| — no Redis, no third-party queue, no third-party cache — | | |

## 9. Why not a queue/cache service add-on

Considered and rejected for v1 to keep to "minimal recurring costs" (Core Principle #7) and
because the shared-hosting cron + database-queue pattern is sufficient at expected v1 scale.
Revisit if usage outgrows it — see `19_Decision_Log.md`.
