/**
 * Recovery Key generation/formatting — docs/06_Cryptography.md §11.
 *
 * 256 bits of CSPRNG entropy, presented as grouped Crockford Base32 (human-transcribable:
 * no I/L/O/U ambiguity) with a checksum segment so a typo is caught locally before a
 * failed-unwrap round trip. Never transmitted to or stored by the server.
 */
import { RECOVERY_KEY } from './constants';

const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function encodeCrockford(bytes: Uint8Array): string {
  let bits = 0;
  let value = 0;
  let output = '';

  for (const byte of bytes) {
    value = (value << 8) | byte;
    bits += 8;
    while (bits >= 5) {
      output += CROCKFORD[(value >>> (bits - 5)) & 31];
      bits -= 5;
    }
  }

  if (bits > 0) {
    output += CROCKFORD[(value << (5 - bits)) & 31];
  }

  return output;
}

async function checksumOf(body: string): Promise<string> {
  const digest = await globalThis.crypto.subtle.digest('SHA-256', new TextEncoder().encode(body));
  return encodeCrockford(new Uint8Array(digest)).slice(0, RECOVERY_KEY.checksumChars);
}

export interface GeneratedRecoveryKey {
  /** Grouped display form shown to the user exactly once (e.g. XXXX-XXXX-…-CCCC). */
  display: string;
  /** Normalized form fed into the KDF — what "the Recovery Key" means cryptographically. */
  normalized: string;
}

export async function generateRecoveryKey(): Promise<GeneratedRecoveryKey> {
  const entropy = new Uint8Array(RECOVERY_KEY.entropyBytes);
  globalThis.crypto.getRandomValues(entropy);

  const body = encodeCrockford(entropy);
  const checksum = await checksumOf(body);
  const normalized = body + checksum;

  const grouped = normalized.match(new RegExp(`.{1,${RECOVERY_KEY.groupSize}}`, 'g')) ?? [];

  return { display: grouped.join('-'), normalized };
}

/**
 * Normalize user input (case, separators, Crockford aliases like O→0, I/L→1) and verify
 * the checksum. Returns the normalized key, or null if the checksum fails (typo caught
 * before any KDF work).
 */
export async function normalizeAndValidateRecoveryKey(input: string): Promise<string | null> {
  const cleaned = input
    .toUpperCase()
    .replace(/[\s-]/g, '')
    .replace(/O/g, '0')
    .replace(/[IL]/g, '1');

  if (cleaned.length <= RECOVERY_KEY.checksumChars || /[^0-9A-HJKMNP-TV-Z]/.test(cleaned)) {
    return null;
  }

  const body = cleaned.slice(0, -RECOVERY_KEY.checksumChars);
  const checksum = cleaned.slice(-RECOVERY_KEY.checksumChars);

  return (await checksumOf(body)) === checksum ? cleaned : null;
}
