# 11 — Check-In Engine ("Dead Man's Switch")

## 1. State machine

```
   active ──(missed due date)──► grace_period ──(grace elapses)──► emergency_verification
     ▲                               │                                      │
     │                               │(user checks in)                     │(EC confirms alive)
     └───────────────────────────────┴──────────────────────────────────────┘
                                                                              │
                                                              (EC doesn't respond / can't confirm)
                                                                              ▼
                                                                    delivery_triggered (terminal)

     active ──(user pauses, e.g. temporary opt-out)──► paused ──(user resumes)──► active
```

States (matches `checkins.state` enum, `07_Database_Design.md`):

- **active** — normal operation. Reminders sent per §2 schedule.
- **grace_period** — due date passed, no check-in yet. Escalating reminders, still
  user-recoverable with a single check-in action.
- **emergency_verification** — grace period elapsed. Emergency contact notified, given a
  window to confirm the user is alive.
- **delivery_triggered** — terminal. `DeliveryTriggerService` has run or is running. No path
  back to `active` from here — this is intentional; a terminal state that could be silently
  reversed would undermine trust in the "content actually gets delivered" guarantee (Core
  Principle #3 in `00_Project_Vision.md`).
- **paused** — user-initiated (e.g. explicitly pausing the whole feature without deleting
  their vault) — not part of the automatic escalation path.

## 2. Reminder schedule (v1 defaults, configurable per interval type)

| Phase | Timing | Channels |
|---|---|---|
| Pre-due reminder | 7 days before due (or 20% of interval for short/custom intervals, whichever is smaller) | Push, Email |
| Pre-due reminder (final) | 1 day before due | Push, Email |
| Grace period start | At due date + 0 | Push, Email, SMS (if enabled) |
| Grace period escalation | Grace + 33%, + 66% of grace window | Push, Email, SMS (if enabled — see note below) |
| Emergency verification start | Grace window elapsed | Email + SMS to emergency contact (both, regardless of the *user's* notification preferences — this is the emergency contact's own channel availability) |
| Emergency verification reminder | Halfway through verification window | Repeat to emergency contact |
| Delivery trigger | Verification window elapsed with no confirmation | N/A — hands off to `13_Delivery_Engine.md` |

**Grace-period SMS note:** per `02_User_Flows.md` Flow 4 step 2, grace-period escalation may
use SMS even if the user hadn't opted into routine SMS reminders, because the stakes of a
missed grace period (approaching an irreversible trigger) justify the extra channel — this is
recorded as a deliberate judgment call in `19_Decision_Log.md`, and is disclosed to the user
plainly during check-in setup ("if you miss check-ins, we'll try harder to reach you,
including by SMS, even if you didn't opt into routine SMS reminders").

## 3. Default windows by interval

| Interval | Grace window | Emergency verification window |
|---|---|---|
| 1 week | 3 days | 4 days |
| 1 month | 7 days | 7 days |
| 6 months | 14 days | 10 days |
| 1 year | 21 days | 14 days |
| Custom | max(3 days, 10% of interval) | max(4 days, 10% of interval) |

Rationale: shorter check-in intervals imply a more engaged/available user, so grace windows
scale sub-linearly rather than as a fixed fraction — a fixed 10% of "1 week" would be too
short to accommodate a genuine short absence.

## 4. The sweep (implementation anchor)

`EvaluateCheckIns` runs every 5 minutes (`09_Backend_Architecture.md` §6). For each `checkins`
row where `next_due_at <= now()` (indexed query), it dispatches the appropriate transition job
based on current `state` and elapsed time — never mutates state inline, so the transition
logic is unit-testable in isolation from the scheduler (`15_Testing.md`).

## 5. Idempotency

Every transition job checks whether the target state has already been reached (e.g. a
`grace_period` transition job checks `state !== 'grace_period'` before acting) before
performing work, so a cron-tick overlap or a retried job never double-sends escalation
notifications or, critically, never attempts to re-run `delivery_triggered` transition logic
twice.

## 6. Edge cases

- **User checks in during the exact sweep window a transition job is mid-flight:** the
  check-in write and the transition job both go through the same `checkins` row with a
  version check (optimistic locking via `updated_at`/a `version` column) — whichever commits
  second re-validates state before proceeding; a check-in always wins over an in-flight
  escalation if it lands first in the transaction order.
- **User deletes their account mid-grace-period:** account deletion is treated as an explicit
  terminal action that cancels the check-in engine entirely (state irrelevant after deletion)
  — no dangling grace period continues against a deleted account.
- **Emergency contact is unreachable (bounced email, disconnected phone) for the entire
  verification window:** by design, this reads as "cannot confirm alive" and proceeds to
  delivery. This is disclosed to the user at setup time — an unreachable emergency contact is
  effectively no emergency contact at all, and the UI should nudge users whose emergency
  contact's email has started bouncing (a routine deliverability signal any transactional
  email provider makes available) to update it.
- **User has no emergency contact configured at all:** grace period elapsing goes straight to
  `delivery_triggered` — this must be a loud, explicit, un-skippable warning during setup
  (`03_UI_UX_Guidelines.md`), not a silent default.
- **Clock/timezone issues:** all `checkins` timestamps stored and computed in UTC; user-facing
  display converts to the user's browser timezone — server-side logic never depends on a
  client-supplied timezone for anything that affects state transitions.
- **Maintenance mode / planned downtime:** see §7.

## 7. Maintenance Mode Handling

Planned downtime (deploys, migrations) must not cause `next_due_at` to be silently exceeded
while the cron sweep isn't running, misread later as a missed check-in. Procedure: before
entering maintenance mode, a one-off job extends every `active`/`grace_period` row's relevant
deadline by the planned downtime duration, and reverts any such extension explicitly is not
needed since the extension is just added buffer — logged as
`checkin.maintenance_window_extended` in `audit_logs` for transparency. This is a manual
deploy-checklist step until automated in `14_Deployment.md`'s CI pipeline.

## 8. Manual overrides (admin)

Admin tooling (`08_API_Specification.md` §5) may need to pause an individual account's
check-in engine (e.g. a confirmed false-positive incident under investigation) — always
audit-logged with `actor_type = admin` and a required reason field, never a silent override.
