# 16 — Coding Standards

## 1. PHP / Laravel

- PSR-12 formatting, enforced via Laravel Pint in CI.
- Strict types (`declare(strict_types=1)`) in every new file.
- No business logic in Eloquent models beyond relationships/casts/simple accessors — logic
  lives in Actions/Services (`09_Backend_Architecture.md` §1).
- Controllers are thin (`09_Backend_Architecture.md` §2) — a controller method over ~15 lines
  is a signal logic has leaked into it.
- Every public method on a Service/Action has a docblock stating intent, not just parameter
  types the IDE already shows.
- No `env()` calls outside `config/*.php` files — always read config, never `env()` directly
  in application code, so config caching (`14_Deployment.md` §4) works correctly.

## 2. React / TypeScript

- Strict TypeScript (`strict: true`), no `any` without an explicit inline justification
  comment.
- Function components + hooks only, no class components.
- One component per file, styling in a separate file (`10_Frontend_Architecture.md` §2,
  `AI_RULES.md` #17).
- Props typed explicitly, no implicit `any` props, default values for optional props.
- No direct `fetch` calls inside components — always through the TanStack Query hook layer in
  `lib/api`.

## 3. DTO conventions

Backend DTOs: readonly PHP classes (or Data objects via a library like spatie/laravel-data),
one per request/response shape, named `{Resource}RequestDto` / `{Resource}ResponseDto`.
Frontend: TypeScript types generated or manually mirrored from the API spec
(`08_API_Specification.md`), kept in `lib/api/types.ts`.

## 4. Naming conventions

- Database: `snake_case` tables/columns, singular model names mapping to plural table names
  (Laravel convention).
- API JSON: `snake_case` keys (matches Laravel's default, avoids a transform layer).
- TypeScript: `camelCase` variables, `PascalCase` components/types — a thin mapping layer at
  the API client boundary converts `snake_case` JSON to `camelCase` TS objects, not scattered
  ad hoc through the codebase.

## 5. Dependency policy

- New dependencies require a one-line justification in the PR (what it does, why the
  standard library/existing dependency isn't enough).
- Security-sensitive dependencies (anything crypto-adjacent) require it to be a
  widely-audited, actively maintained library — no cryptography dependency added without
  explicit discussion, ever (`AI_RULES.md` #6 spirit extended to tooling choice).
- Automated CVE scanning in CI (`15_Testing.md` §8); a critical CVE blocks merge until
  resolved or explicitly waived with a documented reason and expiry date.

## 6. Git / PR conventions

- Conventional commit-style messages (`feat:`, `fix:`, `docs:`, `refactor:`, `chore:`).
- PRs touching `app/Services/Crypto` or `app/Services/Delivery` require a second reviewer
  (`09_Backend_Architecture.md` §7).
- A PR that changes behavior without updating the corresponding `docs/*.md` file is
  incomplete, per `AI_RULES.md` #9 — reviewers check for this explicitly.

## 7. i18n synchronization

Every PR adding a user-facing string must add both `en` and `ar` keys in the same diff
(`AI_RULES.md` #10). A linked CI check fails on any key mismatch between locale files.

## 8. Accessibility conventions

Every new interactive component ships with keyboard support and correct ARIA roles as part of
"done," not a follow-up ticket — reviewed against `03_UI_UX_Guidelines.md` §6 in every PR
touching `components/` or `features/*/components/`.

## 9. Comments & documentation in code

Comments explain *why*, not *what* (the code already says what). Anything implementing a rule
from this handbook references the specific doc/section in a comment (e.g.
`// see 06_Cryptography.md §5 — only this service may read the SWK`) so future readers aren't
left reverse-engineering intent.
