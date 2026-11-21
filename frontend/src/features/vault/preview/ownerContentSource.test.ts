// @vitest-environment jsdom
/**
 * The owner's preview source — VAULT-8, docs/19_Decision_Log.md Decisions #30–#31.
 *
 * Real WebCrypto throughout (AI_RULES.md #6): items and files are encrypted with the real
 * crypto module and served by a fake API exactly as the server stores them. Damage to the
 * stored ciphertext — tampering, a swap between items, an item re-pointed at another
 * recipient, a truncated or reordered file — must show as "damaged" (would not be
 * delivered) and be reported with IDs and codes only; unfinished uploads must not appear.
 * (jsdom: the body sanitizer needs a DOM.)
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { prepareAttachment } from '../../../crypto/attachments';
import { generateMasterKey } from '../../../crypto';
import { createBundle, createItem } from '../../../crypto/items';
import type { AttachmentRecord, VaultItemRecord } from '../../../lib/api/types';

const { apiMock, apiStreamMock, reportItemFailure, reportAttachmentFailure } = vi.hoisted(() => ({
  apiMock: vi.fn(),
  apiStreamMock: vi.fn(),
  reportItemFailure: vi.fn(),
  reportAttachmentFailure: vi.fn(),
}));
vi.mock('../../../lib/api/client', () => ({ api: apiMock, apiStream: apiStreamMock }));
vi.mock('../integrityReporter', () => ({ reportItemFailure, reportAttachmentFailure }));

const { loadOwnerPreview } = await import('./ownerContentSource');

const USER = 4;
const LAYLA = 7;
const OMAR = 8;
const STAMP = '2026-11-20T10:00:00Z';

/** The fake server: what it stores, served the way the real API serves it. */
interface Stored {
  items: Map<number, VaultItemRecord[]>; // recipientId → items in list order
  attachments: Map<string, AttachmentRecord[]>; // itemId → attachments
  files: Map<string, Uint8Array[]>; // attachmentId → stored chunk envelopes
}

let stored: Stored;
let masterKey: Uint8Array;

function serve() {
  apiMock.mockImplementation(async (method: string, path: string) => {
    let match = path.match(/^\/recipients\/(\d+)\/items$/);
    if (method === 'GET' && match) {
      return { items: (stored.items.get(Number(match[1])) ?? []).map(({ contentEnc: _body, ...summary }) => summary) };
    }
    match = path.match(/^\/vault-items\/([0-9a-f-]+)$/);
    if (method === 'GET' && match) {
      const item = [...stored.items.values()].flat().find((i) => i.id === match![1]);
      return { item };
    }
    match = path.match(/^\/vault-items\/([0-9a-f-]+)\/attachments$/);
    if (method === 'GET' && match) {
      return { attachments: stored.attachments.get(match[1]) ?? [] };
    }
    throw new Error(`unexpected ${method} ${path}`);
  });
  apiStreamMock.mockImplementation(async (path: string) => {
    const id = path.match(/^\/attachments\/([0-9a-f-]+)\/content$/)![1];
    const chunks = stored.files.get(id)!;
    return (async function* () {
      yield* chunks;
    })();
  });
}

async function addMessage(recipientId: number, title: string, body: string, position: number): Promise<VaultItemRecord> {
  const { itemId, encrypted } = await createItem({ title, body }, masterKey, { userId: USER, recipientId });
  const record: VaultItemRecord = {
    id: itemId,
    recipientId,
    itemType: 'message',
    position,
    createdAt: STAMP,
    updatedAt: STAMP,
    ...encrypted,
  };
  stored.items.set(recipientId, [...(stored.items.get(recipientId) ?? []), record]);
  return record;
}

async function addBundle(recipientId: number, title: string, position: number): Promise<VaultItemRecord> {
  const { itemId, encrypted } = await createBundle(title, masterKey, { userId: USER, recipientId });
  const record: VaultItemRecord = {
    id: itemId,
    recipientId,
    itemType: 'file_bundle',
    position,
    createdAt: STAMP,
    updatedAt: STAMP,
    contentEnc: null,
    ...encrypted,
  };
  stored.items.set(recipientId, [...(stored.items.get(recipientId) ?? []), record]);
  return record;
}

async function addFile(item: VaultItemRecord, name: string, bytes: number, status: AttachmentRecord['status'] = 'complete'): Promise<AttachmentRecord> {
  const content = new Uint8Array(bytes).map((_, i) => i % 251);
  const prepared = await prepareAttachment(new Blob([content]), { name, type: 'application/pdf' }, masterKey, {
    userId: USER,
    recipientId: item.recipientId,
    itemId: item.id,
  });
  const chunks: Uint8Array[] = [];
  for (let i = 0; i < prepared.chunkCount; i++) {
    chunks.push(await prepared.encryptChunk(i));
  }
  prepared.dispose();

  const record: AttachmentRecord = {
    id: prepared.attachmentId,
    itemId: item.id,
    ...prepared.header,
    sizeBytes: prepared.sizeBytes,
    chunkCount: prepared.chunkCount,
    receivedChunks: status === 'complete' ? prepared.chunkCount : 0,
    status,
    createdAt: STAMP,
    updatedAt: STAMP,
  };
  stored.attachments.set(item.id, [...(stored.attachments.get(item.id) ?? []), record]);
  stored.files.set(record.id, chunks);
  return record;
}

describe('loadOwnerPreview', () => {
  beforeEach(() => {
    stored = { items: new Map(), attachments: new Map(), files: new Map() };
    masterKey = generateMasterKey();
    vi.clearAllMocks();
    serve();
  });

  it('opens what is stored, in delivery order, with every file verified', async () => {
    const letter = await addMessage(LAYLA, 'A letter — رسالة', '<p>Dear Layla <img src=x onerror="alert(1)"><a href="javascript:alert(1)">x</a></p>', 1);
    const docs = await addBundle(LAYLA, 'Documents', 0);
    const deed = await addFile(docs, 'deed.pdf', 1_048_576 + 5); // two chunks
    await addMessage(OMAR, 'For Omar', '<p>Not for Layla</p>', 0);

    const { content, files } = await loadOwnerPreview(masterKey, USER, LAYLA, 'Layla');

    expect(content.recipientName).toBe('Layla');
    expect(content.entries.map((e) => [e.id, e.status])).toEqual([
      [docs.id, 'ok'],
      [letter.id, 'ok'],
    ]);
    expect(content.entries[0]).toMatchObject({ kind: 'file_bundle', title: 'Documents', bodyHtml: null });
    expect(content.entries[0].files).toEqual([{ id: deed.id, status: 'ok', filename: 'deed.pdf', sizeBytes: 1_048_581 }]);
    // Sanitized after decrypting: no handler, no script URL.
    expect(content.entries[1].bodyHtml).toBe('<p dir="auto">Dear Layla x</p>');
    expect(files.get(deed.id)).toEqual({ itemId: docs.id, record: deed });
    expect(apiStreamMock).toHaveBeenCalledWith(`/attachments/${deed.id}/content`);
    expect(reportItemFailure).not.toHaveBeenCalled();
    expect(reportAttachmentFailure).not.toHaveBeenCalled();
  });

  it('shows a tampered body as damaged and reports it', async () => {
    const letter = await addMessage(LAYLA, 'A letter', '<p>Words</p>', 0);
    const ok = await addMessage(LAYLA, 'Another', '<p>Fine</p>', 1);
    const bytes = Uint8Array.from(atob(letter.contentEnc!), (c) => c.charCodeAt(0));
    bytes[bytes.length - 1] ^= 0x01;
    letter.contentEnc = btoa(String.fromCharCode(...bytes));

    const { content } = await loadOwnerPreview(masterKey, USER, LAYLA, 'Layla');

    expect(content.entries[0]).toEqual({ id: letter.id, kind: 'message', status: 'damaged', title: null, bodyHtml: null, files: [] });
    expect(content.entries[1]).toMatchObject({ id: ok.id, status: 'ok', title: 'Another' }); // one damaged entry hides nothing else
    expect(reportItemFailure).toHaveBeenCalledTimes(1);
    const [error, itemId, surface] = reportItemFailure.mock.calls[0];
    expect([error.part, error.reason, itemId, surface]).toEqual(['item.content', 'authentication_failed', letter.id, 'preview']);
  });

  it('fails both items when their ciphertexts are swapped', async () => {
    const a = await addMessage(LAYLA, 'A', '<p>A</p>', 0);
    const b = await addMessage(LAYLA, 'B', '<p>B</p>', 1);
    const swap = { contentKeyWrapped: a.contentKeyWrapped, titleEnc: a.titleEnc, contentEnc: a.contentEnc };
    Object.assign(a, { contentKeyWrapped: b.contentKeyWrapped, titleEnc: b.titleEnc, contentEnc: b.contentEnc });
    Object.assign(b, swap);

    const { content } = await loadOwnerPreview(masterKey, USER, LAYLA, 'Layla');

    expect(content.entries.map((e) => e.status)).toEqual(['damaged', 'damaged']);
    expect(reportItemFailure.mock.calls.map(([e, id]) => [e.part, id])).toEqual([
      ['item.key', a.id],
      ['item.key', b.id],
    ]);
  });

  it("does not open another recipient's item as this recipient's", async () => {
    const forLayla = await addMessage(LAYLA, 'For Layla only', '<p>Private</p>', 0);
    // Re-pointed at Omar in the database: the server now lists it under Omar.
    stored.items.delete(LAYLA);
    stored.items.set(OMAR, [{ ...forLayla, recipientId: OMAR }]);

    const { content } = await loadOwnerPreview(masterKey, USER, OMAR, 'Omar');

    expect(content.entries).toEqual([{ id: forLayla.id, kind: 'message', status: 'damaged', title: null, bodyHtml: null, files: [] }]);
    expect(JSON.stringify(content)).not.toContain('Private');
    expect(reportItemFailure).toHaveBeenCalledTimes(1);
  });

  it('marks a file with a missing last chunk as damaged and reports it', async () => {
    const docs = await addBundle(LAYLA, 'Documents', 0);
    const deed = await addFile(docs, 'deed.pdf', 1_048_576 + 5);
    stored.files.set(deed.id, stored.files.get(deed.id)!.slice(0, 1)); // truncated

    const { content } = await loadOwnerPreview(masterKey, USER, LAYLA, 'Layla');

    expect(content.entries[0].status).toBe('ok'); // the collection itself is fine
    expect(content.entries[0].files).toEqual([{ id: deed.id, status: 'damaged', filename: 'deed.pdf', sizeBytes: 1_048_581 }]);
    const [error, itemId, attachmentId, surface] = reportAttachmentFailure.mock.calls[0];
    expect([error.part, error.reason, itemId, attachmentId, surface]).toEqual(['attachment.chunk', 'length_mismatch', docs.id, deed.id, 'preview']);
  });

  it('marks a file with reordered chunks as damaged', async () => {
    const docs = await addBundle(LAYLA, 'Documents', 0);
    const deed = await addFile(docs, 'deed.pdf', 2 * 1_048_576);
    stored.files.set(deed.id, [...stored.files.get(deed.id)!].reverse());

    const { content } = await loadOwnerPreview(masterKey, USER, LAYLA, 'Layla');

    expect(content.entries[0].files[0].status).toBe('damaged');
    expect(reportAttachmentFailure.mock.calls[0][0].reason).toBe('authentication_failed');
  });

  it('leaves out uploads that never completed (in progress or cancelled)', async () => {
    const docs = await addBundle(LAYLA, 'Documents', 0);
    const done = await addFile(docs, 'done.pdf', 10);
    const hanging = await addFile(docs, 'hanging.pdf', 10, 'uploading');

    const { content, files } = await loadOwnerPreview(masterKey, USER, LAYLA, 'Layla');

    expect(content.entries[0].files.map((f) => f.id)).toEqual([done.id]);
    expect(files.has(hanging.id)).toBe(false);
    expect(apiStreamMock).not.toHaveBeenCalledWith(`/attachments/${hanging.id}/content`);
  });

  it('does not open the files of an item that is itself damaged', async () => {
    const docs = await addBundle(LAYLA, 'Documents', 0);
    await addFile(docs, 'deed.pdf', 10);
    docs.titleEnc = (await createBundle('Other', masterKey, { userId: USER, recipientId: LAYLA })).encrypted.titleEnc;

    const { content } = await loadOwnerPreview(masterKey, USER, LAYLA, 'Layla');

    expect(content.entries[0]).toMatchObject({ status: 'damaged', files: [] });
    expect(apiStreamMock).not.toHaveBeenCalled();
  });

  it('passes on errors that are not integrity failures', async () => {
    await addMessage(LAYLA, 'A', '<p>A</p>', 0);
    apiMock.mockRejectedValueOnce(new TypeError('network down'));

    await expect(loadOwnerPreview(masterKey, USER, LAYLA, 'Layla')).rejects.toThrow('network down');
  });
});
