/**
 * Vault items (messages) — API orchestration for Phase 2b (VAULT-5, VAULT-6, VAULT-9).
 *
 * Titles and bodies are encrypted in src/crypto/items.ts before they leave the browser
 * and decrypted here after they come back; components only ever see plaintext views.
 * Message bodies pass through the sanitizer on both sides of the crypto: before
 * encrypting, and again after decrypting (docs/05_Security_Architecture.md §9).
 */
import {
  createItem,
  decryptItem,
  decryptItemTitle,
  ItemIntegrityError,
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

      return { id: record.id, position: record.position, updatedAt: record.updatedAt, title };
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

  const plaintext = await decryptItem(item, masterKey, contextFor(userId, recipientId, itemId));

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
