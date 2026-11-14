# 05 — Security Architecture

Cryptographic design lives in `06_Cryptography.md`. This document covers everything around
the crypto: authentication, session handling, web-layer defenses, and operational security.

## 1. Authentication

- Email + password (Argon2id hashing — see `06_Cryptography.md` §Argon2id Parameters for the
  auth-hashing parameters, which are distinct from the vault key-derivation parameters).
- Google OAuth is a **future** login option (per `01_Product_Requirements.md` AUTH-5). Even
  once added, OAuth authenticates the *account*; it never grants vault decryption — see
  `18_Threat_Model.md` scenario "If Google OAuth is compromised."
- TOTP 2FA (RFC 6238), backup codes issued at enrollment (10 single-use codes, hashed at rest
  like passwords).
- Failed-login handling: exponential backoff per account (1s, 2s, 4s... capped) plus a
  per-IP rate limit independent of the per-account one, so an attacker can't route around
  account lockout by distributing attempts, nor lock out a legitimate user by hammering their
  account from elsewhere (§Brute Force below).

## 2. Authorization

- Every resource (`recipients`, `vault_items`, `attachments`, `checkins`, etc.) is scoped to
  `user_id` at the Eloquent query/policy layer (Laravel Policies), never inferred from a
  client-supplied ID alone. A request for `vault_items/123` is only ever fetched via
  `$user->vaultItems()->findOrFail(123)`, never `VaultItem::findOrFail(123)`.
- Emergency contacts and recipients are **not** application users with login access by
  default — their "authorization" is a signed, single-use, time-limited token embedded in a
  link (see `06_Cryptography.md` §Replay Protection). This avoids building and hardening a
  second full auth system for a role that only ever performs one or two actions.
- Admin roles (support/abuse handling for the public product) are a separate `is_admin` flag
  checked via policy, with all admin actions audit-logged (§Audit Trails) and admin accounts
  required to have 2FA enabled, no exceptions.

## 3. Session management

- Sanctum cookie-based SPA sessions. Session identifier is a random, server-generated token,
  never derived from user-guessable data.
- Sessions tied to a `devices` record (fingerprint: user agent + rough network info, not
  invasive device fingerprinting) so "trusted devices" (Flow 10) has something to list.
- Idle session timeout: 30 days for "remember this device," 12 hours otherwise, both
  server-enforced (not just a client-side timer).
- Logout invalidates the session server-side immediately (not just clears the cookie
  client-side).
- Sensitive actions (password change, recovery-key regeneration, recipient deletion,
  emergency-contact removal, account deletion) require re-authentication ("step-up") even
  within an active session. Re-unlocking the vault after a page reload is also
  password-gated (`19_Decision_Log.md` Decision #12).

## 4. Cookies

- Session cookie: `HttpOnly`, `Secure`, `SameSite=Strict`, scoped to the app's own domain
  path, no third-party/subdomain sharing beyond what's strictly needed for the SPA/API split
  (if API is on a subdomain, `SameSite=Strict` plus explicit CORS allow-list, not a cookie
  domain broadened to cover unrelated subdomains).
- No authentication data of any kind in `localStorage`/`sessionStorage` (`AI_RULES.md` #2) —
  this also means an XSS bug can't trivially exfiltrate a long-lived token the way it could
  with token-in-localStorage designs.

## 5. CSRF

- Sanctum's CSRF-cookie + header pattern (`XSRF-TOKEN` cookie, `X-XSRF-TOKEN` header) on every
  state-changing request. Double-submit is validated server-side on every non-GET request.
- `SameSite=Strict` on the session cookie is defense-in-depth on top of this, not a
  replacement for it.

## 6. Content Security Policy

- Strict CSP: `default-src 'self'`; no `unsafe-inline` for scripts (use nonces for any
  unavoidable inline script, ideally none); `object-src 'none'`; `frame-ancestors 'none'`
  (this app should never be framed); `connect-src` limited to the app's own API and the
  specific push/notification endpoints in use.
- No CDN-hosted third-party JS in the authenticated app shell beyond fonts, to keep the attack
  surface for supply-chain script injection minimal in the one part of the app that handles
  vault crypto in-browser.

## 7. Rate limiting

| Endpoint class | Limit |
|---|---|
| Login | 5/min per IP, 5/15min per account, both enforced |
| Registration | 3/hour per IP |
| Password reset request | 3/hour per email/IP pair |
| Check-in action (authenticated link) | 10/hour per token family (defends against link-guessing, not normal use) |
| Emergency-contact confirmation link | Single-use — see §Replay Protection in `06_Cryptography.md`, not a rate limit at all |
| General authenticated API | 120/min per user, generous but present, to blunt scripted abuse |
| Step-up re-auth inside a session (vault unlock, recipient deletion, emergency-contact removal) | 10/min per user, shared across those endpoints, so a stolen session can't use them to guess the password (Phase 2a) |
| File upload | 20/hour per user, size-checked against remaining 10 MB quota before accepting bytes |

## 8. SQL injection

Eloquent/query builder parameterized queries exclusively (`AI_RULES.md` #3). Raw queries, if
ever unavoidable (none currently anticipated), must use parameter binding, never string
concatenation, and require a second-reviewer sign-off noted in the PR.

## 9. XSS

- React's default escaping covers most output. `dangerouslySetInnerHTML` is banned unless the
  content has passed through a documented sanitizer (e.g. DOMPurify with a strict allow-list)
  — this matters specifically for the rich-text vault content, which is user-authored HTML-ish
  content that will later be rendered to a recipient.
- CSP (§6) as defense-in-depth against any sanitizer gap.

**The documented sanitizer (Phase 2b)** — `frontend/src/features/vault/sanitizeHtml.ts`,
DOMPurify with a dedicated instance (`19_Decision_Log.md` Decision #16):

| | Allowed | Everything else |
|---|---|---|
| Tags | `p br strong em u s h2 h3 ul ol li blockquote a` | removed (text kept; `script`/`style`/`iframe`-class content dropped) |
| Attributes | `href` on `a`; `dir`, `rel`, `target` — *set by the sanitizer*, never copied from input | removed — no `class`, `id`, `style`, `data-*`, `aria-*`, no event handlers |
| Link schemes | `https:`, `http:`, `mailto:` | `href` removed and the `a` unwrapped to text (`javascript:`, `data:`, `vbscript:`, relative, protocol-relative) |

- Every link is forced to `rel="noopener noreferrer nofollow" target="_blank"`; every block
  element is forced to `dir="auto"` (per-paragraph direction for mixed Arabic/English).
- It runs **twice**: on the editor's output before encryption, and on decrypted HTML
  before anything else sees it. The second pass is what protects a recipient if stored
  content was not produced by this client.
- The editor (TipTap/ProseMirror) is a second, independent allow-list: its schema only
  contains the node and mark types above, so pasted markup is reduced to them before the
  sanitizer ever runs. It is configured with `injectCSS: false`; its styles are in a
  stylesheet, so it needs no CSP exception.
- Nothing in Phase 2b renders stored HTML with `dangerouslySetInnerHTML`. The 2d preview
  and the Phase 3 recipient page must render only the output of this same function.

## 10. SSRF

- Any server-side fetch of a user-influenced URL (none currently planned — v1 has no
  "unfurl a link" or "fetch a preview image" feature) is out of scope for v1. If added later,
  must go through an allow-list + private-IP-range block + redirect-re-validation pattern,
  documented here before implementation, not left to the implementer's judgment at the time.

## 11. Directory traversal

- Attachment filenames are never used as filesystem paths. Every stored file is saved under a
  server-generated UUID filename; the user-supplied original filename is stored only as
  metadata (encrypted, per `06_Cryptography.md` §Metadata Encryption) and reattached only at
  download time via a `Content-Disposition` header, never used to construct a path.

## 12. Brute force

- See §1 (login backoff) and §7 (rate limits). Additionally: Argon2id's cost parameters
  (`06_Cryptography.md`) make offline brute-force of a leaked password hash or a leaked
  password-wrapped vault key expensive per-guess, which is the actual backstop if online rate
  limiting is somehow bypassed.

## 13. Timing attacks

- Password verification uses constant-time comparison (Argon2id verify functions are
  constant-time by design; never implement a manual byte-by-byte compare for secrets).
- Login and password-reset responses are timing-normalized where feasible (e.g. always
  perform a dummy hash-verify on a nonexistent-user login attempt) to reduce account
  enumeration via response-time difference, on top of the identical-response-body approach.

## 14. File upload security

- MIME/type validated server-side by content inspection, not just file extension or
  client-supplied `Content-Type`.
- Size enforced server-side against remaining quota before the encrypted blob is accepted.
- Stored ciphertext files are never executed, served with `Content-Disposition: attachment`
  and a locked-down `Content-Type`, and served from a path Nginx/Apache is configured to never
  execute as PHP (defense-in-depth even though content is ciphertext and thus inert anyway).

## 15. Virus scanning

- v1: since content is client-side encrypted before upload, the server cannot scan plaintext
  content (it never has it) — this is an accepted trade-off of the encryption model, stated
  plainly rather than glossed over. Mitigations: the risk is to the *recipient* who eventually
  decrypts and opens the file client-side, not to the server (server never executes it). UI
  copy for recipients notes that attachments come from the account owner and should be treated
  like any personal file transfer. Revisit if a client-side scanning approach (e.g. scanning
  before encryption, in-browser, against a signature set) becomes worth the complexity —
  logged as an open item in `17_Roadmap.md`.

## 16. Logging

- Application logs: request metadata, error traces, timing — **never** request/response
  bodies for any endpoint touching auth or vault content, never password fields even redacted-
  by-convention (redact by field name allow-list, not deny-list, to fail closed).
- Audit logs (§17) are a separate store from application logs, different retention policy,
  different access control.

## 17. Audit trails

Audit-logged events (append-only `audit_logs` table, see `07_Database_Design.md`): login
success/failure, password change, 2FA enroll/disable, recovery-key regeneration, recipient
create/update/delete, vault item create/update/delete/reorder (IDs only — never titles,
content or ciphertext), emergency-contact change, check-in, all check-in-engine state
transitions, delivery trigger and each per-recipient delivery, session revocation, admin
actions. Each row: `user_id`, `actor_type` (user/system/emergency_contact/admin), `event_type`,
`metadata` (JSON, no secrets), `ip_address`, `created_at`. Retained minimum 1 year (`NFR-4`),
never hard-deleted even if the account is deleted (retained per §21 data-retention policy,
anonymized to remove PII beyond what's needed for a legal/abuse record).

## 18. Secrets management

- All secrets (app key, DB credentials, provider API keys, VAPID keys, the server-side wrap
  key from `06_Cryptography.md`) live in environment variables outside the web root, loaded
  via Laravel's `.env` mechanism, never committed to git (`AI_RULES.md` #7).
- The server-side wrap key specifically is the single most sensitive secret in the system —
  see `06_Cryptography.md` §Key Hierarchy for handling requirements (rotation plan, access
  restricted to the app process only, not readable by any other site on shared hosting if
  account isolation allows it).

## 19. Server permissions

- Shared hosting constrains this, but within it: web root permissions locked to the minimum
  the web server user needs; `.env` and storage directories outside the publicly served
  document root; file uploads directory not web-accessible directly (served through an
  authenticated controller route, not a static file URL).

## 20. HTTPS / HSTS

- HTTPS-only, enforced at the web server level (redirect, not just "available"). HSTS header
  with a long `max-age`, `includeSubDomains`, and preload once the domain is stable enough to
  commit to preload (preload is hard to reverse — noted as a deliberate one-way decision).

## 21. Backups & disaster recovery

- Nightly encrypted database backup, stored off the primary hosting account (e.g. pushed to
  separate storage), retained on a rolling window (e.g. 30 daily + 12 monthly).
- Backups contain ciphertext for vault content by construction — a backup leak has the same
  blast radius as a live database leak (see `18_Threat_Model.md` scenario 1), which is a
  deliberate simplification: we do not need a separate "backup-specific" threat analysis.
- Restore procedure documented and tested at least once before go-live (`14_Deployment.md`).
- Data-retention policy for deceased/completed accounts: content is retained for a defined
  window post-delivery (e.g. 90 days, allowing recipient re-access) then the account and its
  ciphertext are purged; audit-log entries about the account persist per §17 in anonymized
  form.

## 22. Monitoring

- Uptime monitoring on the public endpoints (external, third-party, so it doesn't depend on
  the same host being up).
- Error-rate and queue-backlog alerting (a growing `jobs` table backlog is itself a signal
  something's wrong with cron on shared hosting — alert on it specifically, since there's no
  worker-health-check equivalent to a persistent daemon).
- Failed-login-spike and check-in-engine anomaly alerting (e.g. an unusual number of
  simultaneous "grace period" entries could indicate the scheduler stalled rather than that
  many users actually missed check-ins).

## 23. Incident response

- Documented, minimal runbook: who gets notified, how to rotate the app key / DB credentials /
  provider API keys quickly, how to put the app in maintenance mode without breaking the
  check-in engine's correctness (i.e. maintenance mode must not silently cause missed
  check-ins to look like true misses — see `11_CheckIn_Engine.md` §Maintenance Mode Handling).
- Any suspected vault-content exposure gets a direct, plain-language user notification — no
  minimizing language — per Core Principle #2 (honesty about the security model).

## 24. OWASP Top 10 mapping (quick reference)

| OWASP category | Where addressed |
|---|---|
| Broken Access Control | §2 Authorization |
| Cryptographic Failures | `06_Cryptography.md` entirely |
| Injection | §8, §9 |
| Insecure Design | This whole document + `18_Threat_Model.md` |
| Security Misconfiguration | §6, §19, §20, `14_Deployment.md` |
| Vulnerable/Outdated Components | `16_Coding_Standards.md` §Dependency Policy |
| Auth Failures | §1, §3 |
| Software/Data Integrity Failures | `06_Cryptography.md` §Integrity Verification |
| Logging/Monitoring Failures | §16, §17, §22 |
| SSRF | §10 |

## 25. Security checklist (pre-launch gate)

- [ ] All endpoints behind auth/CSRF/rate-limit middleware as specified
- [ ] CSP enforced, no `unsafe-inline`
- [ ] HTTPS + HSTS enforced
- [ ] Argon2id parameters match `06_Cryptography.md` exactly, verified with test vectors
- [ ] No secret in git history (scanned)
- [ ] Audit logging verified end-to-end for every event in §17
- [ ] Backup restore tested
- [ ] Rate limits verified with load test
- [ ] File upload MIME/size/path checks verified with adversarial test files
- [ ] Session revocation verified to take effect immediately, not just client-side
- [ ] Threat model (`18_Threat_Model.md`) scenarios re-validated against the actual
      implementation, not just the design
