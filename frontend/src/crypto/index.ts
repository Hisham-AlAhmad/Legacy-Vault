/**
 * The client-side crypto module — docs/10_Frontend_Architecture.md §6.
 *
 * This is the most security-critical directory in the frontend. Every feature calls these
 * vetted primitives; no feature reimplements WebCrypto calls ad hoc. Vetted libraries only
 * (docs/06_Cryptography.md §24): WebCrypto for AES-256-GCM, hash-wasm for Argon2id.
 *
 * Key material is handled as raw bytes (Uint8Array) held in memory only — never persisted
 * to any storage API (AI_RULES.md #2, docs/18_Threat_Model.md scenario 3).
 */
import { argon2id } from 'hash-wasm';
import {
  AES_GCM,
  ARGON2ID_KDF,
  KDF_SALT_LENGTH_BYTES,
  METADATA_KEY_HKDF,
  SEALED_FIELD_VERSION,
} from './constants';

export interface WrappedKey {
  /** AES-256-GCM ciphertext with the 16-byte tag appended (WebCrypto's native output). */
  ciphertext: Uint8Array;
  nonce: Uint8Array;
}

const subtle = globalThis.crypto.subtle;

function randomBytes(length: number): Uint8Array {
  const bytes = new Uint8Array(length);
  globalThis.crypto.getRandomValues(bytes);
  return bytes;
}

export function generateKdfSalt(): Uint8Array {
  return randomBytes(KDF_SALT_LENGTH_BYTES);
}

/** 256-bit random Master Key, generated client-side at registration (docs/06_Cryptography.md §4). */
export function generateMasterKey(): Uint8Array {
  return randomBytes(AES_GCM.keyLengthBytes);
}

/** Password-Derived Key (PDK) — Argon2id 64 MiB / 3 / 4 (docs/06_Cryptography.md §6). */
export async function deriveKeyFromPassword(password: string, salt: Uint8Array): Promise<Uint8Array> {
  return argon2id({
    password,
    salt,
    memorySize: ARGON2ID_KDF.memorySizeKib,
    iterations: ARGON2ID_KDF.iterations,
    parallelism: ARGON2ID_KDF.parallelism,
    hashLength: ARGON2ID_KDF.outputLengthBytes,
    outputType: 'binary',
  });
}

/**
 * Recovery-Key-Derived Key (RDK) — same KDF over the normalized Recovery Key string
 * (docs/06_Cryptography.md §4). Normalization lives in recoveryKey.ts.
 */
export async function deriveKeyFromRecoveryKey(normalizedRecoveryKey: string, salt: Uint8Array): Promise<Uint8Array> {
  return deriveKeyFromPassword(normalizedRecoveryKey, salt);
}

async function importAesKey(keyBytes: Uint8Array): Promise<CryptoKey> {
  return subtle.importKey('raw', keyBytes as BufferSource, { name: 'AES-GCM' }, false, [
    'encrypt',
    'decrypt',
  ]);
}

/** Wrap (encrypt) a key with a wrapping key. Fresh nonce every call (docs/06_Cryptography.md §8). */
export async function wrapKey(key: Uint8Array, wrappingKey: Uint8Array): Promise<WrappedKey> {
  const nonce = randomBytes(AES_GCM.nonceLengthBytes);
  const aesKey = await importAesKey(wrappingKey);

  const ciphertext = await subtle.encrypt(
    { name: 'AES-GCM', iv: nonce as BufferSource, tagLength: AES_GCM.tagLengthBits },
    aesKey,
    key as BufferSource,
  );

  return { ciphertext: new Uint8Array(ciphertext), nonce };
}

/**
 * Unwrap a wrapped key. Returns null on a failed GCM tag check — a wrong password /
 * Recovery Key / tampered ciphertext all surface here, never as garbage plaintext
 * (docs/06_Cryptography.md §9).
 */
export async function unwrapKey(
  ciphertext: Uint8Array,
  nonce: Uint8Array,
  wrappingKey: Uint8Array,
): Promise<Uint8Array | null> {
  try {
    const aesKey = await importAesKey(wrappingKey);
    const plaintext = await subtle.decrypt(
      { name: 'AES-GCM', iv: nonce as BufferSource, tagLength: AES_GCM.tagLengthBits },
      aesKey,
      ciphertext as BufferSource,
    );

    return new Uint8Array(plaintext);
  } catch {
    return null; // tag mismatch = wrong key or tampering
  }
}

/**
 * Encrypt content with a content key, binding contextual AAD (user ID + item ID + version)
 * so ciphertext can't be replayed under another context (docs/06_Cryptography.md §9).
 */
export async function encryptContent(
  plaintext: Uint8Array,
  contentKey: Uint8Array,
  aad: Uint8Array,
): Promise<WrappedKey> {
  const nonce = randomBytes(AES_GCM.nonceLengthBytes);
  const aesKey = await importAesKey(contentKey);

  const ciphertext = await subtle.encrypt(
    {
      name: 'AES-GCM',
      iv: nonce as BufferSource,
      additionalData: aad as BufferSource,
      tagLength: AES_GCM.tagLengthBits,
    },
    aesKey,
    plaintext as BufferSource,
  );

  return { ciphertext: new Uint8Array(ciphertext), nonce };
}

/** Decrypt content. Returns null on tag/AAD failure — treated as tampering by callers. */
export async function decryptContent(
  ciphertext: Uint8Array,
  nonce: Uint8Array,
  contentKey: Uint8Array,
  aad: Uint8Array,
): Promise<Uint8Array | null> {
  try {
    const aesKey = await importAesKey(contentKey);
    const plaintext = await subtle.decrypt(
      {
        name: 'AES-GCM',
        iv: nonce as BufferSource,
        additionalData: aad as BufferSource,
        tagLength: AES_GCM.tagLengthBits,
      },
      aesKey,
      ciphertext as BufferSource,
    );

    return new Uint8Array(plaintext);
  } catch {
    return null;
  }
}

// ── Metadata encryption (docs/06_Cryptography.md §15) ─────────────────────────────────

/** Which metadata field a sealed value belongs to — bound into AAD, so values can't be swapped. */
export type MetadataField = 'recipient.name' | 'recipient.relationship' | 'emergency_contact.name';

/**
 * Metadata Key = HKDF-SHA256(Master Key, info "legacy-vault/v1/metadata-key")
 * (docs/06_Cryptography.md §4). The server reproduces this only inside the delivery
 * trigger (Phase 3), after SWK-unwrapping the Master Key.
 */
export async function deriveMetadataKey(masterKey: Uint8Array): Promise<Uint8Array> {
  const ikm = await subtle.importKey('raw', masterKey as BufferSource, 'HKDF', false, ['deriveBits']);
  const bits = await subtle.deriveBits(
    {
      name: 'HKDF',
      hash: METADATA_KEY_HKDF.hash,
      salt: new Uint8Array(0),
      info: new TextEncoder().encode(METADATA_KEY_HKDF.info),
    },
    ikm,
    METADATA_KEY_HKDF.lengthBytes * 8,
  );

  return new Uint8Array(bits);
}

/**
 * AAD for a sealed metadata field: binds the field name and owning user
 * (docs/06_Cryptography.md §9). Record IDs are server-assigned after create, so they
 * can't be bound — see docs/19_Decision_Log.md Decision #11.
 */
export function metadataAad(field: MetadataField, userId: number): Uint8Array {
  return new TextEncoder().encode(`legacy-vault|v${SEALED_FIELD_VERSION}|${field}|user:${userId}`);
}

/** Seal a short text field: version ‖ nonce ‖ ciphertext ‖ tag, base64. Fresh nonce per call. */
export async function sealField(plaintext: string, metadataKey: Uint8Array, aad: Uint8Array): Promise<string> {
  const { ciphertext, nonce } = await encryptContent(new TextEncoder().encode(plaintext), metadataKey, aad);

  const sealed = new Uint8Array(1 + nonce.length + ciphertext.length);
  sealed[0] = SEALED_FIELD_VERSION;
  sealed.set(nonce, 1);
  sealed.set(ciphertext, 1 + nonce.length);

  return toBase64(sealed);
}

/**
 * Open a sealed field. Returns null on an unknown version, malformed envelope, or failed
 * GCM tag/AAD check — callers treat null as an integrity failure, never as empty text.
 */
export async function openField(sealedB64: string, metadataKey: Uint8Array, aad: Uint8Array): Promise<string | null> {
  let sealed: Uint8Array;
  try {
    sealed = fromBase64(sealedB64);
  } catch {
    return null;
  }

  const headerLength = 1 + AES_GCM.nonceLengthBytes;
  if (sealed.length < headerLength + AES_GCM.tagLengthBits / 8 || sealed[0] !== SEALED_FIELD_VERSION) {
    return null;
  }

  const plaintext = await decryptContent(
    sealed.subarray(headerLength),
    sealed.subarray(1, headerLength),
    metadataKey,
    aad,
  );

  if (plaintext === null) {
    return null;
  }

  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(plaintext);
  } catch {
    return null;
  }
}

// ── Encoding helpers (API payloads are base64) ────────────────────────────────────────

export function toBase64(bytes: Uint8Array): string {
  let binary = '';
  bytes.forEach((b) => {
    binary += String.fromCharCode(b);
  });
  return btoa(binary);
}

export function fromBase64(value: string): Uint8Array {
  const binary = atob(value);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes;
}

/** Best-effort zeroization of key material we control (JS strings can't be zeroized). */
export function zeroize(bytes: Uint8Array): void {
  bytes.fill(0);
}
