# 01 — Product Requirements

## 1. Requirement notation

Each requirement is tagged `MUST` (v1 blocking), `SHOULD` (v1 target, can slip to v1.1), or
`FUTURE` (post-v1). Each maps to a phase from `17_Roadmap.md`.

## 2. Account & Authentication

| ID | Requirement | Priority | Phase |
|----|---|---|---|
| AUTH-1 | User can register with email + password | MUST | 1 |
| AUTH-2 | Password strength enforced client- and server-side (zxcvbn score ≥ 3) | MUST | 1 |
| AUTH-3 | User can log in with email + password | MUST | 1 |
| AUTH-4 | User can enable TOTP 2FA (authenticator app) | MUST | 1 |
| AUTH-5 | User can log in with Google OAuth | FUTURE | — |
| AUTH-6 | User can view and revoke active sessions/trusted devices | MUST | 1 |
| AUTH-7 | User can view login history | MUST | 1 |
| AUTH-8 | Account lockout / backoff after repeated failed logins | MUST | 1 |
| AUTH-9 | Password reset via email, does not expose whether email exists | MUST | 1 |
| AUTH-10 | Password reset explicitly does NOT restore vault access without the Recovery Key (see `06_Cryptography.md`) | MUST | 1 |

## 3. Vault Creation & Encryption

| ID | Requirement | Priority | Phase |
|----|---|---|---|
| VAULT-1 | All vault content (messages, files, metadata subject to `06_Cryptography.md` §Metadata Encryption) is encrypted client-side before it ever reaches the network | MUST | 1–2 |
| VAULT-2 | User receives a Recovery Key at signup and must acknowledge saving it before proceeding | MUST | 1 |
| VAULT-3 | User can create a "legacy" (a named collection of recipients + content) | MUST | 2 |
| VAULT-4 | User can add recipients: name, relationship, phone, optional email, preferred delivery methods | MUST | 2 |
| VAULT-5 | Each recipient's content is independently encrypted and inaccessible to any other recipient | MUST | 2 |
| VAULT-6 | Supported content types: rich text, images, PDF, Word docs, ZIP, audio, video, links | MUST | 2 |
| VAULT-7 | Attachment limit: 100 MB total per user (v1) | MUST | 2 |
| VAULT-8 | User can preview exactly what a recipient will see before saving | MUST | 2 |
| VAULT-9 | User can edit or delete vault content at any time while alive | MUST | 2 |
| VAULT-10 | User can export an encrypted backup and re-import it | SHOULD | 2 |

## 4. Check-In System

| ID | Requirement | Priority | Phase |
|----|---|---|---|
| CHECKIN-1 | User selects a check-in interval: 1 week / 1 month / 6 months / 1 year / custom | MUST | 3 |
| CHECKIN-2 | System reminds the user before a check-in is due (push, email, optional SMS) | MUST | 3 |
| CHECKIN-3 | User can check in with a single action from any reminder channel | MUST | 3 |
| CHECKIN-4 | Missed check-in triggers a grace period, not immediate delivery | MUST | 3 |
| CHECKIN-5 | During grace period, escalating reminders fire on a defined schedule | MUST | 3 |
| CHECKIN-6 | Emergency contact is notified and can pause the process by confirming the user is alive | MUST | 3 |
| CHECKIN-7 | If grace period and emergency-contact step both fail to produce a "user is alive" signal, delivery triggers | MUST | 3 |
| CHECKIN-8 | Dashboard shows last check-in, next check-in due date, and current state plainly | MUST | 2 |
| CHECKIN-9 | All state transitions are audit-logged | MUST | 3 |

## 5. Emergency Contact

| ID | Requirement | Priority | Phase |
|----|---|---|---|
| EC-1 | Emergency contact is a distinct role from recipients (may or may not overlap) | MUST | 2–3 |
| EC-2 | Emergency contact does not have vault access — only a pause/confirm action | MUST | 3 |
| EC-3 | Emergency contact confirmation uses a signed, single-use, time-limited link | MUST | 3 |
| EC-4 | Emergency contact can be changed by the user at any time | MUST | 2 |

## 6. Notifications

| ID | Requirement | Priority | Phase |
|----|---|---|---|
| NOTIF-1 | Web push notifications (PWA-ready) | MUST | 3 |
| NOTIF-2 | Email notifications | MUST | 3 |
| NOTIF-3 | Optional paid SMS notifications | SHOULD | 3 |
| NOTIF-4 | Telegram integration | FUTURE | — |
| NOTIF-5 | WhatsApp integration | FUTURE (cost-blocked) | — |

## 7. Delivery

| ID | Requirement | Priority | Phase |
|----|---|---|---|
| DELIV-1 | On confirmed trigger, each recipient receives only their own content, decrypted, via their preferred method(s) | MUST | 3 |
| DELIV-2 | Delivery includes a secure, authenticated, single-recipient access link, not a raw attachment blast where avoidable | MUST | 3 |
| DELIV-3 | Delivery is logged per-recipient, per-item, with timestamps | MUST | 3 |
| DELIV-4 | Undo is possible during the grace period only — never after trigger has fully executed | MUST | 3 |
| DELIV-5 | Delivery preview (§VAULT-8) matches actual delivered output exactly | MUST | 3 |

## 8. Dashboard

| ID | Requirement | Priority | Phase |
|----|---|---|---|
| DASH-1 | Last check-in / next check-in | MUST | 2 |
| DASH-2 | Recipients list with per-recipient status | MUST | 2 |
| DASH-3 | Legacy items list with storage used per item | MUST | 2 |
| DASH-4 | Total storage usage vs. 100 MB limit | MUST | 2 |
| DASH-5 | Security score (2FA on/off, recovery key acknowledged, trusted devices reviewed, etc.) | SHOULD | 2 |
| DASH-6 | Trusted devices list with revoke action | MUST | 2 |

## 9. Localization & Accessibility

| ID | Requirement | Priority | Phase |
|----|---|---|---|
| I18N-1 | Full English support | MUST | 1 |
| I18N-2 | Full Arabic support, correct RTL layout (not just mirrored text) | MUST | 1 |
| I18N-3 | Every new string ships with both locale keys in the same change (see `AI_RULES.md` #10) | MUST | all |
| A11Y-1 | Keyboard navigable, visible focus states, semantic HTML/ARIA | MUST | 4 (audited), ongoing (built-in) |

## 10. Non-functional requirements

| ID | Requirement | Priority |
|----|---|---|
| NFR-1 | Runs within Hostinger shared hosting constraints (no persistent daemons, no Redis, no WebSockets) | MUST |
| NFR-2 | Check-in scheduler evaluation runs at least every 5 minutes via cron | MUST |
| NFR-3 | 100 MB/user storage enforced server-side, not just client-side | MUST |
| NFR-4 | All security-relevant actions audit-logged with 1-year minimum retention | MUST |
| NFR-5 | PWA installable, offline-tolerant for read-only dashboard views | SHOULD |
| NFR-6 | Public registration open to any user (multi-tenant from day one) | MUST |

## 11. Explicitly out of scope for v1

- Legal will attachment / notarization workflow (placeholder field only).
- AI-assisted writing.
- Premium storage tiers beyond the base 100 MB.
- Native mobile apps.
- Voice/video message recording in-app (file upload of pre-recorded audio/video is in scope;
  in-browser recording is not).
