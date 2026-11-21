/**
 * Recently deleted — list, restore, erase now (docs/19_Decision_Log.md Decision #28).
 * Names and titles are decrypted here. For entries whose parent is not on screen, the
 * AAD context comes from the IDs the server reports: a successful GCM check proves that
 * context was the real one, and a wrong one only shows the entry as unreadable.
 */
import { decryptItemTitle, ItemIntegrityError } from '../../crypto/items';
import { deriveMetadataKey, metadataAad, openField, zeroize } from '../../crypto';
import { api } from '../../lib/api/client';
import type { StorageUsage, TrashListing } from '../../lib/api/types';
import { toView, type AttachmentView } from './attachmentsApi';
import { reportItemFailure, reportRecipientFieldFailure } from './integrityReporter';

export interface TrashedRecipient {
  id: number;
  name: string | null;
  itemCount: number;
  deletedAt: string;
  purgeAt: string;
}

export interface TrashedItem {
  id: string;
  recipientId: number;
  itemType: 'message' | 'file_bundle';
  title: string | null;
  deletedAt: string;
  purgeAt: string;
}

export interface TrashedAttachment extends AttachmentView {
  itemId: string;
  recipientId: number;
  deletedAt: string;
  purgeAt: string;
}

export interface TrashView {
  undoWindowDays: number;
  recipients: TrashedRecipient[];
  items: TrashedItem[];
  attachments: TrashedAttachment[];
  storage: StorageUsage;
}

export type TrashKind = 'recipients' | 'items' | 'attachments';

export async function fetchTrash(masterKey: Uint8Array, userId: number): Promise<TrashView> {
  const listing = await api<TrashListing>('GET', '/trash');

  const metadataKey = await deriveMetadataKey(masterKey);
  let recipients: TrashedRecipient[];
  try {
    recipients = await Promise.all(
      listing.recipients.map(async (r) => {
        const name = await openField(r.nameEnc, metadataKey, metadataAad('recipient.name', userId));
        if (name === null) {
          reportRecipientFieldFailure('recipient.name', r.id, 'trash');
        }
        return { id: r.id, name, itemCount: r.itemCount, deletedAt: r.deletedAt, purgeAt: r.purgeAt };
      }),
    );
  } finally {
    zeroize(metadataKey);
  }

  const items = await Promise.all(
    listing.items.map(async (i): Promise<TrashedItem> => {
      let title: string | null = null;
      try {
        title = await decryptItemTitle(i, masterKey, { userId, recipientId: i.recipientId, itemId: i.id });
      } catch (error) {
        if (!(error instanceof ItemIntegrityError)) {
          throw error;
        }
        reportItemFailure(error, i.id, 'trash');
      }
      return { id: i.id, recipientId: i.recipientId, itemType: i.itemType, title, deletedAt: i.deletedAt, purgeAt: i.purgeAt };
    }),
  );

  const attachments = await Promise.all(
    listing.attachments.map(async (a): Promise<TrashedAttachment> => ({
      ...(await toView(a, masterKey, { userId, recipientId: a.recipientId, itemId: a.itemId, attachmentId: a.id }, 'trash')),
      itemId: a.itemId,
      recipientId: a.recipientId,
      deletedAt: a.deletedAt,
      purgeAt: a.purgeAt,
    })),
  );

  return { undoWindowDays: listing.undoWindowDays, recipients, items, attachments, storage: listing.storage };
}

/** Undo a deletion. Children deleted in the same operation come back with it. */
export async function restoreFromTrash(kind: TrashKind, id: string | number): Promise<void> {
  await api('POST', `/trash/${kind}/${id}/restore`);
}

/** Erase permanently, now. Step-up: the password is re-verified server-side. */
export async function eraseFromTrash(kind: TrashKind, id: string | number, password: string): Promise<StorageUsage> {
  const { storage } = await api<{ storage: StorageUsage }>('DELETE', `/trash/${kind}/${id}`, { password });
  return storage;
}
