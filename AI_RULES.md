# AI_RULES.md — Read This First

This file governs every AI-assisted session (Claude Desktop, Claude in VS Code, or any other
model) working on Legacy Vault. It is loaded mentally before any planning or coding task.
If a rule here conflicts with a convenience shortcut, the rule wins.

## How this file is used

- **Claude Desktop (architect mode):** consult this file when writing or revising anything in
  `docs/`. Never let a convenience shortcut in documentation contradict these rules.
- **Claude in VS Code / IDE (implementer mode):** read this file, then read the relevant
  `docs/*.md` files, before writing a single line of code. Implement exactly what the docs
  specify. If the docs are silent or ambiguous on something necessary to proceed, stop and
  flag it rather than inventing an approach — then propose a Decision Log entry.

## Non-negotiables

1. **Never store plaintext secrets.** Passwords, recovery keys, vault content, and attachment
   contents are never written to logs, error messages, database columns, cache, or session
   storage in plaintext. See `06_Cryptography.md`.
2. **Never use `localStorage` or `sessionStorage` for authentication tokens.** Session tokens
   live in `HttpOnly`, `Secure`, `SameSite=Strict` cookies only. See `05_Security_Architecture.md`.
3. **Always use prepared statements / the query builder or Eloquent ORM.** Raw string
   interpolation into SQL is forbidden, no exceptions, no "just this once."
4. **Always use DTOs at the API boundary.** Controllers never accept raw `$request->all()`
   into business logic and never return raw Eloquent models directly. See
   `09_Backend_Architecture.md`.
5. **Always validate every incoming request** with a dedicated Form Request / validation class.
   No inline ad-hoc validation in controllers.
6. **Never bypass encryption "temporarily for testing."** Test against real encryption using
   fixed test vectors (see `15_Testing.md`), not a disabled-crypto code path. There is no
   `ENCRYPTION_DISABLED` flag, ever.
7. **Never hard-code secrets, keys, or credentials.** Everything sensitive comes from
   environment variables, documented in `14_Deployment.md`. No secret is ever committed to git.
8. **Always update tests alongside code.** A PR that changes behavior without a corresponding
   test change is incomplete.
9. **Always update documentation alongside code.** If an implementation detail changes from
   what a `docs/*.md` file says, update the doc in the same change and note it, so docs never
   silently drift from reality.
10. **Always keep Arabic and English content synchronized.** Every user-facing string added to
    the English locale file gets a same-key entry in the Arabic locale file in the same change.
    A missing translation key is a bug, not a follow-up task.
11. **Always write accessible components.** Semantic HTML, ARIA where semantic HTML isn't
    enough, keyboard navigability, visible focus states, and RTL-correctness for every new UI
    component. See `03_UI_UX_Guidelines.md`.
12. **Never break backward compatibility** of a shipped API endpoint or database migration
    without an explicit versioning/migration plan documented in `08_API_Specification.md` or
    `07_Database_Design.md`.
13. **Never assume the server can silently decrypt user vaults "just to check something."**
    The server may only ever decrypt vault content through the single, audited delivery-trigger
    code path described in `13_Delivery_Engine.md`. Every other code path treats vault content
    as opaque ciphertext.
14. **Always log security-relevant events to the audit log** (login, password change, recovery
    key use, recipient changes, check-in, delivery trigger, admin action) — never to the general
    application log. See `05_Security_Architecture.md`.
15. **One phase at a time.** Do not start Phase 2 work while Phase 1 has open, unresolved items.
    Review a phase against `01_Product_Requirements.md` and `17_Roadmap.md` before moving on.
16. **This project runs on Hostinger shared hosting.** Do not design or implement anything that
    assumes a persistent daemon, Redis, WebSockets, or long-running processes unless
    `14_Deployment.md` has been updated to reflect an infrastructure upgrade. Default to
    cron-driven, stateless, database-backed patterns. See `09_Backend_Architecture.md` and
    `11_CheckIn_Engine.md`.
17. **CSS lives in its own files, never inline in the same file as markup/logic.** Component
    styles are separate Tailwind/CSS files or Tailwind utility classes in markup only —
    no `<style>` blocks embedded alongside component logic, and no CSS-in-JS.

## Reading order for a new session

1. `AI_RULES.md` (this file)
2. `docs/00_Project_Vision.md`
3. `docs/01_Product_Requirements.md`
4. Whichever numbered doc covers the area being touched (e.g. touching auth → read
   `05_Security_Architecture.md` and `06_Cryptography.md` in full before writing code)
5. `docs/16_Coding_Standards.md`

## When documentation and instinct disagree

The docs in `docs/` are the source of truth, not general best practice, not what a similar SaaS
app does, not what's fastest to ship. If something in the docs seems wrong, say so explicitly
and propose a change — don't silently implement something different.
