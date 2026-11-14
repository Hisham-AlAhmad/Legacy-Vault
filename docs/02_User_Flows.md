# 02 — User Flows

Every flow below is written as a numbered sequence of screens/states plus the error/edge
branches. Frontend routes referenced use placeholder paths; exact routing lives in
`10_Frontend_Architecture.md`.

## Flow 1 — First-time registration & vault key setup

1. Landing page → "Get Started."
2. User enters email + password (client-side strength check, zxcvbn ≥ 3).
3. Client generates the user's Master Key material (see `06_Cryptography.md` §Master Key
   Generation) entirely in-browser.
4. Client derives a Recovery Key, encrypts a copy of the Master Key with it, and displays the
   Recovery Key **once**, with a mandatory "I have saved this somewhere safe" checkbox plus a
   download-as-file option. The raw Recovery Key is never sent to or stored by the server.
5. Server receives: email, password hash (Argon2id), wrapped Master Key (wrapped by
   password-derived key), wrapped Master Key (wrapped by Recovery-Key-derived key), and the
   server-side wrap described in `06_Cryptography.md` §Key Hierarchy. Server never receives
   the plaintext Master Key, the password in plaintext beyond the request body used to hash it,
   or the plaintext Recovery Key.
6. Account created, session cookie issued, user lands on empty dashboard.
7. Dashboard shows a "Set up your legacy" prompt and a "Security score" nudging 2FA + emergency
   contact setup.

**Edge cases:** user closes tab before acknowledging Recovery Key save → account is not
finalized; a partial-registration record expires after 24h and is purged (see
`07_Database_Design.md` §users, `deleted_at`/pending-state column).

## Flow 2 — Creating a legacy (recipients + content)

1. Dashboard → "Add Recipient."
2. Form: name, relationship, phone, optional email, preferred delivery method(s) checkboxes
   (push doesn't apply to recipients — push is for the account owner only; recipient methods
   are email / SMS / link).
3. Recipient saved (recipient records are not encrypted with the vault key — see
   `07_Database_Design.md` §recipients — but content addressed to them is).
4. User selects a recipient → "Write content."
5. Rich text editor + attachment upload. On save:
   a. Client encrypts the item content with a per-item content key.
   b. The per-item content key is wrapped with the user's Master Key.
   c. Ciphertext + wrapped per-item key uploaded to server.
6. User can add multiple items per recipient, and repeat for multiple recipients — fully
   independent per recipient (VAULT-5).
7. "Preview as recipient" renders exactly what will be delivered, decrypted client-side using
   keys already in the browser session (never re-fetched in plaintext from the server for this
   preview — see `06_Cryptography.md` §Integrity Verification for why preview must decrypt the
   same ciphertext that will actually be delivered, not a separate unencrypted preview copy).
8. User sets check-in interval and emergency contact (can be deferred, but dashboard nags if
   incomplete).

**Edge case:** user uploads an attachment that would exceed the 100 MB total → rejected
client-side before encryption/upload starts, with a clear message and current usage shown.

## Flow 3 — Ongoing check-ins

1. Reminder fires per `11_CheckIn_Engine.md` schedule (push / email / SMS per user preference).
2. User clicks "I'm here" in the notification or logs in and clicks "Check in" on the
   dashboard.
3. Server validates the check-in action is authenticated and not replayed (single-use token
   for the notification-link path), records a `checkins` row, recalculates next-due date.
4. Dashboard reflects updated "Next check-in" date immediately.

**Edge case — user is on vacation / knows they'll miss a check-in:** user can proactively
extend from the dashboard ("Check in early" — always available, resets the clock).

## Flow 4 — Missed check-in → grace period → emergency contact → delivery

This is the highest-stakes flow in the product. Full state machine in
`11_CheckIn_Engine.md` §State Machine. Narrative version:

1. Check-in due date passes with no check-in recorded.
2. State moves from `active` to `grace_period`. Escalating reminders begin (more frequent,
   more channels, including SMS if enabled even if the user hadn't enabled it for routine
   reminders — this is a judgment call documented in `19_Decision_Log.md`).
3. If user checks in at any point during grace period → state returns to `active`, full reset,
   audit-logged as a "recovered from grace period" event.
4. If grace period elapses with no check-in → state moves to `emergency_verification`. The
   emergency contact is notified via all available channels and given a signed, single-use,
   time-limited link with two options: "They're alive / I've spoken to them" (pauses, returns
   state to `active` pending user re-confirmation) or "I don't know" / no response.
5. If the emergency contact confirms the user is alive → state resets, user is notified (once
   reachable) that a delivery was nearly triggered, prompting a security review.
6. If the emergency contact does not respond within the emergency-verification window, or
   explicitly cannot confirm → state moves to `delivery_triggered`. This is irreversible.
7. Delivery Engine (`13_Delivery_Engine.md`) unwraps each recipient's content server-side
   (the one audited code path allowed to do this — see `06_Cryptography.md` §Zero-Access
   Architecture) and sends each recipient their content via their preferred method(s).
8. Every step is audit-logged with timestamps, actor (system/user/emergency contact), and
   state-transition reason.

## Flow 5 — Recipient receiving delivered content

1. Recipient receives an email/SMS with a secure, single-recipient, time-limited access link
   (not the raw content in the message body, to reduce exposure via forwarded emails/insecure
   inboxes — see `13_Delivery_Engine.md` §Recipient Access).
2. Recipient opens link → lightweight identity check appropriate to the delivery method (see
   `13_Delivery_Engine.md` §Recipient Verification) → sees decrypted content addressed to them,
   and only to them.
3. Recipient can download attachments individually or as a bundle.
4. Access is logged (timestamp, IP, user agent) for the account owner's audit trail (visible
   posthumously only to whoever the platform's post-delivery admin process allows — see
   `05_Security_Architecture.md` §Audit Trails).

## Flow 6 — Password reset (without Recovery Key)

1. User requests reset via email.
2. Server sends reset link regardless of whether the email exists (no account enumeration).
3. User sets a new password.
4. **Critical:** the new password can authenticate the account, but cannot decrypt the
   existing vault, because the vault key was wrapped by the *old* password-derived key, which
   the server never stored in a form the new password can unwrap. See `06_Cryptography.md`
   §Password Reset Flow for the exact mechanism and why this is correct, not a bug.
5. UI explicitly tells the user: "Your account access has been restored, but your vault is
   still locked. Use your Recovery Key to unlock it." → routes to Flow 7.

## Flow 7 — Vault recovery via Recovery Key

1. User enters their Recovery Key (plus current password, now that account access is
   restored).
2. Client fetches the Recovery-Key-wrapped Master Key, unwraps it client-side using the
   entered Recovery Key.
3. Client re-wraps the Master Key with a newly derived key from the current password and
   uploads the new wrapped copy, replacing the stale one.
4. A **new** Recovery Key is generated and shown once (old Recovery Key is invalidated — see
   `06_Cryptography.md` §Recovery Process).
5. Vault fully accessible again.

**Edge case — user has lost both password and Recovery Key:** vault is unrecoverable by
design. UI must state this clearly at Recovery Key generation time (Flow 1, step 4) so this is
never a surprise.

## Flow 8 — Changing password (already logged in, vault unlocked)

1. User enters current password + new password.
2. Client unwraps Master Key with current-password-derived key (already in session or
   re-prompted), re-wraps with new-password-derived key.
3. Server updates password hash and the password-wrapped Master Key atomically.
4. Recovery-Key-wrapped copy is untouched — old Recovery Key still works.

## Flow 9 — Export / import backup

1. Export: client bundles encrypted vault items + wrapped keys + recipient metadata into a
   single file, still encrypted, downloadable by the user.
2. Import: user uploads the bundle on a new/restored account, provides password or Recovery
   Key to unwrap, client re-encrypts and re-uploads under the current account's key hierarchy.

## Flow 10 — Trusted devices & session management

1. Dashboard → Security → Devices.
2. List of active sessions: device/browser fingerprint summary, IP (approximate location),
   last active time, "This device" tag.
3. User can revoke any session except (optionally) the current one without re-auth; revoking
   the current one requires re-entering the password.
