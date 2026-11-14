/**
 * Vault item encryption — docs/06_Cryptography.md §4, §8–10, §13.
 *
 * Every item version has its own random 256-bit content key. That key is wrapped by the
 * Content-Wrap Key (HKDF from the Master Key) and encrypts the item's title and body.
 * All three ciphertexts are envelopes — version ‖ nonce ‖ ciphertext ‖ tag — so each
 * carries its own nonce (docs/19_Decision_Log.md Decisions #13–#14), and each is bound
 * by AAD to the user, the recipient, the item ID and its own role (Decision #15).
 *
 * There is no "edit in place": the only ways to produce item ciphertext are createItem()
 * and rotateItem(), and both generate a fresh content key and fresh nonces (§10).
 *
 * Decryption never returns null or an empty value on failure. A failed GCM tag check —
 * wrong key, wrong context, or tampering — throws ItemIntegrityError (§9).
 */
import { AES_GCM, CONTENT_WRAP_KEY_HKDF, ITEM_FORMAT_VERSION, ITEM_LIMITS } from './constants';
import { decryptContent, encryptContent, fromBase64, toBase64, zeroize } from './index';

/** Which of an item's three ciphertexts — bound into AAD so they can't be swapped. */
export type ItemPart = 'item.key' | 'item.title' | 'item.content';

/** Everything an item's ciphertext is bound to. All of it is rebuilt from server data on decrypt. */
export interface ItemContext {
  userId: number;
  recipientId: number;
  /** The item's client-generated UUIDv4. */
  itemId: string;
}

export interface ItemPlaintext {
  title: string;
  /** Sanitized HTML — sanitizing is the caller's job, before encrypt and after decrypt. */
  body: string;
}

/** What is needed to show a title: the wrapped key and the title envelope (base64). */
export interface EncryptedItemHeader {
  contentKeyWrapped: string;
  titleEnc: string;
  aadVersion: number;
}

/** A complete encrypted item, as sent to and returned by the API (base64 envelopes). */
export interface EncryptedItem extends EncryptedItemHeader {
  contentEnc: string;
}

/**
 * A ciphertext failed authentication or was malformed. Callers must surface this as an
 * integrity problem — never swallow it, never substitute empty content.
 */
export class ItemIntegrityError extends Error {
  readonly part: ItemPart;

  constructor(part: ItemPart, reason: string) {
    super(`Vault item integrity check failed (${part}): ${reason}`);
    this.name = 'ItemIntegrityError';
    this.part = part;
  }
}

/** The plaintext doesn't fit the server's size caps — raised before anything is sent. */
export class ItemTooLargeError extends Error {
  readonly part: 'item.title' | 'item.content';

  constructor(part: 'item.title' | 'item.content') {
    super(`Vault item ${part} exceeds the maximum size.`);
    this.name = 'ItemTooLargeError';
    this.part = part;
  }
}

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const ENVELOPE_HEADER_BYTES = 1 + AES_GCM.nonceLengthBytes;
const ENVELOPE_OVERHEAD_BYTES = ENVELOPE_HEADER_BYTES + AES_GCM.tagLengthBits / 8;

const subtle = globalThis.crypto.subtle;
const utf8 = new TextEncoder();

/** A new item ID. Generated client-side so it exists before the first encryption (Decision #15). */
export function generateItemId(): string {
  return globalThis.crypto.randomUUID();
}

/**
 * Content-Wrap Key = HKDF-SHA256(Master Key, info "legacy-vault/v1/content-wrap-key").
 * The server reproduces it only inside the delivery trigger (Phase 3).
 */
export async function deriveContentWrapKey(masterKey: Uint8Array): Promise<Uint8Array> {
  const ikm = await subtle.importKey('raw', masterKey as BufferSource, 'HKDF', false, ['deriveBits']);
  const bits = await subtle.deriveBits(
    {
      name: 'HKDF',
      hash: CONTENT_WRAP_KEY_HKDF.hash,
      salt: new Uint8Array(0),
      info: utf8.encode(CONTENT_WRAP_KEY_HKDF.info),
    },
    ikm,
    CONTENT_WRAP_KEY_HKDF.lengthBytes * 8,
  );

  return new Uint8Array(bits);
}

/**
 * AAD for one part of an item: "legacy-vault|v1|{part}|user:{id}|recipient:{id}|item:{uuid}".
 * The context is validated first — only integers and a canonical UUID can appear, so the
 * string is unambiguous.
 */
export function itemAad(part: ItemPart, context: ItemContext): Uint8Array {
  assertValidContext(context);

  return utf8.encode(
    `legacy-vault|v${ITEM_FORMAT_VERSION}|${part}|user:${context.userId}|recipient:${context.recipientId}|item:${context.itemId}`,
  );
}

function assertValidContext({ userId, recipientId, itemId }: ItemContext): void {
  if (!Number.isSafeInteger(userId) || userId <= 0 || !Number.isSafeInteger(recipientId) || recipientId <= 0) {
    throw new TypeError('Vault item context needs positive integer user and recipient IDs.');
  }
  if (!UUID_V4.test(itemId)) {
    throw new TypeError('Vault item ID must be a canonical lowercase UUIDv4.');
  }
}

/** version ‖ nonce ‖ ciphertext ‖ tag, with a fresh random nonce (from encryptContent). */
async function seal(plaintext: Uint8Array, key: Uint8Array, aad: Uint8Array): Promise<Uint8Array> {
  const { ciphertext, nonce } = await encryptContent(plaintext, key, aad);

  const envelope = new Uint8Array(ENVELOPE_HEADER_BYTES + ciphertext.length);
  envelope[0] = ITEM_FORMAT_VERSION;
  envelope.set(nonce, 1);
  envelope.set(ciphertext, ENVELOPE_HEADER_BYTES);

  return envelope;
}

/** Open an envelope or throw — there is no null/empty return path. */
async function open(envelopeB64: string, key: Uint8Array, part: ItemPart, context: ItemContext): Promise<Uint8Array> {
  let envelope: Uint8Array;
  try {
    envelope = fromBase64(envelopeB64);
  } catch {
    throw new ItemIntegrityError(part, 'not valid base64');
  }

  if (envelope.length < ENVELOPE_OVERHEAD_BYTES) {
    throw new ItemIntegrityError(part, 'envelope too short');
  }
  if (envelope[0] !== ITEM_FORMAT_VERSION) {
    throw new ItemIntegrityError(part, 'unknown format version');
  }

  const plaintext = await decryptContent(
    envelope.subarray(ENVELOPE_HEADER_BYTES),
    envelope.subarray(1, ENVELOPE_HEADER_BYTES),
    key,
    itemAad(part, context),
  );

  if (plaintext === null) {
    throw new ItemIntegrityError(part, 'authentication failed');
  }

  return plaintext;
}

function decodeText(bytes: Uint8Array, part: ItemPart): string {
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  } catch {
    throw new ItemIntegrityError(part, 'not valid UTF-8');
  }
}

/** Unwrap the item's content key. The caller zeroizes it. */
async function unwrapContentKey(
  header: EncryptedItemHeader,
  masterKey: Uint8Array,
  context: ItemContext,
): Promise<Uint8Array> {
  if (header.aadVersion !== ITEM_FORMAT_VERSION) {
    throw new ItemIntegrityError('item.key', 'unknown format version');
  }

  const wrapKey = await deriveContentWrapKey(masterKey);
  try {
    const contentKey = await open(header.contentKeyWrapped, wrapKey, 'item.key', context);
    if (contentKey.length !== AES_GCM.keyLengthBytes) {
      zeroize(contentKey);
      throw new ItemIntegrityError('item.key', 'unexpected key length');
    }
    return contentKey;
  } finally {
    zeroize(wrapKey);
  }
}

/**
 * The one encryption path: a new random content key, wrapped, and title + body encrypted
 * under it — three envelopes with three independent nonces.
 */
async function encryptUnderFreshKey(
  plaintext: ItemPlaintext,
  masterKey: Uint8Array,
  context: ItemContext,
): Promise<EncryptedItem> {
  assertValidContext(context);

  const titleBytes = utf8.encode(plaintext.title);
  const bodyBytes = utf8.encode(plaintext.body);

  if (
    [...plaintext.title].length > ITEM_LIMITS.titleChars ||
    titleBytes.length + ENVELOPE_OVERHEAD_BYTES > ITEM_LIMITS.titleEnvelopeBytes
  ) {
    throw new ItemTooLargeError('item.title');
  }
  if (bodyBytes.length + ENVELOPE_OVERHEAD_BYTES > ITEM_LIMITS.contentEnvelopeBytes) {
    throw new ItemTooLargeError('item.content');
  }

  const contentKey = new Uint8Array(AES_GCM.keyLengthBytes);
  globalThis.crypto.getRandomValues(contentKey);
  const wrapKey = await deriveContentWrapKey(masterKey);

  try {
    const [contentKeyWrapped, titleEnc, contentEnc] = await Promise.all([
      seal(contentKey, wrapKey, itemAad('item.key', context)),
      seal(titleBytes, contentKey, itemAad('item.title', context)),
      seal(bodyBytes, contentKey, itemAad('item.content', context)),
    ]);

    return {
      contentKeyWrapped: toBase64(contentKeyWrapped),
      titleEnc: toBase64(titleEnc),
      contentEnc: toBase64(contentEnc),
      aadVersion: ITEM_FORMAT_VERSION,
    };
  } finally {
    zeroize(contentKey);
    zeroize(wrapKey);
  }
}

/**
 * Create a new item for a recipient: picks the item ID, generates the first content key
 * and encrypts title and body.
 */
export async function createItem(
  plaintext: ItemPlaintext,
  masterKey: Uint8Array,
  owner: Pick<ItemContext, 'userId' | 'recipientId'>,
): Promise<{ itemId: string; encrypted: EncryptedItem }> {
  const itemId = generateItemId();
  const encrypted = await encryptUnderFreshKey(plaintext, masterKey, { ...owner, itemId });

  return { itemId, encrypted };
}

/**
 * Save an edit to an existing item. Always rotates: a brand-new content key and new
 * nonces, with title and body fully re-encrypted (docs/06_Cryptography.md §10). The
 * previous key is simply never used again.
 */
export async function rotateItem(
  plaintext: ItemPlaintext,
  masterKey: Uint8Array,
  context: ItemContext,
): Promise<EncryptedItem> {
  return encryptUnderFreshKey(plaintext, masterKey, context);
}

/** Decrypt only the title (item lists). Throws ItemIntegrityError on any failure. */
export async function decryptItemTitle(
  header: EncryptedItemHeader,
  masterKey: Uint8Array,
  context: ItemContext,
): Promise<string> {
  const contentKey = await unwrapContentKey(header, masterKey, context);
  try {
    return decodeText(await open(header.titleEnc, contentKey, 'item.title', context), 'item.title');
  } finally {
    zeroize(contentKey);
  }
}

/** Decrypt title and body. Throws ItemIntegrityError on any failure — never partial output. */
export async function decryptItem(
  item: EncryptedItem,
  masterKey: Uint8Array,
  context: ItemContext,
): Promise<ItemPlaintext> {
  const contentKey = await unwrapContentKey(item, masterKey, context);
  try {
    const [title, body] = await Promise.all([
      open(item.titleEnc, contentKey, 'item.title', context),
      open(item.contentEnc, contentKey, 'item.content', context),
    ]);

    return { title: decodeText(title, 'item.title'), body: decodeText(body, 'item.content') };
  } finally {
    zeroize(contentKey);
  }
}
