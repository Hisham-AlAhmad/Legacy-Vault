/**
 * Attachments — API orchestration for Phase 2c (VAULT-6, VAULT-7; docs/19_Decision_Log.md
 * Decisions #21–#26). Files are encrypted in src/crypto/attachments.ts before any byte
 * leaves the browser, uploaded one chunk at a time, and decrypted from the download
 * stream; components only ever see plaintext names and the server's storage numbers.
 */
import {
  AttachmentIntegrityError,
  decryptAttachment,
  decryptAttachmentMetadata,
  normalizeFilename,
  plaintextSizeOf,
  prepareAttachment,
  type AttachmentContext,
} from '../../crypto/attachments';
import type { ItemContext } from '../../crypto/items';
import { api, apiStream, apiUploadBytes, ApiRequestError } from '../../lib/api/client';
import type { AttachmentRecord, StorageUsage } from '../../lib/api/types';

/** A file in a list. `filename`/`mimeType` are null only when the file failed its integrity check. */
export interface AttachmentView {
  id: string;
  filename: string | null;
  mimeType: string | null;
  /** The original file size (exact — the server knows it too, see Decision #24). */
  plainBytes: number;
  createdAt: string;
  record: AttachmentRecord;
}

export interface StorageInfo {
  storage: StorageUsage;
  undoWindowDays: number;
}

/** The AAD context is the item the user has open, not what the server says (docs/06_Cryptography.md §9). */
const contextFor = (item: ItemContext, attachmentId: string): AttachmentContext => ({ ...item, attachmentId });

export async function fetchStorage(): Promise<StorageInfo> {
  return api<StorageInfo>('GET', '/vault/storage');
}

/** An item's completed files, names decrypted. One unreadable file doesn't hide the others. */
export async function fetchAttachments(masterKey: Uint8Array, item: ItemContext): Promise<AttachmentView[]> {
  const { attachments } = await api<{ attachments: AttachmentRecord[] }>('GET', `/vault-items/${item.itemId}/attachments`);

  return Promise.all(attachments.map((record) => toView(record, masterKey, contextFor(item, record.id))));
}

export async function toView(record: AttachmentRecord, masterKey: Uint8Array, context: AttachmentContext): Promise<AttachmentView> {
  let filename: string | null = null;
  let mimeType: string | null = null;
  try {
    ({ filename, mimeType } = await decryptAttachmentMetadata(record, masterKey, context));
  } catch (error) {
    if (!(error instanceof AttachmentIntegrityError)) {
      throw error;
    }
  }

  return {
    id: record.id,
    filename,
    mimeType,
    plainBytes: plaintextSizeOf(record.sizeBytes, record.chunkCount),
    createdAt: record.createdAt,
    record,
  };
}

export interface UploadProgress {
  sentChunks: number;
  totalChunks: number;
}

/** A network failure or a transient server error is worth one resync-and-retry; anything else is final. */
function isRetryable(error: unknown): boolean {
  if (error instanceof ApiRequestError) {
    return error.status >= 500 || error.error.code === 'CHUNK_SEQUENCE_ERROR';
  }
  return error instanceof TypeError; // fetch's network error
}

/**
 * Encrypt and upload one file (Decision #25): declare it (which reserves its size against
 * the quota on the server), then send each chunk in order, encrypting it only when it is
 * about to be sent. After a failed chunk the server is asked how far it got and the
 * upload continues from there, once. On cancel or failure the unfinished upload is
 * deleted, which releases the reservation at once instead of after 24 hours.
 */
export async function uploadAttachment(
  masterKey: Uint8Array,
  item: ItemContext,
  file: File,
  { signal, onProgress }: { signal: AbortSignal; onProgress: (progress: UploadProgress) => void },
): Promise<{ attachment: AttachmentRecord; storage: StorageUsage }> {
  const prepared = await prepareAttachment(file, { name: file.name, type: file.type }, masterKey, item);
  const id = prepared.attachmentId;
  let declared = false;

  try {
    signal.throwIfAborted();
    // Deliberately not abortable: if the request were cut off after the server created the
    // row, the client would not know to delete it and the reservation would sit for 24 h.
    // The declaration is small and quick; a cancel is honoured as soon as it returns.
    await api('POST', `/vault-items/${item.itemId}/attachments`, {
      id,
      ...prepared.header,
      sizeBytes: prepared.sizeBytes,
      chunkCount: prepared.chunkCount,
    });
    declared = true;
    signal.throwIfAborted();
    onProgress({ sentChunks: 0, totalChunks: prepared.chunkCount });

    let index = 0;
    let retried = false;

    while (index < prepared.chunkCount) {
      signal.throwIfAborted();
      const chunk = await prepared.encryptChunk(index);

      try {
        const result = await apiUploadBytes<{ receivedChunk: number; attachment?: AttachmentRecord; storage?: StorageUsage }>(
          `/attachments/${id}/chunks`,
          chunk,
          { 'X-Chunk-Index': String(index), 'X-Chunk-Total': String(prepared.chunkCount), 'X-Upload-Id': id },
          { signal },
        );
        index++;
        retried = false;
        onProgress({ sentChunks: index, totalChunks: prepared.chunkCount });

        if (result.attachment && result.storage) {
          return { attachment: result.attachment, storage: result.storage };
        }
      } catch (error) {
        if (signal.aborted || retried || !isRetryable(error)) {
          throw error;
        }
        // Resume: continue from what the server actually has (any fresh encryption of
        // a chunk is equally valid, so the chunk is simply encrypted again).
        retried = true;
        const { attachment } = await api<{ attachment: AttachmentRecord }>('GET', `/attachments/${id}`, undefined, { signal });
        if (attachment.status === 'complete') {
          return { attachment, storage: (await fetchStorage()).storage };
        }
        index = attachment.receivedChunks;
      }
    }

    throw new Error('Upload ended without completing.');
  } catch (error) {
    if (declared) {
      // Abort: frees the reservation now. Best effort — if this fails too, the server
      // discards the unfinished upload after 24 hours.
      await api('DELETE', `/attachments/${id}`).catch(() => undefined);
    }
    throw error;
  } finally {
    prepared.dispose();
  }
}

/**
 * Download, decrypt and hand the file to the browser as a download. The decrypted file is
 * never rendered inside the app: it is offered as an opaque `application/octet-stream`
 * Blob (Decision #23), under its decrypted name normalized a second time — the stored name
 * isn't trusted to have come from this client (docs/05_Security_Architecture.md §11).
 * Any integrity failure throws AttachmentIntegrityError and nothing is saved.
 */
export async function downloadAttachment(
  masterKey: Uint8Array,
  item: ItemContext,
  record: AttachmentRecord,
  onProgress?: (fraction: number) => void,
): Promise<void> {
  const stream = await apiStream(`/attachments/${record.id}/content`);
  // Each authenticated chunk is copied into its own Blob and its buffer wiped at once, so the
  // plaintext is never held twice; a Blob built from Blobs doesn't copy them again. If a later
  // chunk fails, `parts` is simply dropped — nothing is offered to the user.
  const parts: Blob[] = [];
  const result = await decryptAttachment(
    stream,
    record,
    masterKey,
    contextFor(item, record.id),
    (received, total) => onProgress?.(received / total),
    (plain) => parts.push(new Blob([plain as BlobPart])),
  );

  const blob = new Blob(parts, { type: 'application/octet-stream' });

  const url = URL.createObjectURL(blob);
  try {
    const link = document.createElement('a');
    link.href = url;
    link.download = normalizeFilename(result.filename);
    link.rel = 'noopener';
    document.body.append(link);
    link.click();
    link.remove();
  } finally {
    // Give the browser a moment to start the download before the URL goes away.
    setTimeout(() => URL.revokeObjectURL(url), 30_000);
  }
}

/** Soft delete (restorable for the undo window); returns the updated storage numbers. */
export async function deleteAttachment(attachmentId: string): Promise<StorageUsage> {
  const { storage } = await api<{ storage: StorageUsage }>('DELETE', `/attachments/${attachmentId}`);
  return storage;
}
