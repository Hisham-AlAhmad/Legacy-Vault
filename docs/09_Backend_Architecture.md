# 09 — Backend Architecture (Laravel)

## 1. Layered structure

```
app/
├── Http/
│   ├── Controllers/Api/V1/      # Thin — validate via Form Request, call an Action, return a DTO
│   ├── Requests/                # Form Request validation classes, one per endpoint
│   └── Middleware/               # Auth, rate limit, audit-context binding
├── Actions/                       # One class per use-case (e.g. CreateRecipientAction)
├── Services/
│   ├── Crypto/                    # SWK handling, ONLY DeliveryTriggerService may read it
│   ├── CheckIn/                   # State machine (11_CheckIn_Engine.md)
│   ├── Delivery/                  # DeliveryTriggerService (13_Delivery_Engine.md)
│   └── Notification/              # Channel dispatch (12_Notification_System.md)
├── DTOs/                          # Immutable data objects crossing the API boundary
├── Models/                        # Eloquent, thin — no business logic in models
├── Policies/                      # Authorization, one per resource
└── Console/Commands/              # Scheduled: check-in sweep, queue drain, cleanup jobs
```

## 2. Controllers are thin, by rule

A controller method: resolve the Form Request (validation already done), call exactly one
Action or Service method, map the result to a DTO, return it. No query logic, no business
rules, no direct Eloquent calls beyond what the Action returns. This keeps business logic
testable independent of HTTP (`15_Testing.md`).

## 3. DTOs at every boundary

Per `AI_RULES.md` #4: controllers never pass `$request->all()` into an Action; they pass a
typed DTO built from the validated Form Request. Actions never return an Eloquent model to a
controller for direct serialization; they return a response DTO. This means a database column
rename never silently changes the public API shape — the DTO is the explicit contract.

## 4. Policies for authorization

Every user-owned resource (`Recipient`, `VaultItem`, `Attachment`, `EmergencyContact`,
`CheckIn`) has a Laravel Policy scoping access to `user_id === $request->user()->id`. Route
model binding + policy authorization (`$this->authorize(...)`) on every controller method —
never a manual `if` check duplicated per controller (`05_Security_Architecture.md` §2).

## 5. Queue strategy on shared hosting

No persistent worker. Queue driver: `database`. The scheduler (`04_System_Architecture.md`
§5) runs `queue:work --stop-when-empty --max-time=50 --tries=3` once per cron tick, draining
whatever accumulated since the last tick. Implications for job design:

- Jobs must be idempotent (a job that runs twice due to a cron overlap edge case must not
  double-send a notification or double-trigger delivery — dedupe via a `notifications`/
  `audit_logs` existence check inside the job, not just queue semantics).
- Jobs must complete well within the cron tick's time budget — no job should assume it can run
  for minutes. Long-running work (e.g. sending to 50 recipients) is chunked into multiple
  small jobs rather than one large one.
- Failed jobs go to `failed_jobs` and are surfaced in monitoring
  (`05_Security_Architecture.md` §22), since there's no daemon restarting a crashed worker to
  notice for us.

## 6. The check-in sweep command

`app/Console/Commands/EvaluateCheckIns.php`, scheduled every 5 minutes
(`04_System_Architecture.md` §5, `NFR-2`). Queries `checkins` via the `(state, next_due_at)`
index (`07_Database_Design.md`), dispatches state-transition jobs — never mutates state
directly in the sweep command itself, so the actual transition logic lives in one testable
place (`CheckInStateMachine` service), not duplicated between the sweep and any manual
admin-triggered transition.

## 7. DeliveryTriggerService isolation

Physically a separate service class with a narrow public interface
(`executeDelivery(User $user): void`), the only class permitted to inject the SWK-reading
repository method (`06_Cryptography.md` §5). Code review checklist item
(`16_Coding_Standards.md`): any PR touching `app/Services/Crypto` or
`app/Services/Delivery` gets a mandatory second reviewer.

## 8. Validation layers

1. Client-side (fast feedback, not trusted).
2. Form Request (server-side, authoritative) — every field, every endpoint
   (`AI_RULES.md` #5).
3. Domain invariants inside Actions/Services where a Form Request can't express them (e.g.
   quota checks that require a DB aggregate).

## 9. Localization in the backend

- All user-facing strings the backend generates (email/SMS templates, error messages returned
  to the client) are resolved via Laravel's localization files, keyed the same as the frontend
  i18n keys where the string is shared, with `en`/`ar` maintained together
  (`AI_RULES.md` #10).
- `Accept-Language` / user's stored `locale` column picks the language for
  server-generated content (emails, SMS) — the frontend's own current-language selection
  covers UI strings independently.

## 10. Error handling

Centralized exception handler maps domain exceptions to the standard error envelope
(`08_API_Specification.md` §1). No stack traces or internal exception messages ever reach a
production response body; full detail goes to the application log only (never to the audit
log — `05_Security_Architecture.md` §16 vs §17 distinction).

## 11. Testing hooks

Services are constructor-injected (not facades-in-business-logic) so unit tests can substitute
fakes — particularly important for `Crypto`/`Delivery` services, which must be testable
against fixed test vectors (`15_Testing.md`) without hitting real provider APIs or real SWK
material in CI.
