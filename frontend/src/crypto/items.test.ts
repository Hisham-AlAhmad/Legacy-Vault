/**
 * Vault item crypto — real WebCrypto, no mocks (AI_RULES.md #6, docs/15_Testing.md §2).
 * Covers the round trip, the cross-language known-answer vector, tamper and wrong-context
 * rejection, and key/nonce freshness on every edit (docs/06_Cryptography.md §8–10, §13).
 */
import { describe, expect, it } from 'vitest';
import { AES_GCM, ITEM_FORMAT_VERSION, ITEM_LIMITS } from './constants';
import { decryptContent, deriveMetadataKey, fromBase64, generateMasterKey, toBase64 } from './index';
import {
  createItem,
  decryptItem,
  decryptItemTitle,
  deriveContentWrapKey,
  generateItemId,
  itemAad,
  ItemIntegrityError,
  ItemTooLargeError,
  rotateItem,
  type EncryptedItem,
  type ItemContext,
} from './items';

const PLAIN = {
  title: 'For Layla, when the time comes — إلى ليلى',
  body: '<p dir="auto">My dearest Layla.</p><p dir="auto">رسالتي الأخيرة، مع كل الحب.</p>',
};

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

/** Unwrap an item's content key the long way, so tests can compare keys across edits. */
async function contentKeyOf(item: EncryptedItem, masterKey: Uint8Array, context: ItemContext): Promise<string> {
  const envelope = fromBase64(item.contentKeyWrapped);
  const key = await decryptContent(
    envelope.subarray(13),
    envelope.subarray(1, 13),
    await deriveContentWrapKey(masterKey),
    itemAad('item.key', context),
  );
  expect(key).not.toBeNull();
  return toBase64(key!);
}

const nonceOf = (envelopeB64: string) => toBase64(fromBase64(envelopeB64).subarray(1, 13));

function flipBit(envelopeB64: string, index: number): string {
  const bytes = fromBase64(envelopeB64);
  bytes[index] ^= 0x01;
  return toBase64(bytes);
}

describe('vault item round trip', () => {
  it('creates an item with a fresh UUIDv4 and decrypts title and body', async () => {
    const masterKey = generateMasterKey();
    const { itemId, encrypted } = await createItem(PLAIN, masterKey, { userId: 1, recipientId: 7 });
    const context = { userId: 1, recipientId: 7, itemId };

    expect(itemId).toMatch(UUID_V4);
    expect(encrypted.aadVersion).toBe(ITEM_FORMAT_VERSION);
    expect(await decryptItem(encrypted, masterKey, context)).toEqual(PLAIN);
    expect(await decryptItemTitle(encrypted, masterKey, context)).toBe(PLAIN.title);
  });

  it('produces three envelopes: version byte, own nonce, ciphertext, tag', async () => {
    const masterKey = generateMasterKey();
    const { encrypted } = await createItem(PLAIN, masterKey, { userId: 1, recipientId: 7 });

    const key = fromBase64(encrypted.contentKeyWrapped);
    const title = fromBase64(encrypted.titleEnc);
    const content = fromBase64(encrypted.contentEnc);

    expect(key.length).toBe(1 + AES_GCM.nonceLengthBytes + 32 + 16); // 61
    expect(title.length).toBe(29 + new TextEncoder().encode(PLAIN.title).length);
    expect(content.length).toBe(29 + new TextEncoder().encode(PLAIN.body).length);
    expect([key[0], title[0], content[0]]).toEqual([1, 1, 1]);

    // Title and body share the content key, so they must never share a nonce.
    const nonces = [encrypted.contentKeyWrapped, encrypted.titleEnc, encrypted.contentEnc].map(nonceOf);
    expect(new Set(nonces).size).toBe(3);
  });

  it('keeps plaintext out of the ciphertext', async () => {
    const masterKey = generateMasterKey();
    const { encrypted } = await createItem(PLAIN, masterKey, { userId: 1, recipientId: 7 });

    const wire = atob(encrypted.titleEnc) + atob(encrypted.contentEnc) + atob(encrypted.contentKeyWrapped);
    expect(wire).not.toContain('Layla');
    expect(wire).not.toContain('dearest');
  });

  it('generates distinct item IDs', () => {
    const ids = new Set(Array.from({ length: 50 }, () => generateItemId()));
    expect(ids.size).toBe(50);
    ids.forEach((id) => expect(id).toMatch(UUID_V4));
  });
});

describe('cross-language known-answer vector (backend/tests/Unit/VaultItemVectorTest.php)', () => {
  // Master Key = bytes 0x00…0x1f, content key = bytes 0x20…0x3f, nonces 12×0x01/0x02/0x03.
  const masterKey = new Uint8Array(32).map((_, i) => i);
  const contentKey = new Uint8Array(32).map((_, i) => i + 32);
  const context = { userId: 42, recipientId: 7, itemId: '3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5b' };
  const title = 'For Layla — إلى ليلى';
  const body = '<p dir="auto">Read this first. اقرئي هذا أولاً.</p>';
  const pinned: EncryptedItem = {
    titleEnc: 'AQICAgICAgICAgICAithR5tgbZLyhW9Ai+7nlSEy0KIkg8FzMPJOPQqKO8JcTmt/saOcgTe6QoA6oA==',
    contentEnc:
      'AQMDAwMDAwMDAwMDAwmiZzKAsgr+99ebC58AubKhNYq6RfoRx+rLJc+u7JWazIAhi7IJvokctc9YSQEK8Lrl+PmrzivGNsBkU/+N7phTx49bqTeSER70jDzutojk',
    contentKeyWrapped: 'AQEBAQEBAQEBAQEBAd2Yih6NjZUfPfT1AwvLnvz9eNrfJdOxhn2LyzjL2OoIT+Vy8BVrdgMkKbnbjUP7xw==',
    aadVersion: 1,
  };

  it('derives the pinned Content-Wrap Key, distinct from the Metadata Key', async () => {
    const wrapKey = await deriveContentWrapKey(masterKey);

    expect(toBase64(wrapKey)).toBe('70SHEEyckdayOFBnIvxIcu6dV5yXiYPoDgC473e1Nm0=');
    expect(toBase64(wrapKey)).not.toBe(toBase64(await deriveMetadataKey(masterKey)));
  });

  it('pins the AAD string', () => {
    expect(new TextDecoder().decode(itemAad('item.content', context))).toBe(
      'legacy-vault|v1|item.content|user:42|recipient:7|item:3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5b',
    );
  });

  it('opens what PHP/OpenSSL sealed', async () => {
    expect(await decryptItem(pinned, masterKey, context)).toEqual({ title, body });
    expect(await decryptItemTitle(pinned, masterKey, context)).toBe(title);
    expect(await contentKeyOf(pinned, masterKey, context)).toBe(toBase64(contentKey));
  });

  it('reproduces the same bytes with WebCrypto given the same key and nonces', async () => {
    // The module never accepts a caller-chosen nonce, so the encrypt direction is checked
    // with raw WebCrypto over the module's own AAD and derived wrap key.
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
      return toBase64(new Uint8Array([1, ...nonce, ...ciphertext]));
    };
    const utf8 = new TextEncoder();
    const wrapKey = await deriveContentWrapKey(masterKey);

    expect(await sealFixed(contentKey, wrapKey, itemAad('item.key', context), 1)).toBe(pinned.contentKeyWrapped);
    expect(await sealFixed(utf8.encode(title), contentKey, itemAad('item.title', context), 2)).toBe(pinned.titleEnc);
    expect(await sealFixed(utf8.encode(body), contentKey, itemAad('item.content', context), 3)).toBe(pinned.contentEnc);
  });
});

describe('tamper and wrong-context rejection', () => {
  const setup = async () => {
    const masterKey = generateMasterKey();
    const { itemId, encrypted } = await createItem(PLAIN, masterKey, { userId: 1, recipientId: 7 });
    return { masterKey, encrypted, context: { userId: 1, recipientId: 7, itemId } };
  };

  it('throws ItemIntegrityError — never null or empty — when any envelope is tampered with', async () => {
    const { masterKey, encrypted, context } = await setup();

    for (const [field, part] of [
      ['contentKeyWrapped', 'item.key'],
      ['titleEnc', 'item.title'],
      ['contentEnc', 'item.content'],
    ] as const) {
      const last = fromBase64(encrypted[field]).length - 1;

      // Flip one bit in the nonce, the ciphertext, and the tag in turn.
      for (const index of [5, 20, last]) {
        const tampered = { ...encrypted, [field]: flipBit(encrypted[field], index) };
        const error = await decryptItem(tampered, masterKey, context).catch((e: unknown) => e);

        expect(error).toBeInstanceOf(ItemIntegrityError);
        expect((error as ItemIntegrityError).part).toBe(part);
      }
    }
  });

  it('rejects the wrong item, the wrong recipient and the wrong user', async () => {
    const { masterKey, encrypted, context } = await setup();

    const wrongContexts: ItemContext[] = [
      { ...context, itemId: generateItemId() },
      { ...context, recipientId: 8 }, // recipient B's context
      { ...context, userId: 2 },
    ];

    for (const wrong of wrongContexts) {
      await expect(decryptItem(encrypted, masterKey, wrong)).rejects.toBeInstanceOf(ItemIntegrityError);
      await expect(decryptItemTitle(encrypted, masterKey, wrong)).rejects.toBeInstanceOf(ItemIntegrityError);
    }

    // The right context still works — the failures above weren't incidental.
    expect((await decryptItem(encrypted, masterKey, context)).title).toBe(PLAIN.title);
  });

  it("rejects one recipient's item replayed under another recipient, same user, same ID", async () => {
    const masterKey = generateMasterKey();
    const forA = await createItem(PLAIN, masterKey, { userId: 1, recipientId: 7 });

    await expect(
      decryptItem(forA.encrypted, masterKey, { userId: 1, recipientId: 9, itemId: forA.itemId }),
    ).rejects.toBeInstanceOf(ItemIntegrityError);
  });

  it('rejects title and body swapped for each other', async () => {
    const { masterKey, encrypted, context } = await setup();
    const swapped = { ...encrypted, titleEnc: encrypted.contentEnc, contentEnc: encrypted.titleEnc };

    await expect(decryptItem(swapped, masterKey, context)).rejects.toBeInstanceOf(ItemIntegrityError);
    await expect(decryptItemTitle(swapped, masterKey, context)).rejects.toBeInstanceOf(ItemIntegrityError);
  });

  it('rejects parts mixed from two versions of the same item', async () => {
    const { masterKey, encrypted, context } = await setup();
    const next = await rotateItem({ title: 'New', body: '<p>New</p>' }, masterKey, context);

    // Old body with the new key: the old content key is gone, so this can't authenticate.
    await expect(decryptItem({ ...next, contentEnc: encrypted.contentEnc }, masterKey, context)).rejects.toBeInstanceOf(
      ItemIntegrityError,
    );
  });

  it('rejects another Master Key, a malformed envelope and an unknown version', async () => {
    const { masterKey, encrypted, context } = await setup();

    await expect(decryptItem(encrypted, generateMasterKey(), context)).rejects.toBeInstanceOf(ItemIntegrityError);
    await expect(decryptItem({ ...encrypted, titleEnc: '!!not base64!!' }, masterKey, context)).rejects.toBeInstanceOf(
      ItemIntegrityError,
    );
    await expect(decryptItem({ ...encrypted, contentEnc: toBase64(new Uint8Array(10)) }, masterKey, context)).rejects.toBeInstanceOf(
      ItemIntegrityError,
    );
    await expect(decryptItem({ ...encrypted, aadVersion: 2 }, masterKey, context)).rejects.toBeInstanceOf(ItemIntegrityError);

    const wrongVersion = fromBase64(encrypted.titleEnc);
    wrongVersion[0] = 2;
    await expect(decryptItem({ ...encrypted, titleEnc: toBase64(wrongVersion) }, masterKey, context)).rejects.toBeInstanceOf(
      ItemIntegrityError,
    );
  });
});

describe('key rotation on edit (docs/06_Cryptography.md §10)', () => {
  it('uses a fresh content key and fresh nonces on every edit, even with unchanged content', async () => {
    const masterKey = generateMasterKey();
    const { itemId, encrypted: v1 } = await createItem(PLAIN, masterKey, { userId: 1, recipientId: 7 });
    const context = { userId: 1, recipientId: 7, itemId };

    const v2 = await rotateItem(PLAIN, masterKey, context); // identical plaintext
    const v3 = await rotateItem({ ...PLAIN, title: 'Edited' }, masterKey, context);
    const versions = [v1, v2, v3];

    const keys = await Promise.all(versions.map((v) => contentKeyOf(v, masterKey, context)));
    expect(new Set(keys).size).toBe(3);

    const nonces = versions.flatMap((v) => [v.contentKeyWrapped, v.titleEnc, v.contentEnc].map(nonceOf));
    expect(new Set(nonces).size).toBe(9);

    // Same plaintext, different ciphertext everywhere.
    expect(v2.titleEnc).not.toBe(v1.titleEnc);
    expect(v2.contentEnc).not.toBe(v1.contentEnc);
    expect(v2.contentKeyWrapped).not.toBe(v1.contentKeyWrapped);

    expect(await decryptItem(v2, masterKey, context)).toEqual(PLAIN);
    expect((await decryptItem(v3, masterKey, context)).title).toBe('Edited');
  });

  it('gives every new item its own key', async () => {
    const masterKey = generateMasterKey();
    const a = await createItem(PLAIN, masterKey, { userId: 1, recipientId: 7 });
    const b = await createItem(PLAIN, masterKey, { userId: 1, recipientId: 7 });

    const keyA = await contentKeyOf(a.encrypted, masterKey, { userId: 1, recipientId: 7, itemId: a.itemId });
    const keyB = await contentKeyOf(b.encrypted, masterKey, { userId: 1, recipientId: 7, itemId: b.itemId });

    expect(a.itemId).not.toBe(b.itemId);
    expect(keyA).not.toBe(keyB);
  });
});

describe('input guards', () => {
  it('refuses a context that is not integers plus a canonical UUIDv4', async () => {
    const masterKey = generateMasterKey();
    const bad: ItemContext[] = [
      { userId: 1, recipientId: 7, itemId: 'not-a-uuid' },
      { userId: 1, recipientId: 7, itemId: '3F2B8C1E-9A4D-4E6F-8B7A-0C1D2E3F4A5B' },
      { userId: 1, recipientId: 7, itemId: '6ba7b810-9dad-11d1-80b4-00c04fd430c8' }, // v1
      { userId: 0, recipientId: 7, itemId: generateItemId() },
      { userId: 1, recipientId: 1.5, itemId: generateItemId() },
    ];

    for (const context of bad) {
      await expect(rotateItem(PLAIN, masterKey, context)).rejects.toBeInstanceOf(TypeError);
      expect(() => itemAad('item.title', context)).toThrow(TypeError);
    }
  });

  it('refuses plaintext that would exceed the server caps', async () => {
    const masterKey = generateMasterKey();
    const owner = { userId: 1, recipientId: 7 };

    await expect(createItem({ title: 'x'.repeat(ITEM_LIMITS.titleChars + 1), body: '<p>x</p>' }, masterKey, owner)).rejects.toBeInstanceOf(
      ItemTooLargeError,
    );
    await expect(
      createItem({ title: 'ok', body: 'x'.repeat(ITEM_LIMITS.contentEnvelopeBytes) }, masterKey, owner),
    ).rejects.toBeInstanceOf(ItemTooLargeError);

    // 200 four-byte characters is the worst-case title and still fits its envelope cap.
    const widest = await createItem({ title: '𝄞'.repeat(ITEM_LIMITS.titleChars), body: '<p>x</p>' }, masterKey, owner);
    expect(fromBase64(widest.encrypted.titleEnc).length).toBeLessThanOrEqual(ITEM_LIMITS.titleEnvelopeBytes);
  });
});
