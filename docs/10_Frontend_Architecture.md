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

**Vault items (Phase 2b)** — `src/crypto/items.ts`, a higher-level contract on top of the
primitives above, so no feature ever assembles item ciphertext by hand:

```
createItem(plaintext, masterKey, { userId, recipientId }) -> { itemId, encrypted }
rotateItem(plaintext, masterKey, { userId, recipientId, itemId }) -> encrypted
decryptItem(encrypted, masterKey, context) -> { title, body }      // throws ItemIntegrityError
decryptItemTitle(header, masterKey, context) -> title               // throws ItemIntegrityError
deriveContentWrapKey(masterKey) -> bytes
itemAad(part, context) -> bytes
```

There is deliberately no "encrypt with this existing key" function: saving an edit *is*
`rotateItem()`, so a content key can't outlive one version of an item
(`06_Cryptography.md` §10). The decrypt functions have no `null` return — a failed GCM tag
check is a thrown `ItemIntegrityError`, which the UI shows as an integrity warning and
which blocks the editor from opening (`06_Cryptography.md` §9).

**Attachments (Phase 2c)** — `src/crypto/attachments.ts` (`06_Cryptography.md` §12; the
earlier `encryptFileStream` sketch above was replaced by this and removed):

```
prepareAttachment(file, { name, type }, masterKey, itemContext)
    -> { attachmentId, header, sizeBytes, chunkCount, encryptChunk(i), dispose() }
decryptAttachmentMetadata(header, masterKey, context) -> { filename, mimeType }   // throws AttachmentIntegrityError
decryptAttachment(byteStream, info, masterKey, context, onProgress?)
    -> { filename, mimeType, chunks }                                            // throws AttachmentIntegrityError
attachmentAad(part, context, chunk?) -> bytes
ciphertextSizeFor(n), chunkCountFor(n), plaintextSizeOf(size, count)
normalizeFilename(name), normalizeMimeType(type)
createBundle(title, masterKey, owner) / rotateBundle(title, masterKey, context)  // items.ts, title-only items
```

`encryptChunk(i)` reads and encrypts one 1 MiB slice on demand, so an upload never holds
the whole file encrypted in memory; `decryptAttachment` consumes the download stream chunk
by chunk and wipes everything already decrypted if a later chunk fails. Orchestration lives
in `features/vault/attachmentsApi.ts` (declare → chunks with one resync-and-retry → `DELETE`
on cancel or failure) and `trashApi.ts`; the API client has `apiUploadBytes` (raw
`application/octet-stream` POST) and `apiStream` (streamed GET). A decrypted file is only
ever handed to the browser as an `application/octet-stream` Blob download under its
re-normalized name — never rendered in the app's origin. The storage limit and undo window
come from `GET /vault/storage` and are never constants in the client.

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

## 7a. Rich text: editor and sanitizer (Phase 2b)

- **Sanitizer:** `features/vault/sanitizeHtml.ts` — DOMPurify with the allow-list in
  `05_Security_Architecture.md` §9. Called in exactly one layer, `features/vault/itemsApi.ts`:
  on the editor's HTML before `createItem`/`rotateItem`, and on the output of `decryptItem`
  before it is returned to any component. Components never see unsanitized stored HTML,
  and nothing uses `dangerouslySetInnerHTML`.
- **Editor:** `features/vault/RichTextEditor.tsx` — TipTap 3 (`19_Decision_Log.md` Decision
  #16), restricted to the allow-listed node types, `injectCSS: false` (its base rules are
  in `RichTextEditor.css`, so the CSP needs no exception), `textDirection: 'auto'` so each
  block resolves its own direction. The toolbar follows the WAI-ARIA toolbar pattern: one
  tab stop, arrow keys between buttons (mirrored in RTL), `aria-pressed` state. It is only
  imported by `ItemEditorPage`, which is a lazily loaded route chunk.
- **Routes:** `/recipients/:recipientId/messages` (list), `…/messages/new` and
  `…/messages/:itemId` (editor). All three render inside `VaultUnlockGate`.
- **Wrong-recipient guard:** `WritingToBanner` is sticky at the top of the editor
  (`03_UI_UX_Guidelines.md` §7), and the AAD context used to decrypt is built from the
  route's recipient ID rather than the one the server reports — an item filed under the
  wrong recipient shows as unreadable instead of as that recipient's.
- **Cache:** list entries hold decrypted titles under `['items', userId, recipientId]`;
  the single-item query (`['item', userId, itemId]`, decrypted body) has `gcTime: 0`, so a
  message body leaves the cache when the editor closes. Every mutation invalidates the
  list key.
- **Tests:** Vitest runs in Node by default; the sanitizer test opts into a real DOM with
  `// @vitest-environment jsdom`.

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
