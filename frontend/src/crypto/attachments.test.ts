/**
 * Attachment crypto — real WebCrypto, no mocks (AI_RULES.md #6, docs/15_Testing.md §2).
 * Covers the round trip, the cross-language known-answer vector, tampering, wrong
 * context/AAD, and chunks reordered, dropped, duplicated or truncated
 * (docs/06_Cryptography.md §12, docs/19_Decision_Log.md Decisions #21, #22, #24).
 */
import { describe, expect, it } from 'vitest';
import { ATTACHMENT_FORMAT } from './constants';
import { fromBase64, generateMasterKey, toBase64 } from './index';
import { createBundle, decryptItemTitle, deriveContentWrapKey, rotateBundle } from './items';
import {
  attachmentAad,
  AttachmentIntegrityError,
  chunkCountFor,
  ciphertextSizeFor,
  decryptAttachment,
  decryptAttachmentMetadata,
  EmptyAttachmentError,
  normalizeFilename,
  normalizeMimeType,
  plaintextSizeOf,
  prepareAttachment,
  type AttachmentContext,
  type EncryptedAttachmentInfo,
  type PreparedAttachment,
} from './attachments';

const { chunkPlaintextBytes: CHUNK_PLAIN, chunkBytes: CHUNK } = ATTACHMENT_FORMAT;
const OWNER = { userId: 1, recipientId: 7, itemId: '3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5b' };

/** Deterministic test data: byte i is i mod 251. */
const pattern = (length: number) => new Uint8Array(length).map((_, i) => i % 251);

async function encryptAll(prepared: PreparedAttachment): Promise<Uint8Array[]> {
  const chunks: Uint8Array[] = [];
  for (let index = 0; index < prepared.chunkCount; index++) {
    chunks.push(await prepared.encryptChunk(index));
  }
  return chunks;
}

const infoOf = (prepared: PreparedAttachment): EncryptedAttachmentInfo => ({
  ...prepared.header,
  sizeBytes: prepared.sizeBytes,
  chunkCount: prepared.chunkCount,
});

const contextOf = (prepared: PreparedAttachment): AttachmentContext => ({ ...OWNER, attachmentId: prepared.attachmentId });

/** Feed ciphertext to the decryptor in arbitrary network-sized pieces. */
async function* stream(bytes: Uint8Array, pieceSize = 65536 + 7): AsyncGenerator<Uint8Array> {
  for (let offset = 0; offset < bytes.length; offset += pieceSize) {
    yield bytes.subarray(offset, offset + pieceSize);
  }
}

function concat(parts: Uint8Array[]): Uint8Array {
  const out = new Uint8Array(parts.reduce((sum, part) => sum + part.length, 0));
  let offset = 0;
  for (const part of parts) {
    out.set(part, offset);
    offset += part.length;
  }
  return out;
}

async function sha256Hex(bytes: Uint8Array): Promise<string> {
  const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', bytes as BufferSource));
  return [...digest].map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function setup(plainBytes: number, name = 'will.pdf', type = 'application/pdf') {
  const masterKey = generateMasterKey();
  const data = pattern(plainBytes);
  const prepared = await prepareAttachment(new Blob([data]), { name, type }, masterKey, OWNER);
  const chunks = await encryptAll(prepared);

  return { masterKey, data, prepared, chunks, info: infoOf(prepared), context: contextOf(prepared) };
}

const expectIntegrityError = (promise: Promise<unknown>) => expect(promise).rejects.toBeInstanceOf(AttachmentIntegrityError);

describe('attachment round trip', () => {
  it('encrypts a small file into one chunk and decrypts name, type and content', async () => {
    const { masterKey, data, prepared, chunks, info, context } = await setup(1000, 'وصية — will.pdf');

    expect(prepared.chunkCount).toBe(1);
    expect(prepared.sizeBytes).toBe(1000 + 29);
    expect(chunks[0].length).toBe(1029);
    expect(chunks[0][0]).toBe(1);

    const result = await decryptAttachment(stream(chunks[0]), info, masterKey, context);
    expect(result.filename).toBe('وصية — will.pdf');
    expect(result.mimeType).toBe('application/pdf');
    expect(await sha256Hex(concat(result.chunks))).toBe(await sha256Hex(data));
    expect(await decryptAttachmentMetadata(prepared.header, masterKey, context)).toEqual({
      filename: 'وصية — will.pdf',
      mimeType: 'application/pdf',
    });
  });

  it('splits a larger file into full chunks plus a short last one, with distinct nonces', async () => {
    const { masterKey, data, prepared, chunks, info, context } = await setup(2 * CHUNK_PLAIN + 123);

    expect(chunks.map((c) => c.length)).toEqual([CHUNK, CHUNK, 123 + 29]);
    expect(prepared.sizeBytes).toBe(2 * CHUNK + 152);
    expect(ciphertextSizeFor(data.length)).toBe(prepared.sizeBytes);
    expect(chunkCountFor(data.length)).toBe(3);
    expect(plaintextSizeOf(prepared.sizeBytes, 3)).toBe(data.length);
    expect(new Set(chunks.map((c) => toBase64(c.subarray(1, 13)))).size).toBe(3);

    const progress: number[] = [];
    const result = await decryptAttachment(stream(concat(chunks)), info, masterKey, context, (received) => progress.push(received));
    expect(await sha256Hex(concat(result.chunks))).toBe(await sha256Hex(data));
    expect(progress.at(-1)).toBe(prepared.sizeBytes);
  });

  it('handles a file that is exactly one full chunk, and exactly two', async () => {
    for (const size of [CHUNK_PLAIN, 2 * CHUNK_PLAIN]) {
      const { masterKey, data, prepared, chunks, info, context } = await setup(size);
      expect(prepared.chunkCount).toBe(size / CHUNK_PLAIN);
      const result = await decryptAttachment(stream(concat(chunks), 999_983), info, masterKey, context);
      expect(await sha256Hex(concat(result.chunks))).toBe(await sha256Hex(data));
    }
  });

  it('keeps the name, the type and the content out of everything that is stored', async () => {
    const masterKey = generateMasterKey();
    const text = new TextEncoder().encode('My last will. The house goes to Layla.'.repeat(50));
    const prepared = await prepareAttachment(new Blob([text]), { name: 'will_final.pdf', type: 'application/pdf' }, masterKey, OWNER);
    const chunks = await encryptAll(prepared);

    const stored =
      atob(prepared.header.filenameEnc) + atob(prepared.header.mimeTypeEnc) + atob(prepared.header.attachmentKeyWrapped) +
      String.fromCharCode(...concat(chunks));
    for (const needle of ['will', 'pdf', 'application', 'Layla', 'house']) {
      expect(stored).not.toContain(needle);
    }
  });

  it('seals every MIME type to the same length', async () => {
    const masterKey = generateMasterKey();
    const lengths = await Promise.all(
      ['application/pdf', 'image/jpeg', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ''].map(
        async (type) => {
          const prepared = await prepareAttachment(new Blob(['x']), { name: 'f', type }, masterKey, OWNER);
          return fromBase64(prepared.header.mimeTypeEnc).length;
        },
      ),
    );
    expect(lengths).toEqual([157, 157, 157, 157]);
  });

  it('re-encrypting a chunk gives a new nonce and still decrypts (resume)', async () => {
    const { masterKey, data, prepared, chunks, info, context } = await setup(CHUNK_PLAIN + 10);
    const again = await prepared.encryptChunk(0);

    expect(toBase64(again.subarray(1, 13))).not.toBe(toBase64(chunks[0].subarray(1, 13)));
    const result = await decryptAttachment(stream(concat([again, chunks[1]])), info, masterKey, context);
    expect(await sha256Hex(concat(result.chunks))).toBe(await sha256Hex(data));
  });

  it('refuses an empty file, and refuses to encrypt after dispose', async () => {
    const masterKey = generateMasterKey();
    await expect(prepareAttachment(new Blob([]), { name: 'empty', type: '' }, masterKey, OWNER)).rejects.toBeInstanceOf(
      EmptyAttachmentError,
    );

    const prepared = await prepareAttachment(new Blob(['x']), { name: 'f', type: '' }, masterKey, OWNER);
    prepared.dispose();
    await expect(prepared.encryptChunk(0)).rejects.toThrow();
  });
});

describe('chunks reordered, dropped, duplicated or truncated', () => {
  it('rejects every rearrangement of a three-chunk file', async () => {
    const { masterKey, prepared, chunks, info, context } = await setup(2 * CHUNK_PLAIN + 100);
    const [c0, c1, c2] = chunks;
    const open = (parts: Uint8Array[], overrides: Partial<EncryptedAttachmentInfo> = {}) =>
      decryptAttachment(stream(concat(parts)), { ...info, ...overrides }, masterKey, context);

    await expect(open([c0, c1, c2])).resolves.toBeDefined();

    // Reordered (the two full chunks swapped — sizes still line up).
    await expectIntegrityError(open([c1, c0, c2]));
    // Duplicated.
    await expectIntegrityError(open([c0, c0, c2]));
    // Dropped middle chunk: as is, and with size and count "fixed up" to match.
    await expectIntegrityError(open([c0, c2]));
    await expectIntegrityError(open([c0, c2], { chunkCount: 2, sizeBytes: CHUNK + c2.length }));
    // Truncated: the last chunk gone, with and without matching metadata.
    await expectIntegrityError(open([c0, c1]));
    await expectIntegrityError(open([c0, c1], { chunkCount: 2, sizeBytes: 2 * CHUNK }));
    // Truncated inside the last chunk; trailing bytes; an extra chunk.
    await expectIntegrityError(open([c0, c1, c2.subarray(0, c2.length - 1)]));
    await expectIntegrityError(open([c0, c1, c2, new Uint8Array([0])]));
    await expectIntegrityError(open([c0, c1, c2, c2], { chunkCount: 4, sizeBytes: prepared.sizeBytes + c2.length }));
    // An empty stream.
    await expectIntegrityError(open([]));
  });

  it('rejects a chunk transplanted from another attachment', async () => {
    const a = await setup(CHUNK_PLAIN + 5);
    // Same master key, same item, another attachment with identical content.
    const otherPrepared = await prepareAttachment(new Blob([a.data]), { name: 'will.pdf', type: 'application/pdf' }, a.masterKey, OWNER);
    const otherChunks = await encryptAll(otherPrepared);

    await expectIntegrityError(decryptAttachment(stream(concat([a.chunks[0], otherChunks[1]])), a.info, a.masterKey, a.context));
  });

  it('rejects a size that the chunk count cannot produce', async () => {
    const { masterKey, chunks, info, context } = await setup(100);

    for (const overrides of [{ chunkCount: 0 }, { chunkCount: 2 }, { sizeBytes: 29 }, { sizeBytes: CHUNK + 1 }, { chunkCount: 1.5 }]) {
      await expectIntegrityError(decryptAttachment(stream(chunks[0]), { ...info, ...overrides }, masterKey, context));
    }
  });
});

describe('tamper and wrong-context rejection', () => {
  it('rejects a flipped bit anywhere in the file', async () => {
    const { masterKey, chunks, info, context } = await setup(CHUNK_PLAIN + 300);
    const file = concat(chunks);

    for (const offset of [0, 5, 20, 500_000, CHUNK - 1, CHUNK, CHUNK + 15, file.length - 1]) {
      const tampered = file.slice();
      tampered[offset] ^= 0x01;
      await expectIntegrityError(decryptAttachment(stream(tampered), info, masterKey, context));
    }
  });

  it('rejects a flipped bit in each sealed value, and swapped name and type', async () => {
    const { masterKey, chunks, info, context } = await setup(100);
    const flip = (valueB64: string) => {
      const bytes = fromBase64(valueB64);
      bytes[20] ^= 0x01;
      return toBase64(bytes);
    };

    for (const part of ['filenameEnc', 'mimeTypeEnc', 'attachmentKeyWrapped'] as const) {
      await expectIntegrityError(decryptAttachment(stream(chunks[0]), { ...info, [part]: flip(info[part]) }, masterKey, context));
      await expectIntegrityError(decryptAttachmentMetadata({ ...info, [part]: flip(info[part]) }, masterKey, context));
    }

    await expectIntegrityError(decryptAttachmentMetadata({ ...info, filenameEnc: info.mimeTypeEnc }, masterKey, context));
    await expectIntegrityError(decryptAttachmentMetadata({ ...info, mimeTypeEnc: info.filenameEnc }, masterKey, context));
    await expectIntegrityError(decryptAttachmentMetadata({ ...info, filenameEnc: 'not base64!' }, masterKey, context));
    await expectIntegrityError(decryptAttachmentMetadata({ ...info, aadVersion: 2 }, masterKey, context));
  });

  it('rejects the wrong user, recipient, item, attachment and master key (wrong AAD)', async () => {
    const { masterKey, chunks, info, context } = await setup(100);
    const open = (ctx: AttachmentContext, key = masterKey) => decryptAttachment(stream(chunks[0]), info, key, ctx);

    await expect(open(context)).resolves.toBeDefined();
    await expectIntegrityError(open({ ...context, userId: 2 }));
    await expectIntegrityError(open({ ...context, recipientId: 8 }));
    await expectIntegrityError(open({ ...context, itemId: '3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5c' }));
    await expectIntegrityError(open({ ...context, attachmentId: 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d' }));
    await expectIntegrityError(open(context, generateMasterKey()));
  });

  it('wipes what was already decrypted when a later chunk fails', async () => {
    const { masterKey, chunks, info, context } = await setup(CHUNK_PLAIN + 50);
    const bad = chunks[1].slice();
    bad[40] ^= 0x01;

    // Nothing is returned: the promise rejects, there is no partial file to use.
    await expectIntegrityError(decryptAttachment(stream(concat([chunks[0], bad])), info, masterKey, context));
  });

  it('refuses malformed contexts and chunk positions when building AAD', () => {
    const context = { ...OWNER, attachmentId: 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d' };

    expect(() => attachmentAad('attachment.key', { ...context, attachmentId: 'A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C5D' })).toThrow(TypeError);
    expect(() => attachmentAad('attachment.key', { ...context, userId: 0 })).toThrow(TypeError);
    expect(() => attachmentAad('attachment.chunk', context)).toThrow(TypeError);
    expect(() => attachmentAad('attachment.chunk', context, { index: 2, total: 2 })).toThrow(TypeError);
    expect(() => attachmentAad('attachment.chunk', context, { index: -1, total: 2 })).toThrow(TypeError);
    expect(() => attachmentAad('attachment.name', context, { index: 0, total: 1 })).toThrow(TypeError);
  });
});

describe('cross-language known-answer vector (backend/tests/Unit/AttachmentVectorTest.php)', () => {
  // Master Key = bytes 0x00…0x1f, attachment key = bytes 0x40…0x5f,
  // nonces 12×0x04 (key), 0x05 (name), 0x06 (type), 0x10 and 0x11 (chunks 0 and 1).
  const masterKey = new Uint8Array(32).map((_, i) => i);
  const attachmentKey = new Uint8Array(32).map((_, i) => i + 64);
  const context: AttachmentContext = {
    userId: 42,
    recipientId: 7,
    itemId: '3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5b',
    attachmentId: 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
  };
  const filename = 'وصية — will.pdf';
  const content = pattern(CHUNK_PLAIN + 5);
  const pinned: EncryptedAttachmentInfo = {
    filenameEnc: 'AQUFBQUFBQUFBQUFBTdUINsj3NQ+BOqPAFFpSq5Tp5Chbke6/My9g+cM6N2ZKIbuSOk=',
    mimeTypeEnc:
      'AQYGBgYGBgYGBgYGBvl0RVbBLUv7rPYhhwB5m3tfpj+Ar1P2Fo67jproY5BnQPM/Q0mAU7tSLZ65+349CQjL9R64OgVJAthpZvVoSQni0s7Yn3hac0E52ojNcdcWYXAqhHcOey0DaSH77h7QrFC4ZkR8gWwLDR4dSevIwx3BFvq35Do0SlJyfVxX0jrcb3Dh2SXx4mWSMOxlXot2aw==',
    attachmentKeyWrapped: 'AQQEBAQEBAQEBAQEBDvmKK5ACW6WgDLThktsNC1HSaqcYIQuzTjlCdmynGzBnskTiiIZyZ3RucgR3QZFQg==',
    sizeBytes: 1048639,
    chunkCount: 2,
    aadVersion: 1,
  };
  const pinnedFileSha256 = '393f19225f1a665167114a4e4c1b53c3d0498da84d806420e0f7de494e5bb7aa';
  const pinnedFinalChunk = 'AREREREREREREREREW57MoE23wv+V0NUcG6wgBnkMVznGA==';

  // The module never accepts a caller-chosen key or nonce, so the encrypt direction is
  // reproduced with raw WebCrypto over the module's own AAD.
  const sealFixed = async (plaintext: Uint8Array, key: Uint8Array, aad: Uint8Array, nonceByte: number) => {
    const nonce = new Uint8Array(12).fill(nonceByte);
    const aesKey = await crypto.subtle.importKey('raw', key as BufferSource, 'AES-GCM', false, ['encrypt']);
    const ciphertext = new Uint8Array(
      await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv: nonce, additionalData: aad as BufferSource, tagLength: 128 },
        aesKey,
        plaintext as BufferSource,
      ),
    );
    return concat([new Uint8Array([1]), nonce, ciphertext]);
  };

  const pinnedFile = async () =>
    concat([
      await sealFixed(content.subarray(0, CHUNK_PLAIN), attachmentKey, attachmentAad('attachment.chunk', context, { index: 0, total: 2 }), 0x10),
      await sealFixed(content.subarray(CHUNK_PLAIN), attachmentKey, attachmentAad('attachment.chunk', context, { index: 1, total: 2 }), 0x11),
    ]);

  it('pins the format constants', () => {
    expect(ATTACHMENT_FORMAT.chunkPlaintextBytes).toBe(1048576);
    expect(ATTACHMENT_FORMAT.chunkBytes).toBe(1048605);
    expect(ATTACHMENT_FORMAT.mimeFieldBytes).toBe(128);
  });

  it('pins the AAD strings', () => {
    expect(new TextDecoder().decode(attachmentAad('attachment.name', context))).toBe(
      'legacy-vault|v1|attachment.name|user:42|recipient:7|item:3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5b|attachment:a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
    );
    expect(new TextDecoder().decode(attachmentAad('attachment.chunk', context, { index: 1, total: 2 }))).toBe(
      'legacy-vault|v1|attachment.chunk|user:42|recipient:7|item:3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5b|attachment:a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d|chunk:1/2',
    );
  });

  it('reproduces the same bytes with WebCrypto given the same key and nonces', async () => {
    const utf8 = new TextEncoder();
    const mimeField = new Uint8Array(128);
    mimeField.set(utf8.encode('application/pdf'));
    const wrapKey = await deriveContentWrapKey(masterKey);

    expect(toBase64(await sealFixed(attachmentKey, wrapKey, attachmentAad('attachment.key', context), 4))).toBe(pinned.attachmentKeyWrapped);
    expect(toBase64(await sealFixed(utf8.encode(filename), attachmentKey, attachmentAad('attachment.name', context), 5))).toBe(pinned.filenameEnc);
    expect(toBase64(await sealFixed(mimeField, attachmentKey, attachmentAad('attachment.mime', context), 6))).toBe(pinned.mimeTypeEnc);

    const file = await pinnedFile();
    expect(file.length).toBe(pinned.sizeBytes);
    expect(toBase64(file.subarray(CHUNK))).toBe(pinnedFinalChunk);
    expect(await sha256Hex(file)).toBe(pinnedFileSha256);
  });

  it('opens what PHP/OpenSSL sealed', async () => {
    const result = await decryptAttachment(stream(await pinnedFile()), pinned, masterKey, context);

    expect(result.filename).toBe(filename);
    expect(result.mimeType).toBe('application/pdf');
    expect(await sha256Hex(concat(result.chunks))).toBe(await sha256Hex(content));
    expect(await decryptAttachmentMetadata(pinned, masterKey, context)).toEqual({ filename, mimeType: 'application/pdf' });
  });
});

describe('filename and MIME type normalization', () => {
  it('strips path separators and control characters and never returns an empty name', () => {
    expect(normalizeFilename('../../etc/passwd')).toBe('....etcpasswd');
    expect(normalizeFilename('C:\\Users\\me\\will.pdf')).toBe('C:Usersmewill.pdf');
    expect(normalizeFilename('a\u0000b\nc\u007f.txt')).toBe('abc.txt');
    expect(normalizeFilename('   ')).toBe('file');
    expect(normalizeFilename('..')).toBe('file');
    expect(normalizeFilename('وصية.pdf')).toBe('وصية.pdf');
  });

  it('shortens an over-long name on character boundaries, keeping the extension', () => {
    const long = normalizeFilename(`${'a'.repeat(400)}.pdf`);
    expect([...long].length).toBe(255);
    expect(long.endsWith('.pdf')).toBe(true);

    // 255 four-byte characters would be 1,020 bytes — over the 995-byte cap.
    const emoji = normalizeFilename(`${'😀'.repeat(255)}.png`);
    expect(new TextEncoder().encode(emoji).length).toBeLessThanOrEqual(995);
    expect(emoji.endsWith('.png')).toBe(true);
    expect(emoji).not.toContain('\ufffd');
  });

  it('falls back to a generic type when the browser gives none or something odd', () => {
    expect(normalizeMimeType('application/pdf')).toBe('application/pdf');
    expect(normalizeMimeType('')).toBe('application/octet-stream');
    expect(normalizeMimeType('text/plain; charset=utf-8')).toBe('application/octet-stream'); // contains a space
    expect(normalizeMimeType(`x/${'y'.repeat(200)}`)).toBe('application/octet-stream');
  });
});

describe('file bundles (docs/19_Decision_Log.md Decision #27)', () => {
  it('creates a title-only item, with no body ciphertext at all, and renames it under a new key', async () => {
    const masterKey = generateMasterKey();
    const { itemId, encrypted } = await createBundle('House documents — أوراق البيت', masterKey, { userId: 1, recipientId: 7 });
    const context = { userId: 1, recipientId: 7, itemId };

    expect(encrypted).not.toHaveProperty('contentEnc');
    expect(await decryptItemTitle(encrypted, masterKey, context)).toBe('House documents — أوراق البيت');

    const renamed = await rotateBundle('Renamed', masterKey, context);
    expect(renamed).not.toHaveProperty('contentEnc');
    expect(renamed.contentKeyWrapped).not.toBe(encrypted.contentKeyWrapped);
    expect(await decryptItemTitle(renamed, masterKey, context)).toBe('Renamed');
  });
});
