/**
 * Attachment encryption — docs/06_Cryptography.md §12, §14;
 * docs/19_Decision_Log.md Decisions #21, #22, #24.
 *
 * Every attachment has its own random 256-bit key, wrapped by the Content-Wrap Key. That
 * key seals the filename and the (fixed-length) MIME type and encrypts the file in 1 MiB
 * chunks. Each chunk is its own envelope — version ‖ nonce ‖ ciphertext ‖ tag — with a
 * fresh random nonce, and its AAD carries its index *and* the total number of chunks, so
 * a chunk only authenticates at its own position in a file of exactly that length:
 * reordering, duplicating, dropping, truncating or transplanting chunks all fail.
 *
 * Streaming: encryption reads one slice of the file at a time (encryptChunk is called per
 * chunk, on demand), and decryption consumes the download as a stream. The ciphertext and
 * plaintext of a whole file are never held together.
 *
 * Decryption never returns null, an empty value or a partial file. Any failure — wrong
 * key, wrong context, tampering, a short or over-long stream — throws
 * AttachmentIntegrityError (§9).
 */
import { AES_GCM, ATTACHMENT_FORMAT, ITEM_FORMAT_VERSION } from './constants';
import { decryptContent, encryptContent, fromBase64, toBase64, zeroize } from './index';
import { INTEGRITY_DETAILS, type IntegrityDetail, type IntegrityReason } from './integrity';
import { deriveContentWrapKey, type ItemContext } from './items';

/** Which ciphertext of an attachment — bound into AAD so they can't be swapped. */
export type AttachmentPart = 'attachment.key' | 'attachment.name' | 'attachment.mime' | 'attachment.chunk';

/** Everything an attachment's ciphertext is bound to. Rebuilt from what the user is looking at. */
export interface AttachmentContext extends ItemContext {
  /** The attachment's client-generated UUIDv4. */
  attachmentId: string;
}

/** The three sealed values stored with an attachment (base64 envelopes). */
export interface EncryptedAttachmentHeader {
  attachmentKeyWrapped: string;
  filenameEnc: string;
  mimeTypeEnc: string;
  aadVersion: number;
}

/** What the server reports about the stored file — untrusted until every chunk authenticates. */
export interface EncryptedAttachmentInfo extends EncryptedAttachmentHeader {
  /** Total ciphertext bytes. */
  sizeBytes: number;
  chunkCount: number;
}

export interface AttachmentMetadata {
  filename: string;
  mimeType: string;
}

/**
 * A ciphertext failed authentication, or the file is not the shape it claims to be.
 * Callers must surface this as an integrity problem — never swallow it, never hand over
 * partial content.
 */
export class AttachmentIntegrityError extends Error {
  readonly part: AttachmentPart;
  /** Enumerated code — what an audit report carries, never the message (Decision #31). */
  readonly reason: IntegrityReason;

  constructor(part: AttachmentPart, detail: IntegrityDetail) {
    super(`Attachment integrity check failed (${part}): ${detail}`);
    this.name = 'AttachmentIntegrityError';
    this.part = part;
    this.reason = INTEGRITY_DETAILS[detail];
  }
}

/** A zero-byte file can't be attached: there is nothing to encrypt. */
export class EmptyAttachmentError extends Error {
  constructor() {
    super('An attachment cannot be empty.');
    this.name = 'EmptyAttachmentError';
  }
}

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const MIME_TYPE = /^[\x21-\x7e]+$/;
const ENVELOPE_HEADER_BYTES = 1 + AES_GCM.nonceLengthBytes;
const { chunkPlaintextBytes: CHUNK_PLAIN, chunkBytes: CHUNK_BYTES, envelopeOverheadBytes: OVERHEAD } = ATTACHMENT_FORMAT;

const utf8 = new TextEncoder();

/** A new attachment ID. Generated client-side so it exists before the first encryption (Decision #21). */
export function generateAttachmentId(): string {
  return globalThis.crypto.randomUUID();
}

/** How many chunks a file of `plainBytes` bytes becomes. */
export function chunkCountFor(plainBytes: number): number {
  return Math.ceil(plainBytes / CHUNK_PLAIN);
}

/** The ciphertext size of a file of `plainBytes` bytes — what is stored and counted against the quota. */
export function ciphertextSizeFor(plainBytes: number): number {
  return plainBytes + chunkCountFor(plainBytes) * OVERHEAD;
}

/** The plaintext size of a stored attachment, from the two numbers the server reports. */
export function plaintextSizeOf(sizeBytes: number, chunkCount: number): number {
  return sizeBytes - chunkCount * OVERHEAD;
}

/** Whether `sizeBytes` is a total that `chunkCount` chunks can add up to (Decision #22). */
function isPossibleSize(sizeBytes: number, chunkCount: number): boolean {
  return (
    Number.isSafeInteger(sizeBytes) &&
    Number.isSafeInteger(chunkCount) &&
    chunkCount >= 1 &&
    sizeBytes >= (chunkCount - 1) * CHUNK_BYTES + OVERHEAD + 1 &&
    sizeBytes <= chunkCount * CHUNK_BYTES
  );
}

/**
 * AAD for one part of an attachment:
 * "legacy-vault|v1|{part}|user:{id}|recipient:{id}|item:{uuid}|attachment:{uuid}", and for a
 * chunk additionally "|chunk:{index}/{total}". Only validated integers and canonical UUIDs
 * can appear, written as canonical decimals, so the string is unambiguous.
 */
export function attachmentAad(
  part: AttachmentPart,
  context: AttachmentContext,
  chunk?: { index: number; total: number },
): Uint8Array {
  assertValidContext(context);

  let aad =
    `legacy-vault|v${ITEM_FORMAT_VERSION}|${part}|user:${context.userId}|recipient:${context.recipientId}` +
    `|item:${context.itemId}|attachment:${context.attachmentId}`;

  if (part === 'attachment.chunk') {
    if (
      !chunk ||
      !Number.isSafeInteger(chunk.index) ||
      !Number.isSafeInteger(chunk.total) ||
      chunk.total < 1 ||
      chunk.index < 0 ||
      chunk.index >= chunk.total
    ) {
      throw new TypeError('A chunk AAD needs an index inside its total.');
    }
    aad += `|chunk:${chunk.index}/${chunk.total}`;
  } else if (chunk) {
    throw new TypeError('Only chunks carry an index.');
  }

  return utf8.encode(aad);
}

function assertValidContext({ userId, recipientId, itemId, attachmentId }: AttachmentContext): void {
  if (!Number.isSafeInteger(userId) || userId <= 0 || !Number.isSafeInteger(recipientId) || recipientId <= 0) {
    throw new TypeError('Attachment context needs positive integer user and recipient IDs.');
  }
  if (!UUID_V4.test(itemId) || !UUID_V4.test(attachmentId)) {
    throw new TypeError('Item and attachment IDs must be canonical lowercase UUIDv4.');
  }
}

/**
 * A filename safe to store and to hand to the browser's download: no path separators or
 * control characters, never empty, and short enough for its envelope (≤ 255 characters
 * and ≤ 995 UTF-8 bytes — Decision #24). An over-long name is shortened on character
 * boundaries, keeping the extension.
 */
export function normalizeFilename(name: string): string {
  // eslint-disable-next-line no-control-regex -- stripping control characters is the point
  let clean = name.replace(/[\u0000-\u001f\u007f/\\]/g, '').trim();
  if (clean === '' || clean === '.' || clean === '..') {
    clean = 'file';
  }

  const fits = (value: string) =>
    [...value].length <= ATTACHMENT_FORMAT.filenameChars && utf8.encode(value).length <= ATTACHMENT_FORMAT.filenameBytes;

  if (fits(clean)) {
    return clean;
  }

  const dot = clean.lastIndexOf('.');
  const hasExtension = dot > 0 && [...clean.slice(dot)].length <= 16;
  const extension = hasExtension ? clean.slice(dot) : '';
  const stem = [...(hasExtension ? clean.slice(0, dot) : clean)];

  while (stem.length > 1 && !fits(stem.join('') + extension)) {
    stem.pop();
  }

  return stem.join('') + extension;
}

/** The MIME type to store: the browser's, or the generic one if it is missing or unusable. */
export function normalizeMimeType(type: string): string {
  return MIME_TYPE.test(type) && type.length <= ATTACHMENT_FORMAT.mimeFieldBytes ? type : ATTACHMENT_FORMAT.fallbackMimeType;
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
async function open(envelope: Uint8Array, key: Uint8Array, part: AttachmentPart, aad: Uint8Array): Promise<Uint8Array> {
  if (envelope.length < OVERHEAD) {
    throw new AttachmentIntegrityError(part, 'envelope too short');
  }
  if (envelope[0] !== ITEM_FORMAT_VERSION) {
    throw new AttachmentIntegrityError(part, 'unknown format version');
  }

  const plaintext = await decryptContent(
    envelope.subarray(ENVELOPE_HEADER_BYTES),
    envelope.subarray(1, ENVELOPE_HEADER_BYTES),
    key,
    aad,
  );

  if (plaintext === null) {
    throw new AttachmentIntegrityError(part, 'authentication failed');
  }

  return plaintext;
}

function decodeEnvelope(valueB64: string, part: AttachmentPart): Uint8Array {
  try {
    return fromBase64(valueB64);
  } catch {
    throw new AttachmentIntegrityError(part, 'not valid base64');
  }
}

/** Unwrap the attachment key. The caller zeroizes it. */
async function unwrapAttachmentKey(
  header: EncryptedAttachmentHeader,
  masterKey: Uint8Array,
  context: AttachmentContext,
): Promise<Uint8Array> {
  if (header.aadVersion !== ITEM_FORMAT_VERSION) {
    throw new AttachmentIntegrityError('attachment.key', 'unknown format version');
  }

  const wrapKey = await deriveContentWrapKey(masterKey);
  try {
    const key = await open(
      decodeEnvelope(header.attachmentKeyWrapped, 'attachment.key'),
      wrapKey,
      'attachment.key',
      attachmentAad('attachment.key', context),
    );
    if (key.length !== AES_GCM.keyLengthBytes) {
      zeroize(key);
      throw new AttachmentIntegrityError('attachment.key', 'unexpected key length');
    }
    return key;
  } finally {
    zeroize(wrapKey);
  }
}

async function openMetadata(
  header: EncryptedAttachmentHeader,
  key: Uint8Array,
  context: AttachmentContext,
): Promise<AttachmentMetadata> {
  const [nameBytes, mimeField] = await Promise.all([
    open(decodeEnvelope(header.filenameEnc, 'attachment.name'), key, 'attachment.name', attachmentAad('attachment.name', context)),
    open(decodeEnvelope(header.mimeTypeEnc, 'attachment.mime'), key, 'attachment.mime', attachmentAad('attachment.mime', context)),
  ]);

  let filename: string;
  try {
    filename = new TextDecoder('utf-8', { fatal: true }).decode(nameBytes);
  } catch {
    throw new AttachmentIntegrityError('attachment.name', 'not valid UTF-8');
  }

  // Exactly 128 bytes: printable ASCII, then only 0x00 padding to the end (Decision #24).
  if (mimeField.length !== ATTACHMENT_FORMAT.mimeFieldBytes) {
    throw new AttachmentIntegrityError('attachment.mime', 'unexpected field length');
  }
  let end = mimeField.length;
  while (end > 0 && mimeField[end - 1] === 0) {
    end--;
  }
  const mimeType = String.fromCharCode(...mimeField.subarray(0, end));
  if (!MIME_TYPE.test(mimeType)) {
    throw new AttachmentIntegrityError('attachment.mime', 'malformed type');
  }

  return { filename, mimeType };
}

/** An attachment ready to upload: its sealed header, and its chunks, encrypted on demand. */
export interface PreparedAttachment {
  attachmentId: string;
  header: EncryptedAttachmentHeader;
  /** Total ciphertext bytes — declared to the server before the first chunk. */
  sizeBytes: number;
  chunkCount: number;
  /**
   * Read and encrypt chunk `index` (zero-based). Only that slice of the file is in memory.
   * May be called again for the same index — after a failed request, any fresh encryption
   * of the chunk is equally valid.
   */
  encryptChunk(index: number): Promise<Uint8Array>;
  /** Zeroize the attachment key. Call when the upload is finished, failed or cancelled. */
  dispose(): void;
}

/**
 * Prepare a file for upload under an item: picks the attachment ID, generates the
 * attachment key, wraps it, and seals the filename and MIME type. No file content is read
 * until encryptChunk() is called.
 */
export async function prepareAttachment(
  file: Blob,
  metadata: { name: string; type: string },
  masterKey: Uint8Array,
  owner: ItemContext,
): Promise<PreparedAttachment> {
  if (file.size === 0) {
    throw new EmptyAttachmentError();
  }

  const context: AttachmentContext = { ...owner, attachmentId: generateAttachmentId() };
  assertValidContext(context);

  const chunkCount = chunkCountFor(file.size);
  const mimeField = new Uint8Array(ATTACHMENT_FORMAT.mimeFieldBytes); // zero-filled = the padding
  mimeField.set(utf8.encode(normalizeMimeType(metadata.type)));

  const key = new Uint8Array(AES_GCM.keyLengthBytes);
  globalThis.crypto.getRandomValues(key);
  const wrapKey = await deriveContentWrapKey(masterKey);

  let header: EncryptedAttachmentHeader;
  try {
    const [attachmentKeyWrapped, filenameEnc, mimeTypeEnc] = await Promise.all([
      seal(key, wrapKey, attachmentAad('attachment.key', context)),
      seal(utf8.encode(normalizeFilename(metadata.name)), key, attachmentAad('attachment.name', context)),
      seal(mimeField, key, attachmentAad('attachment.mime', context)),
    ]);

    header = {
      attachmentKeyWrapped: toBase64(attachmentKeyWrapped),
      filenameEnc: toBase64(filenameEnc),
      mimeTypeEnc: toBase64(mimeTypeEnc),
      aadVersion: ITEM_FORMAT_VERSION,
    };
  } catch (error) {
    zeroize(key);
    throw error;
  } finally {
    zeroize(wrapKey);
  }

  let disposed = false;

  return {
    attachmentId: context.attachmentId,
    header,
    sizeBytes: ciphertextSizeFor(file.size),
    chunkCount,
    async encryptChunk(index: number): Promise<Uint8Array> {
      if (disposed) {
        throw new Error('This attachment has been disposed.');
      }
      const aad = attachmentAad('attachment.chunk', context, { index, total: chunkCount });
      const start = index * CHUNK_PLAIN;
      const plain = new Uint8Array(await file.slice(start, start + CHUNK_PLAIN).arrayBuffer());

      return seal(plain, key, aad);
    },
    dispose(): void {
      disposed = true;
      zeroize(key);
    },
  };
}

/** Decrypt only the filename and MIME type (file lists). Throws AttachmentIntegrityError on any failure. */
export async function decryptAttachmentMetadata(
  header: EncryptedAttachmentHeader,
  masterKey: Uint8Array,
  context: AttachmentContext,
): Promise<AttachmentMetadata> {
  const key = await unwrapAttachmentKey(header, masterKey, context);
  try {
    return await openMetadata(header, key, context);
  } finally {
    zeroize(key);
  }
}

/**
 * Decrypt a downloaded attachment from a stream of ciphertext bytes (pieces of any size,
 * in order). Returns the metadata and the plaintext as chunks, ready for `new Blob(chunks)`.
 *
 * The total used in every chunk's AAD is the `chunkCount` the server reported, and the
 * stream must be exactly `sizeBytes` long: a lie about either, or any chunk failing its
 * tag, throws AttachmentIntegrityError and everything decrypted so far is wiped.
 */
export async function decryptAttachment(
  ciphertext: AsyncIterable<Uint8Array>,
  info: EncryptedAttachmentInfo,
  masterKey: Uint8Array,
  context: AttachmentContext,
  onProgress?: (receivedBytes: number, totalBytes: number) => void,
  /**
   * Optional: receives each authenticated plaintext chunk as soon as it is decrypted (e.g.
   * to wrap it in a Blob); the buffer is wiped right after, so the whole plaintext is never
   * held twice. With a sink, `chunks` comes back empty. The caller must still discard what
   * the sink collected if this throws — a later chunk can fail.
   */
  sink?: (plaintextChunk: Uint8Array) => void,
): Promise<AttachmentMetadata & { chunks: Uint8Array[] }> {
  if (!isPossibleSize(info.sizeBytes, info.chunkCount)) {
    throw new AttachmentIntegrityError('attachment.chunk', 'size does not fit the chunk count');
  }

  const key = await unwrapAttachmentKey(info, masterKey, context);
  const chunks: Uint8Array[] = [];

  try {
    const metadata = await openMetadata(info, key, context);

    const lastChunkBytes = info.sizeBytes - (info.chunkCount - 1) * CHUNK_BYTES;
    const expectedBytes = (index: number) => (index < info.chunkCount - 1 ? CHUNK_BYTES : lastChunkBytes);

    let index = 0;
    let received = 0;
    let buffer = new Uint8Array(expectedBytes(0));
    let filled = 0;

    for await (const piece of ciphertext) {
      received += piece.length;
      if (received > info.sizeBytes) {
        throw new AttachmentIntegrityError('attachment.chunk', 'more data than declared');
      }

      let offset = 0;
      while (offset < piece.length) {
        const take = Math.min(buffer.length - filled, piece.length - offset);
        buffer.set(piece.subarray(offset, offset + take), filled);
        filled += take;
        offset += take;

        if (filled === buffer.length) {
          const plain = await open(buffer, key, 'attachment.chunk', attachmentAad('attachment.chunk', context, { index, total: info.chunkCount }));
          if (sink) {
            sink(plain);
            zeroize(plain);
          } else {
            chunks.push(plain);
          }
          index++;
          filled = 0;
          buffer = index < info.chunkCount ? new Uint8Array(expectedBytes(index)) : new Uint8Array(0);
        }
      }

      onProgress?.(received, info.sizeBytes);
    }

    if (received !== info.sizeBytes || index !== info.chunkCount) {
      throw new AttachmentIntegrityError('attachment.chunk', 'less data than declared');
    }

    return { ...metadata, chunks };
  } catch (error) {
    chunks.forEach(zeroize);
    chunks.length = 0;
    throw error;
  } finally {
    zeroize(key);
  }
}
