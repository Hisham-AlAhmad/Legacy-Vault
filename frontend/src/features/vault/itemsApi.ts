/**
 * Vault items (messages) — API orchestration for Phase 2b (VAULT-5, VAULT-6, VAULT-9).
 *
 * Titles and bodies are encrypted in src/crypto/items.ts before they leave the browser
 * and decrypted here after they come back; components only ever see plaintext views.
 * Message bodies pass through the sanitizer on both sides of the crypto: before
 * encrypting, and again after decrypting (docs/05_Security_Architecture.md §9).
 */
import {
  createBundle,
  createItem,
  decryptItem,
  decryptItemTitle,
  ItemIntegrityError,
  rotateBundle,
  rotateItem,
  type ItemContext,
} from '../../crypto/items';
import { api } from '../../lib/api/client';
import type { VaultItemRecord, VaultItemSummaryRecord } from '../../lib/api/types';
import { sanitizeHtml } from './sanitizeHtml';

export interface ItemInput {
  title: string;
  /** Rich-text HTML from the editor. Sanitized here before encryption. */
  body: string;
}

/** A list entry. `title` is null only when the item failed its integrity check. */
export interface ItemSummaryView {
  id: string;
  itemType: 'message' | 'file_bundle';
  position: number;
  updatedAt: string;
  title: string | null;
}

export interface ItemView {
  id: string;
  title: string;
  /** Decrypted, then sanitized. */
  body: string;
  updatedAt: string;
}

/** The server returned an item under a recipient it doesn't claim to belong to. */
export class ItemRecipientMismatchError extends Error {
  constructor() {
    super('Vault item does not belong to this recipient.');
    this.name = 'ItemRecipientMismatchError';
  }
}

/** The item exists but is not the kind the page that asked for it can show. */
export class WrongItemTypeError extends Error {
  constructor() {
    super('Vault item is not of the expected type.');
    this.name = 'WrongItemTypeError';
  }
}

/**
 * The AAD context is built from what the *user is looking at* — their own ID and the
 * recipient whose page is open — not from the recipient ID the server reports for the
 * item. An item filed under the wrong recipient therefore fails to decrypt instead of
 * being shown as that recipient's (docs/06_Cryptography.md §9).
 */
const contextFor = (userId: number, recipientId: number, itemId: string): ItemContext => ({
  userId,
  recipientId,
  itemId,
});

/**
 * One recipient's items, titles decrypted. A title that fails authentication is reported
 * as `title: null` so the list can flag that one item; any other error propagates.
 */
export async function fetchItems(masterKey: Uint8Array, userId: number, recipientId: number): Promise<ItemSummaryView[]> {
  const { items } = await api<{ items: VaultItemSummaryRecord[] }>('GET', `/recipients/${recipientId}/items`);

  return Promise.all(
    items.map(async (record): Promise<ItemSummaryView> => {
      let title: string | null;
      try {
        title = await decryptItemTitle(record, masterKey, contextFor(userId, recipientId, record.id));
      } catch (error) {
        if (!(error instanceof ItemIntegrityError)) {
          throw error;
        }
        title = null; // surfaced in the UI as an integrity warning, never as an empty title
      }

      return { id: record.id, itemType: record.itemType, position: record.position, updatedAt: record.updatedAt, title };
    }),
  );
}

/**
 * One item, fully decrypted. Throws ItemIntegrityError if any part fails authentication —
 * the editor must not open on partial or unauthenticated content.
 */
export async function fetchItem(
  masterKey: Uint8Array,
  userId: number,
  recipientId: number,
  itemId: string,
): Promise<ItemView> {
  const { item } = await api<{ item: VaultItemRecord }>('GET', `/vault-items/${itemId}`);

  if (item.recipientId !== recipientId || item.id !== itemId) {
    throw new ItemRecipientMismatchError();
  }
  // The message editor never opens a file bundle (it has no body to edit).
  if (item.itemType !== 'message') {
    throw new WrongItemTypeError();
  }
  // A message without a body is not something this client ever stores.
  if (item.contentEnc === null) {
    throw new ItemIntegrityError('item.content', 'missing body');
  }

  const plaintext = await decryptItem({ ...item, contentEnc: item.contentEnc }, masterKey, contextFor(userId, recipientId, itemId));

  return {
    id: item.id,
    title: plaintext.title,
    // Second sanitizer pass: don't assume what was stored was produced by this client.
    body: sanitizeHtml(plaintext.body),
    updatedAt: item.updatedAt,
  };
}

/** Create a message for a recipient. The item ID and first content key are generated client-side. */
export async function createMessage(
  masterKey: Uint8Array,
  userId: number,
  recipientId: number,
  input: ItemInput,
): Promise<void> {
  const { itemId, encrypted } = await createItem(
    { title: input.title, body: sanitizeHtml(input.body) },
    masterKey,
    { userId, recipientId },
  );

  await api('POST', `/recipients/${recipientId}/items`, { id: itemId, itemType: 'message', ...encrypted });
}

/** Save an edit. Always re-encrypts under a brand-new content key (docs/06_Cryptography.md §10). */
export async function updateMessage(
  masterKey: Uint8Array,
  userId: number,
  recipientId: number,
  itemId: string,
  input: ItemInput,
): Promise<void> {
  const encrypted = await rotateItem(
    { title: input.title, body: sanitizeHtml(input.body) },
    masterKey,
    contextFor(userId, recipientId, itemId),
  );

  await api('PATCH', `/vault-items/${itemId}`, { ...encrypted });
}

export async function deleteMessage(itemId: string): Promise<void> {
  await api('DELETE', `/vault-items/${itemId}`);
}

/** `order` is the complete list of the recipient's item IDs, in the wanted order. */
export async function reorderMessages(recipientId: number, order: string[]): Promise<void> {
  await api('POST', `/recipients/${recipientId}/items/reorder`, { order });
}

// ── File bundles (docs/19_Decision_Log.md Decision #27) ─────────────────────────────

export interface BundleView {
  id: string;
  title: string;
  updatedAt: string;
}

/** Create a file bundle — a titled container for files. Returns its new (client-generated) ID. */
export async function createFileBundle(masterKey: Uint8Array, userId: number, recipientId: number, title: string): Promise<string> {
  const { itemId, encrypted } = await createBundle(title, masterKey, { userId, recipientId });

  await api('POST', `/recipients/${recipientId}/items`, { id: itemId, itemType: 'file_bundle', ...encrypted });

  return itemId;
}

/** One bundle, title decrypted. Throws ItemIntegrityError / WrongItemTypeError like fetchItem. */
export async function fetchBundle(masterKey: Uint8Array, userId: number, recipientId: number, itemId: string): Promise<BundleView> {
  const { item } = await api<{ item: VaultItemRecord }>('GET', `/vault-items/${itemId}`);

  if (item.recipientId !== recipientId || item.id !== itemId) {
    throw new ItemRecipientMismatchError();
  }
  if (item.itemType !== 'file_bundle') {
    throw new WrongItemTypeError();
  }

  return {
    id: item.id,
    title: await decryptItemTitle(item, masterKey, contextFor(userId, recipientId, itemId)),
    updatedAt: item.updatedAt,
  };
}

/** Rename a bundle. Like every edit, it re-encrypts under a new key (docs/06_Cryptography.md §10). */
export async function renameFileBundle(
  masterKey: Uint8Array,
  userId: number,
  recipientId: number,
  itemId: string,
  title: string,
): Promise<void> {
  const encrypted = await rotateBundle(title, masterKey, contextFor(userId, recipientId, itemId));

  await api('PATCH', `/vault-items/${itemId}`, { ...encrypted });
}

/** Undo a deletion from the recently-deleted list (also used by the "Undo" in notices). */
export async function restoreItem(itemId: string): Promise<void> {
  await api('POST', `/trash/items/${itemId}/restore`);
}
