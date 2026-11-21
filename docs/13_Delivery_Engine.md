# 13 — Delivery Engine

## 1. Trigger conditions

Exactly one entry point: `checkins.state` transitions to `delivery_triggered`
(`11_CheckIn_Engine.md` §1). No other code path may initiate delivery — not an admin action,
not a support request, not a user "test delivery" button (a *preview*, per
`01_Product_Requirements.md` VAULT-8, is explicitly different from real delivery and never
touches `DeliveryTriggerService`).

## 2. Execution flow (`DeliveryTriggerService::executeDelivery`)

1. Load the user's `server_wrapped_master` row (`07_Database_Design.md` `encrypted_keys`).
2. Unwrap the Master Key using the SWK (`06_Cryptography.md` §5) — held in process memory
   only, for this job's duration.
3. For each recipient belonging to the user:
   a. Load their `vault_items` + `attachments` — live rows only (soft-deleted items and
      items of soft-deleted recipients are never delivered, `19_Decision_Log.md` Decision #18).
   b. Derive the Content-Wrap Key from the Master Key (HKDF, `06_Cryptography.md` §4), unwrap
      each item's content key, then decrypt the item's title and body. The AAD is rebuilt from
      the row's own `user_id`, `recipient_id` and `uuid` (§9), so a row that was re-pointed at
      a different recipient fails its tag check and is reported as an integrity failure, not
      delivered. The decrypted HTML is passed through the sanitizer allow-list
      (`05_Security_Architecture.md` §9) before it is rendered to anyone.
   c. Decrypt each attachment similarly (streaming, per `06_Cryptography.md` §12).
   d. Generate a signed, single-use, time-limited (§4) access token bound to this recipient.
   e. Dispatch delivery notifications (`12_Notification_System.md`) per the recipient's
      preferred methods, containing the access link, not the raw content.
   f. Write a `delivery_methods` status row and an `audit_logs` entry per recipient/method.
4. On completion of all recipients, mark `users.status = 'deceased_processing'`, clear the
   Master Key from process memory (no explicit "clear" is reliable in PHP's memory model, so
   this is scoped to end when the job/process ends — kept short and single-purpose
   deliberately, §6).
5. Any failure mid-loop (e.g. one recipient's email provider is down) does not roll back
   already-completed recipients — delivery is per-recipient atomic, not all-or-nothing, so one
   failure doesn't block delivery to everyone else. Failed recipients are retried
   (`09_Backend_Architecture.md` §5 retry semantics) using freshly-decrypted content on retry
   (never cached plaintext between attempts — see §6).

## 3. Recipient access (the `/delivery/access/{token}` flow)

- Content is decrypted server-side at step 2-3 above and stored, for the access window
  duration, in a way that lets the recipient's browser render it — this necessarily means
  plaintext (or a server-held decrypted representation) exists for the access-window duration,
  which is the explicit, documented cost of "recipients see readable content automatically"
  (`06_Cryptography.md` §3). This is scoped as tightly as possible:
  - Decrypted content for recipient access is held in a short-TTL cache/table entry, not
    re-derived from the original ciphertext+key on every view (avoids re-touching the Master
    Key repeatedly), and is purged at the end of the access window (§4) regardless of whether
    the recipient ever opened the link.
  - This purged-decrypted-copy table is itself still access-controlled and never directly
    web-exposed — reached only through the authenticated signed-link controller.

## 4. Recipient verification

Given recipients have no account, the signed link itself is the primary credential, backed by
possession of the channel it was sent to (their own email inbox or phone). For an extra step
where the delivery method is `link` alone (e.g. shared via a channel the platform doesn't
control), a lightweight secondary check applies — e.g. confirming the last 4 digits of the
phone number on file, decided per method at implementation time and documented here before
Phase 3 sign-off.

Access window: 90 days from first successful delivery attempt (matches
`05_Security_Architecture.md` §21 retention policy) — after which the access link expires and
underlying ciphertext/decrypted-cache entries are purged per the deletion strategy in
`07_Database_Design.md`.

## 5. Undo / grace period

Per `01_Product_Requirements.md` DELIV-4: undo is only possible while `checkins.state` is
still `grace_period` or `emergency_verification` — a simple check-in action from the user, or
an emergency-contact confirmation, both handled entirely within `11_CheckIn_Engine.md`. Once
`delivery_triggered` is reached and `DeliveryTriggerService` begins executing, there is no
undo — this is deliberate and disclosed to users at setup time, not a limitation to quietly
work around later.

## 6. Minimizing plaintext exposure window

- The Master Key is only ever unwrapped inside `DeliveryTriggerService`'s process, never
  passed to another service or cached outside it.
- Decrypted item/attachment content used for recipient access (§3) is generated once at
  trigger time and TTL'd, not regenerated from the Master Key on every recipient page view —
  bounding how many times the Master Key itself is actually used to the single trigger event.
- No plaintext from this flow is ever written to application logs
  (`05_Security_Architecture.md` §16).

## 7. Audit trail for delivery

Every step in §2 is audit-logged individually (per-recipient, per-method), so a post-incident
review (or a support inquiry from a grieving family member asking "did my mother's message
actually go out") can be answered precisely from `audit_logs` without needing to re-decrypt
anything.

## 8. Delivery preview vs. real delivery — the critical distinction

`01_Product_Requirements.md` VAULT-8 / DELIV-5 requires the user-facing preview to match real
delivery exactly. This is achieved by having the preview UI decrypt the same stored ciphertext
client-side (`02_User_Flows.md` Flow 2 step 7), rendering it through the same
templates/components the recipient-facing access page uses — not a separate, divergent
preview renderer that could drift out of sync with what's actually delivered.

**As built (Phase 2d, `19_Decision_Log.md` Decision #30):** that shared component is
`frontend/src/features/recipientView/RecipientContentView`. It takes an already-opened
`RecipientContent` value and renders it; it never decrypts or fetches. The owner's preview
builds that value in the browser from the stored ciphertext; Phase 3 must build the same
value for `/delivery/access/{token}` from what §3 makes available, and must follow the
same selection rules the preview shows — live items in `position` order, completed
attachments only, an entry that fails its integrity check reported and **not** delivered
(the preview labels such an entry "would not be delivered").
