/**
 * Crypto module tests — docs/15_Testing.md §2: fixed known-answer vectors, round trips,
 * and tamper tests, against real crypto (real Argon2id, real AES-256-GCM). No mocks,
 * no disabled-crypto path (AI_RULES.md #6).
 */
import { describe, expect, it } from 'vitest';
import {
  decryptContent,
  deriveKeyFromPassword,
  deriveMetadataKey,
  deriveKeyFromRecoveryKey,
  encryptContent,
  fromBase64,
  generateKdfSalt,
  generateMasterKey,
  metadataAad,
  openField,
  sealField,
  toBase64,
  unwrapKey,
  wrapKey,
} from './index';
import { generateRecoveryKey, normalizeAndValidateRecoveryKey } from './recoveryKey';
import { AES_GCM, ARGON2ID_KDF } from './constants';

describe('Argon2id key derivation', () => {
  it('matches the Argon2 reference implementation known-answer vector', async () => {
    // Reference KAT (RustCrypto password-hashes argon2/tests/kat.rs,
    // reference_argon2id_v0x13_2_16_1): argon2id v1.3, password="password",
    // salt="somesalt", t=2, m=65536, p=1 → pinned hex. Verifies the underlying
    // implementation against the reference, independent of our parameters.
    const { argon2id } = await import('hash-wasm');
    const hex = await argon2id({
      password: 'password',
      salt: new TextEncoder().encode('somesalt'),
      memorySize: 65536,
      iterations: 2,
      parallelism: 1,
      hashLength: 32,
      outputType: 'hex',
    });

    expect(hex).toBe('09316115d5cf24ed5a15a31a3ba326e5cf32edc24702987c02b6566f61913cf7');
  });

  it('matches the pinned project vector for the documented PDK parameters', async () => {
    // Project regression vector: argon2id(password="legacy-vault-test-vector",
    // salt=16×0x01, m=64 MiB, t=3, p=4, 32 bytes) — pinned after verifying the library
    // against the reference KAT above. A diff here means the KDF changed and requires
    // explicit sign-off, never a silent snapshot update (docs/15_Testing.md §8).
    const salt = new Uint8Array(16).fill(0x01);
    const key = await deriveKeyFromPassword('legacy-vault-test-vector', salt);

    expect(key.length).toBe(32);
    expect(toBase64(key)).toBe('bRVt4z101k56f663+yHehcpgGUogUNAAKUS8zqybqH8=');
  });

  it('uses the documented cost parameters (docs/06_Cryptography.md §6)', () => {
    expect(ARGON2ID_KDF.memorySizeKib).toBe(65536);
    expect(ARGON2ID_KDF.iterations).toBe(3);
    expect(ARGON2ID_KDF.parallelism).toBe(4);
  });

  it('derives different keys for different salts', async () => {
    const a = await deriveKeyFromPassword('same password', new Uint8Array(16).fill(1));
    const b = await deriveKeyFromPassword('same password', new Uint8Array(16).fill(2));
    expect(toBase64(a)).not.toBe(toBase64(b));
  });
});

describe('key wrap/unwrap (Master Key hierarchy)', () => {
  it('round-trips the Master Key through the PDK', async () => {
    const masterKey = generateMasterKey();
    const salt = generateKdfSalt();
    const pdk = await deriveKeyFromPassword('quiet lantern orbits 42 mango', salt);

    const wrapped = await wrapKey(masterKey, pdk);
    expect(wrapped.ciphertext.length).toBe(48); // 32 + 16-byte GCM tag
    expect(wrapped.nonce.length).toBe(AES_GCM.nonceLengthBytes);

    const unwrapped = await unwrapKey(wrapped.ciphertext, wrapped.nonce, pdk);
    expect(unwrapped).not.toBeNull();
    expect(toBase64(unwrapped!)).toBe(toBase64(masterKey));
  });

  it('fails cleanly (null, not garbage) with the wrong password', async () => {
    const masterKey = generateMasterKey();
    const salt = generateKdfSalt();
    const right = await deriveKeyFromPassword('right password here 1', salt);
    const wrong = await deriveKeyFromPassword('wrong password here 1', salt);

    const wrapped = await wrapKey(masterKey, right);
    expect(await unwrapKey(wrapped.ciphertext, wrapped.nonce, wrong)).toBeNull();
  });

  it('detects ciphertext tampering via the GCM tag', async () => {
    const masterKey = generateMasterKey();
    const kek = generateMasterKey();
    const wrapped = await wrapKey(masterKey, kek);

    wrapped.ciphertext[0] ^= 0x01;
    expect(await unwrapKey(wrapped.ciphertext, wrapped.nonce, kek)).toBeNull();
  });

  it('never reuses a nonce across wraps', async () => {
    const key = generateMasterKey();
    const kek = generateMasterKey();
    const a = await wrapKey(key, kek);
    const b = await wrapKey(key, kek);
    expect(toBase64(a.nonce)).not.toBe(toBase64(b.nonce));
  });
});

describe('content encryption with AAD binding', () => {
  it('round-trips content and rejects a changed AAD context', async () => {
    const contentKey = generateMasterKey();
    const plaintext = new TextEncoder().encode('A final message, with love. رسالة أخيرة، مع الحب.');
    const aad = new TextEncoder().encode('user:1|item:7|v1');

    const encrypted = await encryptContent(plaintext, contentKey, aad);
    const decrypted = await decryptContent(encrypted.ciphertext, encrypted.nonce, contentKey, aad);
    expect(new TextDecoder().decode(decrypted!)).toContain('رسالة أخيرة');

    // Relocated to a different item/user context → must fail (docs/06_Cryptography.md §9).
    const otherAad = new TextEncoder().encode('user:2|item:7|v1');
    expect(await decryptContent(encrypted.ciphertext, encrypted.nonce, contentKey, otherAad)).toBeNull();
  });
});

describe('Recovery Key format', () => {
  it('generates a checksummed, grouped, transcribable key that validates', async () => {
    const { display, normalized } = await generateRecoveryKey();

    expect(display).toMatch(/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{1,4})+$/);
    expect(await normalizeAndValidateRecoveryKey(display)).toBe(normalized);
    // Crockford aliases and case are forgiven.
    expect(await normalizeAndValidateRecoveryKey(display.toLowerCase().replace(/0/g, 'o'))).toBe(normalized);
  });

  it('catches a single-character typo via the checksum', async () => {
    const { display } = await generateRecoveryKey();
    const typo = display.replace(/[0-9A-HJKMNP-TV-Z]/, (c) => (c === 'X' ? 'Y' : 'X'));
    expect(await normalizeAndValidateRecoveryKey(typo)).toBeNull();
  });

  it('round-trips the Master Key through the RDK', async () => {
    const masterKey = generateMasterKey();
    const { display, normalized } = await generateRecoveryKey();
    const salt = generateKdfSalt();

    const rdk = await deriveKeyFromRecoveryKey(normalized, salt);
    const wrapped = await wrapKey(masterKey, rdk);

    // As the user would: re-enter the displayed key, normalize, derive, unwrap.
    const reentered = await normalizeAndValidateRecoveryKey(display);
    const rdk2 = await deriveKeyFromRecoveryKey(reentered!, salt);
    const unwrapped = await unwrapKey(wrapped.ciphertext, wrapped.nonce, rdk2);

    expect(toBase64(unwrapped!)).toBe(toBase64(masterKey));
  });
});

describe('metadata encryption (sealed fields)', () => {
  const fixedMasterKey = Uint8Array.from({ length: 32 }, (_, i) => i);

  it('derives the Metadata Key with HKDF-SHA256, matching independent implementations', async () => {
    const key = await deriveMetadataKey(fixedMasterKey);

    // Pinned after cross-checking Node's crypto.hkdfSync and PHP's hash_hkdf, which agree
    // byte for byte (the PHP side is re-asserted in backend SealedFieldVectorTest).
    expect(toBase64(key)).toBe('kqXUSFpkvNwIfOp0Me/n2cbHcsiZysha1e6R29SNN+0=');
  });

  it('opens a sealed field produced by PHP/OpenSSL (cross-language vector)', async () => {
    // Produced server-side with a fixed nonce (12×0x07) — the same bytes are pinned in
    // backend/tests/Unit/SealedFieldVectorTest.php, proving the delivery path (Phase 3)
    // can read what the browser seals (docs/06_Cryptography.md §15).
    const sealed = 'AQcHBwcHBwcHBwcHB3b7P3y4kslhD1em3quJ5lR1PRXVKL4OfazEaL5GtJ5cyPKwRnKytmDf';
    const key = await deriveMetadataKey(fixedMasterKey);

    expect(await openField(sealed, key, metadataAad('recipient.name', 42))).toBe('Layla Haddad — ليلى');
  });

  it('round-trips with a fresh nonce every call', async () => {
    const key = await deriveMetadataKey(generateMasterKey());
    const aad = metadataAad('recipient.relationship', 7);

    const first = await sealField('Daughter — ابنة', key, aad);
    const second = await sealField('Daughter — ابنة', key, aad);

    expect(first).not.toBe(second);
    expect(fromBase64(first)[0]).toBe(1); // version byte
    expect(await openField(first, key, aad)).toBe('Daughter — ابنة');
  });

  it('rejects a value moved to another field or another user (AAD binding)', async () => {
    const key = await deriveMetadataKey(generateMasterKey());
    const sealed = await sealField('Omar', key, metadataAad('recipient.name', 1));

    expect(await openField(sealed, key, metadataAad('recipient.relationship', 1))).toBeNull();
    expect(await openField(sealed, key, metadataAad('recipient.name', 2))).toBeNull();
  });

  it('rejects tampering, a wrong key, an unknown version, and malformed input', async () => {
    const key = await deriveMetadataKey(generateMasterKey());
    const aad = metadataAad('emergency_contact.name', 3);
    const bytes = fromBase64(await sealField('Nadia', key, aad));

    const flipped = bytes.slice();
    flipped[flipped.length - 1] ^= 0x01;
    expect(await openField(toBase64(flipped), key, aad)).toBeNull();

    const wrongVersion = bytes.slice();
    wrongVersion[0] = 2;
    expect(await openField(toBase64(wrongVersion), key, aad)).toBeNull();

    const otherKey = await deriveMetadataKey(generateMasterKey());
    expect(await openField(toBase64(bytes), otherKey, aad)).toBeNull();

    expect(await openField('not base64 !!', key, aad)).toBeNull();
    expect(await openField(toBase64(bytes.subarray(0, 20)), key, aad)).toBeNull();
  });
});

describe('base64 helpers', () => {
  it('round-trips arbitrary bytes', () => {
    const bytes = generateMasterKey();
    expect(toBase64(fromBase64(toBase64(bytes)))).toBe(toBase64(bytes));
  });
});
