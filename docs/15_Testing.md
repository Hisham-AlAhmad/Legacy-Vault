# 15 — Testing Strategy

## 1. Layers

| Layer | Tooling | Scope |
|---|---|---|
| Unit | PHPUnit/Pest (backend), Vitest (frontend) | Actions, Services, crypto module functions, DTO mapping |
| Feature/Integration | PHPUnit + Laravel's HTTP testing | Full request→response per endpoint, including auth/policy/validation |
| E2E | Playwright | Full user flows (`02_User_Flows.md`) across real browser, including RTL rendering |
| Security | Manual + automated (see §5) | OWASP checklist (`05_Security_Architecture.md` §24-25) |

## 2. Cryptography testing — fixed test vectors

Per `AI_RULES.md` #6, encryption is never disabled for tests. Instead:

- Fixed known-answer test vectors for Argon2id (both parameter sets, `06_Cryptography.md` §6)
  and AES-256-GCM (known key/nonce/plaintext/ciphertext/tag tuples) verify the implementation
  matches spec exactly, independent of any live key material.
- Round-trip tests: encrypt→decrypt for every content type (message, attachment chunk),
  including tamper tests (flip a bit in ciphertext or AAD, assert the GCM tag check fails and
  the failure is audit-logged per `06_Cryptography.md` §9).
- Cross-language vectors: the sealed-field format (`SealedFieldVectorTest` ⇄
  `crypto.test.ts`) and the vault item format (`VaultItemVectorTest` ⇄ `items.test.ts`)
  are each pinned by the same bytes on both sides — PHP/OpenSSL and WebCrypto must produce
  and open identical ciphertext, because the delivery trigger depends on it.
- Key-hierarchy tests: full registration→login→password-change→recovery flow tested against
  real WebCrypto/libsodium calls (in a test environment, with test-only SWK), asserting each
  wrapped-key relationship holds and that a wrong password/Recovery Key correctly fails to
  unwrap rather than silently producing garbage plaintext.

## 3. Check-in engine testing

State-machine transitions (`11_CheckIn_Engine.md` §1) are unit-tested exhaustively per
transition, plus property-style tests for the edge cases in §6 of that document (concurrent
check-in vs. transition job, unreachable emergency contact, no emergency contact configured).
Time-dependent logic uses an injectable clock (never `now()` called directly in the state
machine), so grace/verification windows can be tested without real wall-clock waits.

## 4. Delivery engine testing

`DeliveryTriggerService` tested against a full seeded account (multiple recipients, multiple
items, attachments) in an isolated test environment with test-only SWK and test provider
fakes (no real email/SMS sends in CI). Asserts: correct per-recipient scoping (recipient A
never receives recipient B's content — this is the single most important assertion in the
whole test suite), correct audit-log entries, correct behavior when one recipient's send fails
(§2 partial-failure handling in `13_Delivery_Engine.md`).

## 5. Security testing

- Automated dependency scanning (`16_Coding_Standards.md` §Dependency Policy) in CI.
- Static analysis for common issues (unescaped output, raw query construction) as a CI gate.
- Manual penetration-style review before each phase's launch, checked against
  `05_Security_Architecture.md` §25 and `18_Threat_Model.md` scenarios re-validated against
  the actual running implementation, not just the design doc.
- Rate-limit and lockout behavior explicitly load-tested, not just unit-tested in isolation.

## 6. Accessibility & i18n testing

- Automated axe-core checks in the E2E suite for every major screen, both `en` and `ar`
  locales.
- A CI check fails the build if any i18n key exists in one locale file and not the other
  (`10_Frontend_Architecture.md` §5).
- Manual RTL visual review for any new component before merge (automated checks catch missing
  keys and gross ARIA issues, not "does this actually look right mirrored").

## 7. Test data & fixtures

Seeded fixtures never use production-shaped real personal data. Synthetic recipients/content
only, including for Arabic-locale fixtures (real, natural Arabic sample text, not
machine-mistranslated placeholder text, since RTL/typography bugs often only show up with
real script).

## 8. CI gates before merge

- All unit + feature tests pass.
- No new i18n key mismatch.
- No new dependency with a known critical CVE.
- Crypto test vectors pass unchanged (a diff to expected test-vector output is treated as a
  red flag requiring explicit sign-off, not just "update the snapshot").

## 9. Pre-launch checklist (maps to `05_Security_Architecture.md` §25 and `17_Roadmap.md`)

Full E2E pass of every flow in `02_User_Flows.md`, backup/restore drill
(`14_Deployment.md` §6), key-rotation drill (`14_Deployment.md` §7) on a staging copy, and a
full check-in-to-delivery drill on a seeded test account with a compressed timeline (minutes
instead of weeks) to observe the entire state machine end-to-end before trusting it with real
users' real check-in intervals.
