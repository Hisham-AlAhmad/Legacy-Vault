/**
 * Cryptographic parameters — the single source of truth for the client, matching
 * docs/06_Cryptography.md exactly. Never inline these at a call site
 * (docs/10_Frontend_Architecture.md §6): a parameter change must be a one-file diff.
 */

/**
 * Argon2id for client-side key derivation (PDK and RDK) — docs/06_Cryptography.md §6.
 * Deliberately heavier than the server's auth-hash parameters: this runs once per
 * login/recovery in the user's own browser, not on shared-hosting CPU.
 */
export const ARGON2ID_KDF = {
  memorySizeKib: 64 * 1024, // 64 MiB
  iterations: 3,
  parallelism: 4,
  outputLengthBytes: 32, // 256-bit keys
} as const;

/** AES-256-GCM — docs/06_Cryptography.md §8–9. */
export const AES_GCM = {
  keyLengthBytes: 32,
  nonceLengthBytes: 12, // 96-bit, fresh per operation, never reused per key
  tagLengthBits: 128,
} as const;

/** Argon2id salts — docs/06_Cryptography.md §7. */
export const KDF_SALT_LENGTH_BYTES = 16;

/** Wrapped-key format version, bound into AAD and stored server-side. */
export const WRAP_VERSION = 1;

/**
 * Metadata Key — derived from the Master Key with HKDF-SHA256 (docs/06_Cryptography.md §4,
 * §15). Empty salt (RFC 5869 → HashLen zero bytes): the Master Key is already uniformly
 * random, so the `info` label alone provides domain separation.
 */
export const METADATA_KEY_HKDF = {
  hash: 'SHA-256',
  info: 'legacy-vault/v1/metadata-key',
  lengthBytes: 32,
} as const;

/**
 * Sealed-field envelope for encrypted metadata columns (docs/06_Cryptography.md §15):
 * version(1) ‖ nonce(12) ‖ AES-256-GCM ciphertext ‖ tag(16), base64 on the wire.
 */
export const SEALED_FIELD_VERSION = 1;

/** Plaintext length caps for sealed metadata (characters) — docs/07_Database_Design.md. */
export const METADATA_LIMITS = {
  recipientName: 120,
  relationship: 60,
} as const;

/** File encryption chunk size for streaming AES-GCM (docs/06_Cryptography.md §12). */
export const FILE_CHUNK_BYTES = 1024 * 1024;

/** Recovery Key — docs/06_Cryptography.md §11: 256 bits, Crockford Base32, checksum. */
export const RECOVERY_KEY = {
  entropyBytes: 32,
  groupSize: 4,
  checksumChars: 4,
} as const;

/** zxcvbn minimum acceptable score (AUTH-2). */
export const MIN_PASSWORD_SCORE = 3;
export const MIN_PASSWORD_LENGTH = 12;
