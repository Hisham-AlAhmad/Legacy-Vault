# 00 — Project Vision

## 1. What Legacy Vault is

Legacy Vault is a privacy-first digital legacy platform. A user prepares encrypted messages,
files, and instructions for specific people in their life. Nothing is delivered while the user
is alive and checking in. If the user stops checking in — because they died, or became
incapacitated, or simply vanished — and a grace/verification process confirms this isn't a
false alarm, the platform delivers each recipient exactly the content addressed to them, and
nothing else.

It is, functionally, a dead man's switch wrapped around an encrypted vault, with a delivery
system on the other end.

## 2. Who it's for

- People who want to leave final messages, instructions, passwords, or files for family
  without relying on a lawyer, a sealed envelope, or a relative remembering where "the
  folder" is.
- People who want each recipient to get only what's meant for them — a message to a spouse
  is not visible to a sibling.
- People who are not going to trust a company with plaintext access to what they write, and
  want that trust minimized by design, not by policy promise.

It is explicitly **not**:

- A legal will-replacement (it may attach a reference to a will, but it isn't one).
- A general-purpose encrypted cloud drive.
- A social or memorial platform.

## 3. Core principles (non-negotiable, in priority order)

1. **Privacy first.** Default to the most private option at every design decision, even when
   it costs convenience.
2. **Honesty about the security model.** We do not claim guarantees we can't back up. See the
   note on zero-knowledge vs. zero-access below — this is a direct consequence of this
   principle.
3. **Reliability of delivery.** A vault that is perfectly encrypted but fails to deliver when
   the user dies has failed at its one job. Reliability is treated as a security property, not
   just an availability nice-to-have.
4. **Excellent, calm UX.** This product deals with death. The interface should feel considered
   and dignified, not like a generic SaaS dashboard with a dark theme bolted on.
5. **Bilingual from day one.** English and Arabic (RTL) are both first-class, not
   English-plus-a-translation-layer.
6. **Self-hostable on modest infrastructure.** Runs on Hostinger shared hosting. Every
   architectural choice respects this constraint rather than assuming it away.
7. **Minimal recurring cost.** Optional paid features (SMS) are opt-in and clearly costed;
   the core product does not require them.

## 4. The central tension this project is built around

Legacy Vault's spec calls for "zero-knowledge architecture" and also for automatic delivery
of **decrypted, readable content** to a recipient after the user is gone, with no user present
to unlock anything at that moment. These two requirements are in direct tension:

- True zero-knowledge means the server never has the ability to decrypt user content, under
  any circumstances.
- Automatic decrypted delivery means that, at some point, *something the server controls* has
  to be able to decrypt the vault without the user's password.

You cannot have both in the strict sense. After clarifying this directly, the project adopts
what we call a **zero-access architecture**, not a zero-knowledge one:

- During normal operation (any time the user is alive and active), the server cannot read
  vault content. Content is encrypted client-side with a key the server never sees in
  plaintext form.
- The server does hold a wrapped copy of the vault key, itself encrypted at rest with a
  server-held key, that can only be unwrapped through one single, heavily audited code path:
  the verified delivery trigger (missed check-ins → grace period → emergency-contact step,
  all satisfied). See `13_Delivery_Engine.md` and `06_Cryptography.md`.
- This is a deliberate, documented trade-off, not an oversight. Every security and crypto
  document in this handbook states it plainly rather than using "zero-knowledge" language the
  system doesn't actually satisfy.

This single decision is why `docs/19_Decision_Log.md` opens with it, and why it's referenced
from nearly every other document. Get this wrong in an implementation and the entire security
posture of the product is misrepresented to users.

## 5. What "done" looks like for v1

A user can, without help:

1. Create an account.
2. Add one or more recipients, each with their own private content.
3. Write messages and attach files (up to the storage limit) addressed to specific recipients.
4. Choose a check-in interval and set up an emergency contact.
5. Understand — because the UI tells them plainly — what happens if they stop checking in.
6. Trust that if they die, their recipients get exactly what was meant for them, delivered
   reliably, without needing technical help.
7. Trust that if their account or the database were compromised while they're alive, an
   attacker could not read their vault content.

## 6. Non-goals for v1

- No legal will drafting or notarization.
- No social features (no recipient accounts required to view content sent to them, beyond
  what's needed for delivery).
- No mobile native app (PWA covers the mobile case for v1).
- No multi-currency billing / complex subscription tiers (SMS is the only paid add-on, billed
  simply).

## 7. Success criteria

- Zero known path for a database-only leak to expose plaintext vault content (see
  `18_Threat_Model.md`, scenario 1).
- Check-in and delivery engine has no missed-trigger or false-positive-delivery incidents in
  testing across all documented edge cases (see `11_CheckIn_Engine.md` §Edge Cases).
- A new developer (or Claude, in VS Code) can implement any phase by reading `docs/` alone,
  without needing to ask what a feature is supposed to do.
