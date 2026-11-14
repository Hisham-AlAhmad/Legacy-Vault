# 10 — Frontend Architecture (React + TypeScript)

## 1. Structure

```
src/
├── crypto/            # WebCrypto wrappers: key derivation, wrap/unwrap, AES-GCM helpers
│                       # This is the most security-critical directory in the frontend —
│                       # isolated so it's easy to review/audit as a unit
├── features/
│   ├── auth/
│   ├── vault/          # recipients, items, attachments, preview
│   ├── checkin/
│   ├── dashboard/
│   └── recovery/
├── components/         # Shared, dumb, presentational — logic in hooks/features, not here
├── hooks/
├── routes/             # React Router route definitions
├── i18n/               # en.json, ar.json — always edited together (AI_RULES.md #10)
└── lib/                 # API client (TanStack Query), storage helpers (NOT localStorage
                          # for anything auth/crypto-related — in-memory/session-scoped only)
```

## 2. Component file convention (styling separated)

Per the user's explicit preference and `AI_RULES.md` #17: component logic/markup and CSS are
never in the same file. Convention: `ComponentName.tsx` (markup + logic) paired with
`ComponentName.module.css` (or a dedicated Tailwind class-list file if using
Tailwind-only styling) — no `<style>` blocks, no CSS-in-JS, no inline `style={{...}}` beyond
truly dynamic values (e.g. a computed progress-bar width) that can't reasonably live in a
stylesheet.

## 3. State management

- **Server state:** TanStack Query exclusively — no separate global store duplicating what the
  API already gives us. Cache keys scoped per-user to avoid any cross-account leakage risk in
  a shared browser profile edge case.
- **Client/UI state:** React state/context, scoped to the feature that needs it. No global
  state library beyond what TanStack Query + React Context covers — deliberately avoided to
  keep the crypto-sensitive parts of the app easy to reason about.
- **Vault key material:** lives in a dedicated React context (`VaultKeyProvider`) backed by a
  plain in-memory variable (module-level, not `useState`, to avoid it ever being serialized by
  devtools state inspection more than necessary) — cleared on logout and on tab/session end.
  Never persisted to any storage API.

## 4. Routing

React Router, route guards for authenticated vs. public (signed-link) routes. Signed-link
routes (check-in confirm, emergency-contact confirm, delivery access) are intentionally
**separate route trees** from the authenticated SPA shell, so they can render a minimal,
dependency-light page without requiring a full app-shell/auth bootstrap — relevant since
emergency contacts and recipients never have accounts.

## 5. i18next setup

- Namespace-per-feature JSON files under `i18n/en/*.json` and `i18n/ar/*.json`, mirrored
  1:1 — a CI check (`16_Coding_Standards.md`) fails the build if a key exists in one locale
  and not the other.
- `dir="rtl"` toggled at the document root based on locale, driving the CSS logical-property
  layout described in `03_UI_UX_Guidelines.md` §5.

## 6. Client-side crypto module contract

`src/crypto/` exposes a small, deliberately narrow API so every feature calls the same vetted
primitives instead of reimplementing WebCrypto calls ad hoc:

```
deriveKeyFromPassword(password, salt, params) -> CryptoKey
deriveKeyFromRecoveryKey(recoveryKey, salt, params) -> CryptoKey
generateMasterKey() -> CryptoKey
wrapKey(key, wrappingKey) -> { ciphertext, nonce }
unwrapKey(ciphertext, nonce, wrappingKey) -> CryptoKey
encryptContent(plaintext, contentKey, aad) -> { ciphertext, nonce }
decryptContent(ciphertext, nonce, contentKey, aad) -> plaintext
encryptFileStream(file, attachmentKey) -> AsyncIterable<chunk>
```

All parameters (Argon2id cost, AES mode) are imported from a single constants file matching
`06_Cryptography.md` exactly — never inlined per call site, so a parameter change is a one-file
diff, not a search-and-replace across features.

## 7. Forms & validation

React Hook Form + a shared Zod schema per resource, mirrored conceptually with the backend's
Form Request rules (not code-shared across PHP/TS, but kept in sync manually and checked in
code review) so client-side and server-side validation never silently diverge in a way that
confuses users (client accepts, server rejects, with no clear reason shown).

*Implementation note (as built, Phases 1–2a):* forms use controlled React state rather than
React Hook Form (installed, not adopted). The shared-schema half of this section is in
place from Phase 2a — `features/vault/recipientSchema.ts` mirrors `StoreRecipientRequest`
and `RecipientReachability` — and server field errors are mapped back onto the same fields.
Adopting React Hook Form remains optional; the schema is the part that keeps client and
server in sync.

## 8. Accessibility implementation notes

Built on shadcn/ui primitives (Radix under the hood) specifically because they ship correct
keyboard/focus/ARIA behavior for complex components (dialogs, comboboxes) rather than
reimplementing that from scratch — reducing the surface area `16_Testing.md` accessibility
audits need to cover from first principles.

*Implementation note (as built, Phase 2a):* shadcn/ui isn't installed. The one complex
primitive needed so far, the modal (`components/Dialog.tsx`), is built on the native
`<dialog>` element: `showModal()` makes the rest of the page inert (focus trap), Escape
closes it, and focus returns to the trigger. Revisit Radix if comboboxes or menus are needed.

## 9. PWA

Vite PWA plugin, service worker caches static assets + a minimal app shell for offline
tolerance of read-only dashboard views (`NFR-5`). Explicitly does **not** cache or
service-worker-intercept any request carrying vault ciphertext or key material — the service
worker cache is scoped to static assets only, to avoid an entire new class of "where did that
ciphertext get cached" questions.

## 10. Build & deployment output

Vite production build emits static assets served directly by Hostinger's web server
(`04_System_Architecture.md` §3) — no Node runtime needed in production, keeping the shared
hosting footprint to PHP-FPM + static files only.
