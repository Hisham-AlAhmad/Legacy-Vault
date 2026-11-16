# Phase 2c — Attachments, quota, and soft-delete undo/purge: session log

Date: 2026-11-15 – 2026-11-16 · Scope: `docs/17_Roadmap.md` sub-phase 2c only (VAULT-6
files, VAULT-7, NFR-3, VAULT-9 undo; the hand-over list in Decision #18). Preview (2d),
dashboard (2e) and export/import (2f) were not started.

## 1. Outcome

A user can attach files to a message or keep them in a titled **file collection**. Each file
is encrypted in the browser under its own key, in chunks that can't be reordered, dropped
or truncated undetected; its name and type are encrypted too. The server stores ciphertext
under names it generates, enforces a 10 MB quota of stored ciphertext before accepting any
bytes, and never decrypts anything. Deleted recipients, items and files go to **Recently
deleted** for 30 days (restore, or erase now with the password), then an hourly job erases
them for good, files included.

| Check | Result |
|---|---|
| Backend tests | **92 passed**, 1,085 assertions (62 before 2c + 15 attachment + 8 trash/purge + 7 vector) |
| Frontend tests | **92 passed** (70 before − 1 superseded file-stream test + 23 attachment/bundle) |
| `npm run build` (tsc + vite) | clean |
| `npm run lint` | 0 errors; the same 3 pre-existing warnings |
| `npm audit` | 0 vulnerabilities; no dependency added on either side |
| Browser click-through, en + ar, reload → lock → unlock, against **WSL MySQL** | 34/34 checks (§8) |
| Same click-through on the production build under the exact CSP header | 34/34, zero `securitypolicyviolation` events |
| Exit check: raw DB + files hold no readable name, type or content | yes (§7.1) |
| Exit check: an upload over 10 MB is refused by the server | `413 QUOTA_EXCEEDED` (§7.2) |
| Exit check: item deleted > 30 days ago purged from DB and disk; one deleted yesterday restorable | yes (§7.3) |

## 2. Process

- Baseline confirmed first: backend 62, frontend 70, build/lint clean, audit 0.
- Decisions #21–#29 were written and the specs (`05`, `06`, `07`, `08`) updated **before**
  any code (`22f3858`). An Opus plan-critic agent then reviewed them adversarially against
  the code. It found no cryptographic hole but one blocker and several should-fixes. All
  were taken into the decisions before implementation:
  - **The blocker:** concurrent chunk writes could corrupt a file. Fix: row lock,
    fixed-offset writes, never an append helper.
  - Purge could race a restore. Fix: lock and re-check the row before acting.
  - A `.part` rename could orphan files. Fix: one fixed path per row.
  - Database cascades could leave files behind. Fix: `RESTRICT` foreign keys.
  - Erase-now needed step-up.
  - The filename needed a byte cap as well as a character cap.
  - The old `encryptFileStream` was superseded and has been removed.
- The session changed model midway (Fable → Opus at the owner's request); an advisor
  review before the UI caught two more gaps:
  - Bundles would have crashed the message editor.
  - Trashed attachments lacked the `recipient_id` needed to rebuild their AAD.
- The browser click-through found four real bugs, all fixed before tagging (§8).

## 3. Decisions (all in `docs/19_Decision_Log.md`)

| # | Gap | Decision | Why (short) |
|---|---|---|---|
| 21 | Attachment key model and AAD | Random 256-bit key per attachment, wrapped by the **Content-Wrap Key** (Decision #13); client-generated attachment UUID; AAD binds user, recipient, item and attachment | One wrapping key for the delivery trigger; the `attachment.key` label separates it from item keys; recipient binding stops misdelivery after a DB edit |
| 22 | Chunk format | 1 MiB plaintext chunks, each its own envelope (fresh nonce), AAD `…|chunk:{index}/{total}`; file = concatenated envelopes, chunk *i* at `i × 1,048,605`; max chunks derived from the quota | Index **and total** in the AAD make reorder, duplicate, drop, truncate, append and transplant all fail authentication |
| 23 | Where files live | Private `attachments` disk under `storage/app/private`, path `u{user}/{server UUID}.bin` fixed per row; served only by an authenticated route as `{uuid}.bin`, octet-stream, `no-store`; FKs `RESTRICT` | Nothing client-supplied is ever in a path; no file can outlive its row |
| 24 | Encrypted metadata | Filename and MIME sealed under the attachment key; MIME NUL-padded to 128 bytes (envelope always 157); filename ≤ 255 chars and ≤ 995 bytes | Padding hides the type from ciphertext length. Stated plainly: the server sees count, exact size and filename length |
| 25 | Upload protocol | **Declare** (reserves the size against the quota, `413` before any byte) → **chunks** strictly in order under a row lock, exact sizes → resume by asking → abort = `DELETE`; abandoned after 24 h; 20 uploads/hour; 20 files/item | The quota check must precede the bytes; exact sizes mean the reservation can't be exceeded |
| 26 | Quota accounting | Usage = sum of `size_bytes` of every existing row: complete, uploading, and **soft-deleted until purged**; one config value; reported with every relevant response | Deleted files still occupy disk; "erase now" exists so a full user isn't stuck for 30 days |
| 27 | `file_bundle` | Title + files, no body (`content_enc` NULL); attachments on either item type; delete/restore cascade by identical `deleted_at` | Same shape as recipient → items |
| 28 | Undo | 30-day Recently deleted list; restore brings back what was deleted *with* the entry; erase-now with **step-up password**; restore/erase lock and re-check | Attachments are immutable, so without step-up a stolen session would gain a permanent delete it didn't have |
| 29 | Purge | `vault:purge-deleted` hourly, batches of 100, 40 s budget; files first, rows second; system-actor audit with counts | Shared hosting: short cron jobs only (`AI_RULES.md` #16) |

None weakens a promised property, so none was escalated. Decision #18 is now ✅.

**Owner input this session:** the dev database is the WSL Ubuntu MySQL. The owner said to
build 2c there, keep it, and ignore the Windows (XAMPP) MySQL. `backend/.env`'s
`127.0.0.1:3306` already reached it through `wslrelay.exe`; the 2c migration was applied
there.

## 4. What was built

### Backend (`backend/`)

New:

| File | Purpose |
|---|---|
| `database/migrations/2026_11_15_100000_create_attachments_table.php` | `attachments` + `deleted_at` indexes on `recipients`/`vault_items` (§6) |
| `app/Models/Attachment.php` | Model, format constants (chunk size, envelope sizes), `expectedChunkBytes`, `isPossibleSize` |
| `app/Policies/AttachmentPolicy.php` | Owner **and** item **and** recipient chain live |
| `app/Services/Vault/AttachmentStorage.php` | The only code that touches files: server-generated paths, fixed-offset locked writes |
| `app/Services/Vault/StorageQuota.php` | The single place the quota is read; usage sums |
| `app/Services/Vault/UndoWindow.php` | One cutoff for restore and purge, so they can't disagree |
| `app/Services/Vault/AttachmentCascade.php` | Trash/restore attachments with their items by identical timestamp |
| `app/Services/Vault/VaultPurger.php` | Hard delete, files first |
| `app/Services/Vault/TrashPurger.php` | Lock + re-check + purge + audit — shared by cron and erase-now |
| `app/Actions/Vault/{DeclareAttachmentUpload,StoreAttachmentChunk,ListAttachments,DeleteAttachment}Action.php` | Upload/list/delete |
| `app/Actions/Vault/{ListTrash,RestoreRecipient,RestoreVaultItem,RestoreAttachment,EraseTrashed}Action.php` | Recently deleted |
| `app/Console/Commands/PurgeDeletedVaultData.php` | `vault:purge-deleted` |
| `app/DTOs/Vault/{AttachmentDto,NewAttachmentData,StorageUsageDto,ChunkReceiptDto,PurgeResult,TrashListingDto}.php` | DTOs |
| `app/Http/Requests/{DeclareAttachmentUpload,StoreAttachmentChunk,EraseTrashed}Request.php` | Validation (chunk headers; early 413 on oversize bodies) |
| `app/Http/Controllers/Api/V1/{Attachment,Trash}Controller.php` | Thin controllers |
| `tests/Feature/AttachmentTest.php` (15), `tests/Feature/TrashTest.php` (8), `tests/Unit/AttachmentVectorTest.php` (7), `tests/Support/BuildsVault.php` | Tests |

Modified: `VaultItem`/`User` (relations, `file_bundle` creatable), `DeleteRecipientAction`
and `DeleteVaultItemAction` (attachments cascade, `attachment_count`),
`ValidatesItemCiphertext` + item requests (`content_enc` prohibited for bundles),
`VaultItemCiphertext` (nullable body), `RecipientPolicy`/`VaultItemPolicy` (`restore`,
`forceDelete`), `AppServiceProvider` (`{attachment}`, `{trashedRecipient}`,
`{trashedItem}`, `{trashedAttachment}` bindings; `uploads` limiter), `AuditLogger`
(allow-list: `attachment_id`, `attachment_count`, `size_bytes`, `chunk_count`),
`config/legacyvault.php` (quota, attachments, trash), `config/filesystems.php`
(`attachments` disk), `routes/api.php`, `routes/console.php`, `lang/{en,ar}`,
`tests/Support/FakeClientVault.php` (PHP mirror of the attachment crypto),
`tests/Feature/VaultItemTest.php` (two expectations), `.env.example`.

### Frontend (`frontend/`)

New:

| File | Purpose |
|---|---|
| `src/crypto/attachments.ts` (+ `.test.ts`, 23 tests) | `prepareAttachment` (on-demand chunk encryption), `decryptAttachment` (streaming), `decryptAttachmentMetadata`, `attachmentAad`, filename/MIME normalisation, `AttachmentIntegrityError` |
| `src/features/vault/attachmentsApi.ts` | Declare → chunks (one resync-and-retry) → `DELETE` on cancel/failure; streamed download → octet-stream Blob |
| `src/features/vault/trashApi.ts` | List (decrypting with server-reported context, safe because GCM proves it), restore, erase |
| `src/features/vault/AttachmentList.tsx` (+ `.css`) | Upload with `<progress>` and cancel, file list, download, delete with undo, `beforeunload` guard |
| `src/features/vault/StorageMeter.tsx` (+ `.css`) | Native `<meter>` from the server's numbers |
| `src/features/vault/BundlePage.tsx` (+ `.css`) | File collection create/rename + files (own lazy chunk, 3.75 kB) |
| `src/features/vault/TrashPage.tsx` (+ `.css`) | Recently deleted: restore, erase with password |
| `src/features/vault/formatBytes.ts` | Sizes/dates, Western digits in both locales |

Modified: `crypto/constants.ts` (`ATTACHMENT_FORMAT`; `FILE_CHUNK_BYTES` removed),
`crypto/index.ts` (superseded `encryptFileStream` removed, with its test in
`crypto.test.ts`), `crypto/items.ts` (`createBundle`, `rotateBundle`, title-only
encryption), `lib/api/client.ts` (`apiUploadBytes`, `apiStream`, abort signals),
`lib/api/types.ts`, `itemsApi.ts` (item type, bundle API, `WrongItemTypeError`, editor never
opens a bundle), `vaultQueries.ts` (`useStorageInfo`, keys), `RecipientItemsPage` (type
label, "New file collection", undo), `ItemEditorPage` (files under a saved message),
`RecipientsPage`/`RecipientCard`/`DeleteRecipientDialog`/`DeleteItemDialog` (undo, the
30-day note from the server), `WritingToBanner` (alternative sentence), `routes/index.tsx`,
`AppLayout.tsx` (nav), `i18n/{en,ar}/vault.json` (~100 keys each, in the existing
namespace so the parity test covers them).

### Docs

`05` §3, §7, §11, §14, §17 · `06` §4, §9, §12, §14 · `07` vault_items, attachments,
Deletion strategy · `08` index + attachments + recently deleted · `10` §6 · `14` §3 ·
`15` §2 · `17` (2c Done, 2d Next, open items) · `18` scenario 1 · `19` (#18 ✅, #21–#29) ·
`README.md` · `CLAUDE.md` (baseline, WSL MySQL, rate-limit note) · this file.

## 5. API

All under `/api/v1`, Sanctum session, CSRF on writes, active account required. Foreign,
deleted or malformed IDs are `404 NOT_FOUND`. Full spec: `docs/08_API_Specification.md`.

| Method & path | Purpose | Audit |
|---|---|---|
| GET `/vault/storage` | `{ storage: { limit_bytes, used_bytes, trashed_bytes }, undo_window_days }` | — |
| GET `/vault-items/{uuid}/attachments` | Completed files + storage | — |
| POST `/vault-items/{uuid}/attachments` | Declare (reserves quota) — `throttle:uploads` | — |
| GET `/attachments/{uuid}` | One attachment incl. `received_chunks` (resume) | — |
| POST `/attachments/{uuid}/chunks` | Next chunk, raw octet-stream | `attachment.uploaded` on completion |
| GET `/attachments/{uuid}/content` | Ciphertext download | — |
| DELETE `/attachments/{uuid}` | Soft delete / abort upload | `attachment.deleted` / `attachment.upload_aborted` |
| GET `/trash` | Recently deleted (restorable only) | — |
| POST `/trash/{recipients|items|attachments}/{id}/restore` | Restore | `*.restored` |
| DELETE `/trash/{recipients|items|attachments}/{id}` | Erase now — `{ password }`, `throttle:step-up` | `*.purged` (`reason: user_requested`) |

Declare — request (envelopes shortened):

```json
POST /api/v1/vault-items/81a13eca-c4af-4b9d-9e22-18c35d102931/attachments
{ "id": "4e7f4504-11b3-4d62-8f22-9f54678f6242",
  "filename_enc": "AWRPhXkUvV+Jd4…", "mime_type_enc": "ARXkBHgRNLODO4…",
  "attachment_key_wrapped": "AZNTebl9vcLw7V…",
  "size_bytes": 2101640, "chunk_count": 3, "aad_version": 1 }
```

Response `201`:

```json
{ "attachment": { "id": "4e7f4504-…", "item_id": "81a13eca-…", "filename_enc": "…",
    "mime_type_enc": "…", "attachment_key_wrapped": "…", "size_bytes": 2101640,
    "chunk_count": 3, "received_chunks": 0, "status": "uploading", "aad_version": 1,
    "created_at": "2026-11-16T08:58:41+00:00", "updated_at": "…" },
  "storage": { "limit_bytes": 10485760, "used_bytes": 2101640, "trashed_bytes": 0 } }
```

Chunk — `POST /api/v1/attachments/4e7f4504-…/chunks` with
`Content-Type: application/octet-stream`, `X-Chunk-Index: 0`, `X-Chunk-Total: 3`,
`X-Upload-Id: 4e7f4504-…`, body = 1,048,605 raw bytes → `200 {"received_chunk":0}`. The
last chunk's response adds `"attachment": {…, "status": "complete"}` and `"storage"`.

Errors: `413 QUOTA_EXCEEDED`, `409 CHUNK_SEQUENCE_ERROR`, `409 ATTACHMENT_ID_CONFLICT`,
`403 ATTACHMENT_LIMIT_REACHED`, `413 PAYLOAD_TOO_LARGE` (chunk body over one full chunk),
`422 VALIDATION_FAILED`, `401 INVALID_CREDENTIALS` (erase with a wrong password).

## 6. Database

Migration `2026_11_15_100000_create_attachments_table` (applied to the WSL MySQL
`legacy_vault` database after reviewing `--pretend`; additive only):

```
attachments
  id                      BIGINT UNSIGNED PK       internal only
  uuid                    CHAR(36)                 client UUIDv4, public ID, upload ID, in AAD
  user_id                 FK → users  RESTRICT
  vault_item_id           FK → vault_items RESTRICT
  storage_path            VARCHAR(255)             u{user}/{server UUID}.bin, never returned
  filename_enc            VARBINARY(1024)          envelope
  mime_type_enc           VARBINARY(157)           envelope of the 128-byte padded type
  attachment_key_wrapped  VARBINARY(61)            envelope
  size_bytes              BIGINT UNSIGNED          ciphertext bytes (quota)
  chunk_count             SMALLINT UNSIGNED
  received_chunks         SMALLINT UNSIGNED DEFAULT 0
  completed_at            TIMESTAMP NULL           NULL = uploading
  aad_version             TINYINT UNSIGNED
  created_at, updated_at, deleted_at
  UNIQUE (user_id, uuid) · INDEX (vault_item_id) · INDEX (deleted_at)
+ INDEX (deleted_at) on recipients and vault_items
```

The `07` sketch changed: `uuid`, `user_id`, `received_chunks`, `completed_at`,
`aad_version`, `updated_at` added; column sizes fixed; FKs `RESTRICT` (the one exception to
`07`'s cascade convention, Decision #23).

## 7. Exit-check evidence

All on the WSL MySQL database through the real app (production build under the CSP, API on
`php -S 127.0.0.1:8031`). The database was read directly with PDO and the directory with
plain filesystem calls, bypassing Laravel. Test user `p2c-1794819507235@example.com`
(id 9), recipient "Layla Haddad", collections "House documents — أوراق البيت" and
"Yesterday's papers".

### 7.1 Nothing readable in the database or the files

```
== SELECT * FROM attachments WHERE user_id=9
-- id=17 uuid=4e7f4504-… item=81a13eca-… size_bytes=2101640 chunk_count=3 received_chunks=3
   storage_path=u9/1f997046-c216-4fd3-b445-f7c11bc83d47.bin completed_at=2026-11-16 08:58:43
   filename_enc               57 bytes  hex=01644f857914bd5f89778f0dee7962d3…  ascii=.dO.y.._.w...yb...9e..#..jMBZ...
   mime_type_enc             157 bytes  hex=0115e404781134b3833b823353c5017a…  ascii=....x.4..;.3S..z.Qd.HK...jX=..N~
   attachment_key_wrapped     61 bytes  hex=01935379b97dbdc2f0ed57294c97c3e0…  ascii=..Sy.}....W)L....[Xh....8.......
-- id=18 uuid=ebfbf6bb-… size_bytes=129 …  filename_enc 50 bytes hex=01884d61…  mime_type_enc 157 bytes …
-- id=21 uuid=e61c46ca-… size_bytes=64  …  filename_enc 50 bytes hex=019a493a…  mime_type_enc 157 bytes …

== ls -l storage/app/private/attachments/u9
   1f997046-c216-4fd3-b445-f7c11bc83d47.bin       2101640 bytes  first16=0194d45390e3d3a2abf24defc5c77119
   8066b0e3-7efe-46a1-93bb-4aacba4e13a2.bin           129 bytes  first16=01b52a1a6c8fde998a0d23d91f075409
   de30ab37-b1bc-4867-9939-fc3a9d3b4da9.bin            64 bytes  first16=01bbb627267e193453b23ec45147c0e6
```

The bundles in `vault_items` show `content_enc NULL` and envelope-only titles. Every
column of every `vault_items`, `attachments` and `recipients` row of the user, plus every
byte of every file (2,105,835 bytes), was searched for what had been typed or uploaded:

```
"will_and_testament" no · "testament" no · ".pdf" no · "application/pdf" no · "text/plain" no
"%PDF" no · "SECRET-WILL-CONTENT" no · "SECRET-NOTE-CONTENT" no · "SECRET-RECEIPT-CONTENT" no
"The house goes to Layla" no · "وصيتي" no · "ملاحظة" no · "note.txt" no · "receipt" no
"House documents" no · "أوراق البيت" no · "Yesterday's papers" no · "Layla Haddad" no · "Daughter" no
```

Same-length MIME envelopes (157 bytes) for `application/pdf` and `text/plain`; file names on
disk are server UUIDs unrelated to the client's attachment IDs. Downloading and decrypting
`will_and_testament_final.pdf` in the browser gave back a byte-identical file under its
original name.

### 7.2 The server refuses an upload that would exceed 10 MB

With 2,101,769 bytes used, a declaration of 8 × 1,048,605 bytes was sent straight from the
page with `fetch` — structurally valid envelopes, no client-side check involved:

```json
{ "status": 413, "body": { "error": { "code": "QUOTA_EXCEEDED",
  "message": "This file does not fit in your remaining storage.", "fields": {} } } }
```

No row and no file were created. (The UI also refuses a 9 MB file before sending anything —
a courtesy, not the control.) Backend tests pin the boundary exactly: one byte over the
limit is `413`, exactly the remainder is accepted, the limit follows the config value.

### 7.3 Purge after 30 days, restore within them

Both collections were deleted through the UI. Then, **because 30 days can't be waited for**,
their `deleted_at` (and that of the attachments deleted with them) was backdated by SQL —
"House documents" to 31 days ago, "Yesterday's papers" to 1 day ago (values written in the
PHP CLI's local time, UTC+2; the hour offset is irrelevant at these distances). Then the
real command:

```
$ php artisan vault:purge-deleted
Purged 0 recipient(s), 1 item(s), 0 attachment(s) and 0 abandoned upload(s).
```

After: `vault_items` id 10 gone, `attachments` 17 and 18 gone, and the directory holds only
`de30ab37-….bin` (64 bytes, the recent collection's file). The audit log has
`system vault_item.purged {"reason":"window_expired","item_id":"81a13eca-…","recipient_id":7,"attachment_count":2}`.

Recently deleted then listed only `Yesterday's papers` ("deleted Nov 15, 2026 · permanently
erased on Dec 15, 2026"). It was restored in the browser; afterwards its row and its
file's row have `deleted_at = NULL`, and the file downloaded and decrypted to
`"SECRET-RECEIPT-CONTENT bank box 42\n"`.

The same boundaries are covered with a frozen clock in `TrashTest`: still restorable at
29 d 23:59:59, not restorable and purged at exactly 30 d, abandoned uploads kept at
23:59:59 and removed at 24 h.

## 8. Running-app verification

Playwright (scratchpad, not in the repo), Chromium, against `php -S :8031` on the WSL MySQL
database — first with `npm run dev`, then with the production build served under the exact
CSP from `SecurityHeaders.php`. 34 asserted checks, all passing in both runs:

- register → acknowledge Recovery Key → recipient → new file collection; banner reads
  "These files are for: Layla Haddad"
- storage meter "0 bytes of 10 MB used" (from the server); a 3-chunk (2 MB) upload with a
  progress bar; a second file with an Arabic name
- download → byte-identical, original name; client pre-check message for a 9 MB file;
  server `413` (§7.2)
- delete a file → dialog states "restore … for 30 days … permanently erased" → Undo restores
  it
- cancel an upload mid-way → used bytes return exactly to the previous value
- a new message says files come after saving; after saving, the message carries a file;
  the list labels "Message" and "File collection"
- delete the collection → listed in Recently deleted with decrypted title and erase date →
  restore → both files back
- delete a message → erase now: wrong password refused, right one erases it
- delete the recipient (typed name + password) → Undo → recipient, collection and files back
- Arabic: `dir="rtl"`, Arabic file list, meter ("مُستخدَم 2 م.ب من 10 م.ب"), Recently
  deleted lists the trashed file; meter and progress bars fill from the start edge in RTL
- reload → "Unlock your vault", no file names rendered; wrong password refused; right
  password decrypts the list again
- no console errors other than the deliberate `413` and wrong-password `401`s; under the
  CSP, zero `securitypolicyviolation` events (octet-stream upload and blob download both
  work)

The click-through found four bugs, all fixed and committed (`354a5e3`):

1. **Cancelling during the declare request leaked the quota reservation.** The server
   created the row; the aborted client never deleted it. The declare request now always
   completes, then the cancel is honoured with a `DELETE`.
2. The "added" notice appeared before the refreshed list; it now waits for the refresh.
3. "Collection created" never showed. Going from `/files/new` to `/files/:id` reuses the
   component, so its `useState` initialiser never re-ran.
4. "0 byte" → "0 bytes".

## 9. Dependencies

None added or changed (`package.json`, `package-lock.json`, `composer.*` untouched).
`npm audit`: 0 vulnerabilities.

## 10. Open questions, limitations, deferred work

### Known limitations

- **Concurrency on MySQL was not exercised under real parallel load.** The row locks
  (`lockForUpdate`) that serialise quota reservations and chunk order ran on MySQL, but
  `php -S` handles one request at a time. The sequential behaviour was verified on MySQL
  and SQLite; a parallel test needs a real web server and belongs with the Phase 4 load
  test.
- **The server sees each file's exact size, chunk count and filename length** (Decision
  #24). Padding sizes would cost quota; not in v1.
- **Nothing authenticates which files an item has.** Someone with database write access can
  remove or hide an attachment row and the item still decrypts. Same class as the rollback
  limit (Decision #15, `18_Threat_Model.md` Scenario 2).
- **One-second `deleted_at` precision.** An item deleted on its own in the same second as
  its recipient comes back with the recipient. This errs towards keeping data, and isn't
  reachable through the UI, where deleting a recipient needs a password.
- **Resume only within the page.** After a reload an unfinished upload is abandoned. It is
  removed after 24 h; until then it counts against the quota and is not shown in the UI.
  Cancel and page-leave both delete it at once.
- **"Permanently erased" covers the live DB and disk only.** Off-host backups (`05` §21)
  keep ciphertext until they age out. This is stated in `07`.
- **Pre-existing, noticed again:** `ApiException`s such as `invalid_credentials` are written
  to `laravel.log` at ERROR level (no secrets, just noise). Laravel Pint is still not clean,
  and the UI language still resets to English on reload.

### Deferred

- **2d (Preview):** reporting client-side `AttachmentIntegrityError`/`ItemIntegrityError` to
  the audit log; preview of files as the recipient will receive them.
- **Phase 3:** the delivery trigger must read attachments through the same live-only scopes,
  and can derive the Content-Wrap Key and open files with exactly the format pinned by
  `AttachmentVectorTest`.
- **Phase 4:** account deletion must purge attachment files before rows (FKs are
  `RESTRICT`); include `storage/app/private/attachments` in the off-host backup.

### Open questions for the owner

- None blocking. Possible future choice: offer size padding for files (hides exact sizes at
  the cost of quota) — not proposed for v1.

## 11. Commits

| Commit | Subject |
|---|---|
| `22f3858` | docs: resolve Phase 2c spec gaps (Decisions #21-#29) |
| `d4f0d6d` | feat: add attachments table, Attachment model and quota config |
| `719160e` | feat: attachments API, file bundles, restore and purge |
| `c3fedc3` | feat: client-side chunked attachment crypto and file bundle items |
| `2d68f27` | feat: report undo window with storage, recipient on trashed attachments |
| `c417063` | feat: attachments, file collections and recently-deleted UI |
| `354a5e3` | fix: release quota when an upload is cancelled during declaration |
| (this commit) | docs: Phase 2c session log, roadmap, README, deployment and testing notes — tagged `phase-2c` |
