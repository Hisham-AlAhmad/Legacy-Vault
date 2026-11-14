# 12 — Notification System

## 1. Channel priority (per spec)

1. Web Push (PWA-ready)
2. Email
3. SMS (optional, paid)
4. Telegram (future)
5. WhatsApp (postponed — API cost)

## 2. Architecture

`NotificationService::send(Notifiable $target, string $type, array $data)` — a single
dispatch point that: resolves which channels apply (user preference ∩ what's configured for
this notification type ∩ what's available for this target, e.g. emergency contacts never get
push since they have no account/browser subscription), renders the localized template
(`09_Backend_Architecture.md` §9), writes a `notifications` row, and queues the actual
provider send as a job (`09_Backend_Architecture.md` §5 queue constraints apply).

## 3. Providers

| Channel | Provider approach | Notes |
|---|---|---|
| Web Push | VAPID keys, browser Push API, no third-party service required | Free, no recurring cost — fits Core Principle #7 |
| Email | Transactional email API or SMTP (provider decided at deployment time, credentials in `.env`) | Must support deliverability features (SPF/DKIM/DMARC) given how much rides on emails actually arriving |
| SMS | Twilio-class provider | Paid per-message — billed to the user as an opt-in add-on, cost shown before enabling |

## 4. Templates

Each notification `type` has an email template + SMS short-text template + push
title/body, in both `en` and `ar`, stored under the same key structure as the frontend i18n
files so a translator/reviewer can find the English and Arabic version of any given message
side by side.

Notification types (non-exhaustive, extend as features are added):

- `checkin_reminder_early`, `checkin_reminder_final`
- `grace_period_started`, `grace_period_escalation`
- `emergency_verification_requested` (to emergency contact)
- `emergency_verification_reminder`
- `delivery_recovered` (to user, "we almost triggered delivery")
- `delivery_notice` (to recipient)
- `security_alert` (new device login, password changed, recovery key regenerated)

## 5. Retry logic

- Provider send failures: retried via the queue's standard retry (`tries=3`,
  `09_Backend_Architecture.md` §5), with backoff.
- After exhausting retries, a `notifications.status = 'failed'` row is left for visibility,
  and — for anything on the check-in/delivery critical path specifically — a fallback channel
  is attempted (e.g. push failure silently falls back to relying on the already-parallel email
  send rather than treating push as authoritative for anything time-critical).
- Persistent failure on a *user's own* contact channel (bouncing email) triggers a
  `security_alert`-style nudge through whichever channel still works, prompting the user to
  update their contact info — this matters specifically because a stale email is a check-in
  engine reliability risk (`11_CheckIn_Engine.md` §6).

## 6. What notifications never contain

No vault content, ever, in a push/email/SMS body — notification payloads are template data
only (recipient name if needed for context, links, dates), never decrypted or ciphertext
vault content (`07_Database_Design.md` `notifications.payload` — enforced as non-sensitive by
convention and reviewed in `16_Coding_Standards.md`). Delivery notices link to the secure
access flow (`08_API_Specification.md` `/delivery/access/{token}`) rather than embedding
content directly, consistent with `02_User_Flows.md` Flow 5 step 1's reasoning about forwarded
emails/insecure inboxes.

## 7. User preference management

Dashboard → Settings → Notifications: per-type channel toggles where it makes sense (e.g. user
can disable push but not disable grace-period escalation channels entirely — at least one
channel must remain enabled for anything on the check-in critical path, enforced in the UI and
re-validated server-side).
