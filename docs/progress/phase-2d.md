# Phase 2d — Preview as recipient, and integrity events: session log

Date: 2026-11-21 · Scope: `docs/17_Roadmap.md` sub-phase 2d only (VAULT-8, DELIV-5 groundwork,
`06_Cryptography.md` §9/§15/§16, `13_Delivery_Engine.md` §8). Dashboard (2e) and
export/import (2f) were not started.

## 1. Outcome

The owner can open **Preview as [recipient]** from the recipient card or the recipient's
messages page. The page shows exactly what that person will receive: the messages and
file collections in delivery order, each message body, and each completed file with a
download button. To build it, the browser fetches the stored ciphertext again and
decrypts it, including **every chunk of every file**. A broken ciphertext is therefore
caught by the owner now, not by a grieving recipient later. Anything that fails its
check is shown as "would not be delivered", and one damaged entry does not hide the
others. Every client-side decryption failure, on this page or anywhere else in the app,
is now recorded in the audit log.

The page renders content through `RecipientContentView`, the component the Phase 3
recipient access page will mount unchanged.

| Check | Result |
|---|---|
| Backend tests | **108 passed**, 1,232 assertions (92 before + 10 `IntegrityEventTest` + 6 `PreviewContractTest`) |
| Frontend tests | **122 passed** (93 before + 5 reporter + 9 owner source + 6 renderer + 8 boundary + 1 locale parity) |
| `npx tsc --noEmit` / `npm run build` | clean / clean |
| `npm run lint` | 0 errors; the same 3 pre-existing warnings |
| `npm audit` | 0 vulnerabilities; no dependency added on either side |
| Browser check, dev server (en + ar, scratch SQLite) | 26/27; the one failure was a timing bug in the test script, fixed |
| Browser check, production build under the exact CSP header | **27/27**, zero `securitypolicyviolation` events, zero console errors |

## 2. Process

- Baseline confirmed first: backend 92 (1,085 assertions), frontend 93, build clean, lint
  0 errors / 3 warnings.
- Plan written, then reviewed by an advisor before any code. Changes adopted from the
  review:
  - **Props-only renderer.** It does not load its own data, so the Phase 3 page needs no
    query client.
  - **Files are verified chunk by chunk,** not just listed.
  - **The event budget counts recorded events,** not requests.
  - **Dedup fingerprint without `surface`.**
  - **`recipient_id` from the client only for recipient sealed fields,** and checked
    against the user's own rows.
- Decisions #30–#31 and the spec updates (`05`, `06`, `08`, `10`, `13`, `15`) were
  committed **before** code (`efd6847`).
- Order: backend endpoint + tests → client reporter + hooks → renderer + preview → browser
  check → docs.

## 3. Decisions (`docs/19_Decision_Log.md`)

- **#30 — Renderer boundary.**
  - **Renderer:** `features/recipientView/RecipientContentView` takes a ready
    `RecipientContent` value and a `DownloadFile` callback. It never decrypts, fetches
    or reads keys. Its imports are pinned by an allow-list test.
  - **Raw HTML:** `SanitizedHtml` is the only `dangerouslySetInnerHTML` in the app, and
    it runs `sanitizeHtml()` itself on every render.
  - **Owner source:** `features/vault/preview/ownerContentSource.ts` builds the value
    from fresh ciphertext. The AAD context is the recipient being previewed.
  - **Phase 3** adds a second source that produces the same shape.
- **#31 — Integrity events.**
  - **Endpoint:** `POST /vault/integrity-events` takes 1–10 events. Each event is
    `kind`, `part`, `reason`, `surface` (all enumerated) plus the IDs its kind needs.
    There is no free text.
  - **Ownership:** IDs must be the caller's own rows, soft-deleted included. For items
    and attachments, the recipient is read from the database row.
  - **Bounds:** 30 requests/hour, 50 recorded events/day and a 24 h dedup per
    (kind, IDs, part).
  - **Labelling:** entries are `actor_type: user`, `event_source: client`. The text says
    plainly that the server cannot verify a report, only bound it.

No promised security property was weakened, so no owner decision was needed. The one
new exposure is sanitized, user-written HTML now rendered inside the authenticated
origin. Decision #16 planned for it. It is covered by the allow-list, the render-time
second pass and the CSP.

## 4. What was built

### Backend

| File | What |
|---|---|
| `app/DTOs/Vault/IntegrityEventData.php` | enumerations (kinds, parts per kind, reasons, surfaces), which IDs each kind needs |
| `app/Http/Requests/ReportIntegrityEventsRequest.php` | shape validation + part/kind and ID-set checks; `toEvents()` reads validated fields only |
| `app/Actions/Vault/RecordIntegrityEventsAction.php` | ownership resolution (all-or-nothing), dedup via `Cache::add`, event budget via `RateLimiter`, audit rows |
| `app/Http/Controllers/Api/V1/IntegrityEventController.php` | `202 { recorded }` |
| `routes/api.php`, `AppServiceProvider` | route in the active-account group, limiter `integrity-reports` |
| `config/legacyvault.php` | `integrity_events.{requests_per_hour, max_per_request, daily_budget, dedup_hours}` |
| `app/Services/AuditLogger.php` | allow-list + `kind`, `part`, `surface` |
| `lang/{en,ar}/legacyvault.php` | three validation messages |

### Frontend

| File | What |
|---|---|
| `crypto/integrity.ts` | `IntegrityReason` and the compile-checked map from each error detail to its reason |
| `crypto/items.ts`, `crypto/attachments.ts` | errors carry `reason` (constructors only accept known details) |
| `features/vault/integrityReporter.ts` | per-tab dedup, 250 ms batching, ≤ 10 per request, errors swallowed, never retried |
| `itemsApi`, `attachmentsApi`, `trashApi`, `vaultApi` | every decrypt site reports (`list`, `editor`, `download`, `trash`, `recipients`) |
| `features/recipientView/{types.ts, RecipientContentView, ContentEntryView, ContentFileRow, SanitizedHtml}` (+ CSS) | the shared renderer |
| `features/vault/preview/{ownerContentSource.ts, PreviewPage.tsx, PreviewPage.css}` | owner path, page with recipient picker and owner-only banner, `staleTime/gcTime: 0` |
| `routes/index.tsx` | lazy route `/recipients/:recipientId/preview` |
| `RecipientCard`, `RecipientItemsPage` | "Preview" / "Preview as [name]" links |
| `i18n/{en,ar}/recipientView.json`, `vault.json` `preview.*` | strings, en/ar in sync |
| `vite.config.ts` | Vitest now also picks up `*.test.tsx` |

## 5. API example

```http
POST /api/v1/vault/integrity-events
{ "events": [ { "kind": "item", "part": "item.content", "reason": "authentication_failed",
                "surface": "preview", "item_id": "b3aa6442-8239-4064-a31c-af706aba01a5" } ] }

202 { "recorded": 1 }
```

Resulting audit row (from the browser check):
`{"kind":"item","part":"item.content","reason":"authentication_failed","surface":"preview","item_id":"b3aa6442-…","recipient_id":2,"event_source":"client"}`,
`actor_type: user`.

## 6. Tests added

- **`PreviewContractTest` (PHPUnit, `FakeClientVault`).** Covers:
  - Live items come back in position order and decrypt.
  - A flipped tag bit fails, and its report produces the exact audit row.
  - Every encrypted column swapped between two items: both fail.
  - An item re-pointed at another recipient fails under that recipient, and the audit
    row names the recipient the row now has.
  - A file with its last chunk cut off fails, and is reported.
  - A hanging upload and a cancelled upload are never listed or downloadable.
- **`IntegrityEventTest` (PHPUnit).** Covers:
  - All three kinds are recorded, with the recipient taken from the server row.
  - A client-sent recipient on an item event is refused.
  - Free text and unknown keys never reach the log.
  - Part/kind and ID-set mismatches are refused, as are 0 or 11 events.
  - Another user's item, recipient, or attachment/item pairing is refused, and a mixed
    batch writes nothing.
  - Deleted rows can be reported.
  - Dedup holds within 24 h and lets a report through again after 25 h.
  - The daily budget is enforced, with nothing partial written.
  - The request limiter works, and the endpoint needs auth and an active account.
- **`ownerContentSource.test.ts` (Vitest, real WebCrypto, mocked API).** Covers:
  - The happy path, including a two-chunk file verified and the body sanitized.
  - A tampered body.
  - Items swapped with each other.
  - An item served under another recipient (no plaintext leaks into the result).
  - A truncated file and reordered chunks.
  - An unfinished upload left out.
  - A damaged item's files not fetched.
  - Network errors passed on.
- **`RecipientContentView.test.tsx`.** Covers:
  - Order, `dir="auto"` and bidi isolation.
  - Hostile HTML (script, `onerror`, `style`, `javascript:` and `iframe` are all removed;
    links are forced `noopener`).
  - Damaged entry and damaged file states.
  - Download success and failure.
  - Arabic rendering, and the greeting without a name.
- **`boundary.test.ts`.** Every renderer import is on the allow-list, and no file outside
  `SanitizedHtml.tsx` uses `dangerouslySetInnerHTML`.
- **`integrityReporter.test.ts`.** Covers:
  - A real AAD failure is reported as codes only, with no title, body or message.
  - One report per fingerprint across surfaces.
  - Batches of 10/10/3.
  - Auto-flush.
  - A 429 is swallowed and not retried.

## 7. Browser check (scripted with Playwright, scratchpad only, not in the repo)

It ran against a **scratch SQLite database** and a scratch attachments directory
(`DB_CONNECTION=sqlite DB_DATABASE=… ATTACHMENTS_DISK_ROOT=… bash backend/serve.sh`). The
owner's WSL MySQL dev database was not touched. First it ran on the Vite dev server, then
on the production build served with the exact CSP from `SecurityHeaders.php`.

The script performs these steps:
1. Register.
2. Add a recipient.
3. Write a mixed-direction message.
4. Create a collection and upload a two-chunk file.
5. Open Preview as Layla. Checks:
   - the heading is isolated;
   - the order is right;
   - the body is rendered with `dir="auto"`;
   - the file content was fetched once to verify it;
   - the download is byte-identical.
6. Flip one byte of the stored body in the database, then press **Check again**. Checks:
   - the entry shows "would not be delivered";
   - the body text is gone;
   - the summary warning appears and the other entry stays;
   - exactly one `integrity.check_failed` row exists;
   - after a reload and a visit to the list there is still one row (server dedup).
7. Switch to Arabic. Checks: `dir="rtl"`, translated heading, notice and banner.
8. Scan the database for the plaintext body or the filename: 0 hits.
9. No CSP violations and no console errors.

Screenshots were checked by eye in both languages.

## 8. Not done / deliberately left out

- **No inline viewing of files.** Files stay download-only (Decision #23). Rendering
  images or PDFs in the app's origin would need its own decision.
- **The preview cannot prove completeness.** It shows what the server lists. Someone with
  database write access could hide an attachment or item row, and the preview would
  simply not show it (Decision #24 known limit, `18_Threat_Model.md` Scenario 2).
- **Dedup and budget state live in the cache store.** It is the database cache in
  production.

## 9. Open questions, limitations, deferred work

- **Phase 3:** decide how the recipient's browser receives opened content
  (`13_Delivery_Engine.md` §3 describes a server-decrypted, TTL'd copy). Then build a
  second content source producing `RecipientContent`, and mount `RecipientContentView`
  in the signed-link route tree. The delivery trigger must apply the selection rules the
  preview shows: live items in position order, completed files only, damaged entries
  reported and skipped.
- **Ops note (Phase 4):** `artisan cache:clear` resets the integrity dedup and the daily
  budget. That only allows more bounded reports; nothing is lost.
- **Carried from 2c, unchanged:** row-lock concurrency is not load-tested (Phase 4);
  `ApiException`s are noise in `laravel.log`; Pint is not clean; the UI language resets
  on reload; account deletion must purge files first.

### Open questions for the owner

- None blocking. Possible later choice: whether the dashboard (2e) should surface recent
  `integrity.check_failed` entries to the owner, beyond the warning on the page where the
  failure was seen.

## 10. Commits

| Commit | Subject |
|---|---|
| `efd6847` | docs: resolve Phase 2d spec gaps (Decisions #30-#31) |
| `23eaf66` | feat: bounded endpoint for client-reported integrity failures |
| `5a59b13` | feat: report client-side integrity failures to the audit log |
| `d8b034b` | feat: preview as recipient through the shared recipient renderer |
| (this commit) | docs: Phase 2d session log, roadmap and README — tagged `phase-2d` |
