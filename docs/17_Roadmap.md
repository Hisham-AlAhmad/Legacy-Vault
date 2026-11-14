# 17 — Roadmap

## Phase 1 — Foundation

**Goal:** an account can be created with a real, verified key hierarchy, and the app is
secure and localized from day one.

- Registration/login, Argon2id auth hashing, session management (`05_Security_Architecture.md`
  §1-3).
- Client-side key generation, Master Key wrap/unwrap, Recovery Key generation/display
  (`06_Cryptography.md` §4, §11; `02_User_Flows.md` Flow 1).
- Server Wrap Key mechanism established end-to-end (`06_Cryptography.md` §5) — even though
  nothing consumes it yet, it must be correct from the start since retrofitting it later means
  re-keying every existing account.
- 2FA (TOTP), trusted devices/session list.
- CSRF/CSP/rate limiting/security headers baseline (`05_Security_Architecture.md`).
- i18n scaffolding (en/ar), RTL layout foundation (`03_UI_UX_Guidelines.md`).
- Design system implementation (colors, type scale, base components).
- **Exit criteria:** full registration→login→password-change→recovery round trip passes with
  real crypto (no mocks) in the test suite (`15_Testing.md` §2).

## Phase 2 — Core

**Goal:** a user can build a complete legacy — recipients, content, attachments — and see it
reflected accurately on a dashboard.

- Recipients CRUD, emergency contact setup (`02_User_Flows.md` Flow 2).
- Vault items (messages + attachments), per-recipient encryption, 100 MB quota enforcement.
- Preview flow (decrypts real stored ciphertext, `13_Delivery_Engine.md` §8).
- Dashboard (last/next check-in placeholder until Phase 3 makes it real, recipients, items,
  storage usage, security score, trusted devices).
- Export/import backup.
- **Exit criteria:** a user can fully build out a multi-recipient legacy, preview it
  accurately, and their vault content is verifiably unreadable from a raw database dump
  (manual verification against `18_Threat_Model.md` scenario 1).

### Phase 2 sub-phases

Phase 2 is delivered as six sequential sub-phases, each independently shippable and
tested, so no single step is too large to review thoroughly (`AI_RULES.md` #15 applies at
sub-phase granularity: don't start the next one with open items in the current one). The
Phase 2 exit criteria above are checked once, at the end of 2f.

| Sub-phase | Scope | Status |
|---|---|---|
| **2a — Recipients & emergency contact** | Metadata Key (HKDF) + sealed-field format for encrypted metadata (`06_Cryptography.md` §15); vault unlock after page reload; active-account gate on vault endpoints; recipients CRUD with encrypted name/relationship and step-up delete (VAULT-4, Flow 2 steps 1–3); emergency contact view/set/replace/remove (EC-1, EC-4); recipients UI, en/ar. | Done |
| **2b — Vault items (messages)** | Per-item content keys, encrypted title + rich-text body (sanitized), CRUD under a recipient, editor with persistent "You are writing to" banner (`03_UI_UX_Guidelines.md` §7), content-key rotation on edit (`06_Cryptography.md` §10) (VAULT-5, VAULT-6 text/links, VAULT-9). | Next |
| **2c — Attachments & quota** | Chunked client-side-encrypted upload, encrypted filename/MIME, 100 MB server-side quota (VAULT-7, NFR-3), download + decrypt, attachment delete; soft-delete undo window and the scheduled hard-purge job for recipients/items/files (`07_Database_Design.md` §Deletion strategy). | Planned |
| **2d — Preview** | "Preview as recipient" decrypting the actual stored ciphertext through the renderer the Phase 3 recipient access page will reuse (VAULT-8, `13_Delivery_Engine.md` §8); client-side GCM failures reported to the audit log as integrity events (`06_Cryptography.md` §9). | Planned |
| **2e — Dashboard** | `GET /dashboard` aggregate: check-in placeholder, recipients with status, items with per-item storage, storage vs. 100 MB, security score, trusted devices with revoke (DASH-1…6, CHECKIN-8 placeholder). | Planned |
| **2f — Export/import + Phase 2 exit review** | Encrypted backup export/import (VAULT-10, Flow 9, `06_Cryptography.md` §21–22); Phase 2 exit review including the raw-database-dump verification against `18_Threat_Model.md` scenario 1. | Planned |

## Phase 3 — Automation

**Goal:** the check-in engine, notifications, and delivery engine work correctly end-to-end,
including every edge case in `11_CheckIn_Engine.md` §6.

- Check-in interval selection, reminder scheduling, check-in actions (UI + signed link).
- Full state machine (`11_CheckIn_Engine.md`), cron sweep, maintenance-mode handling.
- Notification system across push/email/SMS (`12_Notification_System.md`).
- Emergency contact verification flow.
- Delivery engine (`13_Delivery_Engine.md`), recipient access flow, undo-during-grace.
- **Exit criteria:** the compressed-timeline drill from `15_Testing.md` §9 passes — a seeded
  account runs through active → grace → emergency_verification → delivery_triggered correctly,
  with accurate audit logs and correct per-recipient content delivered, nothing leaked to the
  wrong recipient.

## Phase 4 — Production

**Goal:** ready for real users, with the operational maturity a life-and-death product
requires.

- Full accessibility audit against `03_UI_UX_Guidelines.md` §6.
- Performance pass (query optimization, especially the check-in sweep index at scale).
- PWA finalization, offline dashboard tolerance.
- Deployment pipeline finalized (`14_Deployment.md`), backup/restore drill executed, key
  rotation drill executed.
- Security audit against the full checklist in `05_Security_Architecture.md` §25 and every
  scenario in `18_Threat_Model.md` re-validated against the running implementation.
- Admin/support tooling for the public-product scope (suspend account, view abuse reports).
- ToS / privacy policy / data-retention policy finalized and linked (legal content itself is
  out of scope for this engineering handbook, but the retention mechanics it depends on —
  `05_Security_Architecture.md` §21 — must be implemented and tested by this phase).
- **Exit criteria:** every item in `05_Security_Architecture.md` §25 checked, launch go/no-go
  review held against this document.

## Post-v1 candidates (not scheduled)

- Google OAuth login (AUTH-5).
- Telegram notification channel.
- WhatsApp notification channel (cost-gated).
- Threshold secret-sharing as an alternative/supplement to the Server Wrap Key model
  (`06_Cryptography.md` §23) — revisit once there's operational experience with the
  emergency-contact flow to build on.
- Premium storage tiers.
- Legal will attachment workflow.
- AI-assisted writing.
- In-app voice/video recording (vs. upload of pre-recorded files).
- Hosting upgrade to VPS/Cloud if shared-hosting limits are hit (`14_Deployment.md` §10) —
  would unlock Redis-backed queues, persistent workers, WebSockets.

## Working method reminder

Per the original spec's "Working Method" section: one phase at a time, reviewed thoroughly
before continuing, with this `docs/` folder as the living source of truth updated alongside
any implementation change (`AI_RULES.md` #9).
