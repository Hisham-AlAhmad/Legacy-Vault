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

/**
 * Content-Wrap Key — wraps each vault item's random content key (docs/06_Cryptography.md
 * §4, docs/19_Decision_Log.md Decision #13). Derived like the Metadata Key, under its own
 * label, so the Master Key itself is only ever HKDF input.
 */
export const CONTENT_WRAP_KEY_HKDF = {
  hash: 'SHA-256',
  info: 'legacy-vault/v1/content-wrap-key',
  lengthBytes: 32,
} as const;

/**
 * Vault item ciphertext format version (docs/06_Cryptography.md §9, §13): the envelope
 * version byte, the "v1" in the AAD, and the `aad_version` field sent to the API.
 */
export const ITEM_FORMAT_VERSION = 1;

/**
 * Vault item limits, matching the server's caps (docs/08_API_Specification.md,
 * backend config/legacyvault.php). Envelope = 29 bytes of overhead + UTF-8 plaintext.
 */
export const ITEM_LIMITS = {
  titleChars: 200,
  titleEnvelopeBytes: 1024,
  contentEnvelopeBytes: 262144, // 256 KiB
  perRecipient: 100,
} as const;

/**
 * Attachment format (docs/06_Cryptography.md §12, docs/19_Decision_Log.md Decisions
 * #22, #24). These are part of the ciphertext format, mirrored by the server
 * (App\Models\Attachment) and pinned by the cross-language vector — not tunables.
 *
 * The storage quota is deliberately NOT here: the limit comes from the server on every
 * response and is never hard-coded in the client (Decision #19).
 */
export const ATTACHMENT_FORMAT = {
  /** Plaintext bytes per chunk; the last chunk holds 1…this many. */
  chunkPlaintextBytes: 1024 * 1024,
  /** version(1) ‖ nonce(12) … tag(16) around every ciphertext. */
  envelopeOverheadBytes: 29,
  /** A full chunk as sent and stored. */
  chunkBytes: 1024 * 1024 + 29,
  /** The MIME type is right-padded with 0x00 to this length before sealing. */
  mimeFieldBytes: 128,
  fallbackMimeType: 'application/octet-stream',
  /** Filename caps: characters, and UTF-8 bytes so the envelope fits in 1,024 bytes. */
  filenameChars: 255,
  filenameBytes: 995,
} as const;

/** Recovery Key — docs/06_Cryptography.md §11: 256 bits, Crockford Base32, checksum. */
export const RECOVERY_KEY = {
  entropyBytes: 32,
  groupSize: 4,
  checksumChars: 4,
} as const;

/** zxcvbn minimum acceptable score (AUTH-2). */
export const MIN_PASSWORD_SCORE = 3;
export const MIN_PASSWORD_LENGTH = 12;
