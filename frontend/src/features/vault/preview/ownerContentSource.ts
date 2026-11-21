/**
 * The owner's content source for "Preview as recipient" — VAULT-8, docs/06_Cryptography.md
 * §16, docs/19_Decision_Log.md Decision #30.
 *
 * Builds the exact `RecipientContent` the recipient would receive, from the ciphertext the
 * server actually stores: the item list, then every item, then every completed file — all
 * fetched fresh, never read from the editor's or list's caches — decrypted here with the
 * in-memory Master Key. The AAD context is the recipient being previewed, not the IDs the
 * server reports, so an item filed under the wrong recipient shows as damaged rather than
 * as theirs.
 *
 * Every file is decrypted in full, into a sink that keeps nothing (each chunk is wiped
 * as soon as it authenticates), so a damaged or truncated file is caught now and not on
 * the recipient's first download. Failures are reported to the audit log (Decision #31)
 * and shown as "would not be delivered".
 *
 * This is the vault-owner-key path. The Phase 3 recipient path will be a second source
 * producing the same RecipientContent; the renderer doesn't change.
 */
import {
  AttachmentIntegrityError,
  decryptAttachment,
  decryptAttachmentMetadata,
  plaintextSizeOf,
  type AttachmentContext,
} from '../../../crypto/attachments';
import { decryptItem, decryptItemTitle, ItemIntegrityError, type ItemContext } from '../../../crypto/items';
import { api, apiStream } from '../../../lib/api/client';
import type { AttachmentRecord, VaultItemRecord, VaultItemSummaryRecord } from '../../../lib/api/types';
import type { ContentEntry, ContentFile, RecipientContent } from '../../recipientView/types';
import { reportAttachmentFailure, reportItemFailure } from '../integrityReporter';
import { sanitizeHtml } from '../sanitizeHtml';

/** The opened content plus what is needed to download a file again on request. */
export interface OwnerPreview {
  content: RecipientContent;
  /** fileId → the attachment record and its item, for downloads. Ciphertext metadata only. */
  files: Map<string, { itemId: string; record: AttachmentRecord }>;
}

export async function loadOwnerPreview(
  masterKey: Uint8Array,
  userId: number,
  recipientId: number,
  recipientName: string | null,
): Promise<OwnerPreview> {
  const { items } = await api<{ items: VaultItemSummaryRecord[] }>('GET', `/recipients/${recipientId}/items`);
  const ordered = [...items].sort((a, b) => a.position - b.position);
  const files: OwnerPreview['files'] = new Map();

  const entries = await Promise.all(
    ordered.map((summary) => openEntry(masterKey, { userId, recipientId, itemId: summary.id }, summary.itemType, files)),
  );

  return { content: { recipientName, entries }, files };
}

async function openEntry(
  masterKey: Uint8Array,
  context: ItemContext,
  kind: ContentEntry['kind'],
  files: OwnerPreview['files'],
): Promise<ContentEntry> {
  const damaged: ContentEntry = { id: context.itemId, kind, status: 'damaged', title: null, bodyHtml: null, files: [] };

  // The full item, fetched fresh — the stored ciphertext, not anything cached.
  const { item } = await api<{ item: VaultItemRecord }>('GET', `/vault-items/${context.itemId}`);

  let title: string;
  let bodyHtml: string | null = null;
  try {
    if (kind === 'message') {
      if (item.contentEnc === null) {
        throw new ItemIntegrityError('item.content', 'missing body');
      }
      const plaintext = await decryptItem({ ...item, contentEnc: item.contentEnc }, masterKey, context);
      title = plaintext.title;
      // Sanitized after decrypting, as everywhere (Decision #16); the renderer does it once more.
      bodyHtml = sanitizeHtml(plaintext.body);
    } else {
      title = await decryptItemTitle(item, masterKey, context);
    }
  } catch (error) {
    if (!(error instanceof ItemIntegrityError)) {
      throw error;
    }
    reportItemFailure(error, context.itemId, 'preview');
    return damaged; // delivery skips the whole item, its files with it
  }

  const { attachments } = await api<{ attachments: AttachmentRecord[] }>('GET', `/vault-items/${context.itemId}/attachments`);
  const opened: ContentFile[] = [];
  // One file at a time: at most one chunk of plaintext is in memory at once.
  for (const record of attachments.filter((a) => a.status === 'complete')) {
    opened.push(await verifyFile(masterKey, { ...context, attachmentId: record.id }, record));
    files.set(record.id, { itemId: context.itemId, record });
  }

  return { id: context.itemId, kind, status: 'ok', title, bodyHtml, files: opened };
}

/** Open a file's name, then authenticate every chunk; nothing decrypted is kept. */
async function verifyFile(masterKey: Uint8Array, context: AttachmentContext, record: AttachmentRecord): Promise<ContentFile> {
  const file: ContentFile = {
    id: record.id,
    status: 'damaged',
    filename: null,
    sizeBytes: plaintextSizeOf(record.sizeBytes, record.chunkCount),
  };

  try {
    file.filename = (await decryptAttachmentMetadata(record, masterKey, context)).filename;
    const stream = await apiStream(`/attachments/${record.id}/content`);
    // The sink receives each authenticated chunk and keeps nothing; decryptAttachment
    // wipes the buffer right after.
    await decryptAttachment(stream, record, masterKey, context, undefined, () => undefined);
    file.status = 'ok';
  } catch (error) {
    if (!(error instanceof AttachmentIntegrityError)) {
      throw error;
    }
    reportAttachmentFailure(error, context.itemId, context.attachmentId, 'preview');
  }

  return file;
}
