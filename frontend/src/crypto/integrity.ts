/**
 * Integrity failure codes — docs/06_Cryptography.md §9, docs/19_Decision_Log.md Decision #31.
 *
 * Every integrity error thrown by the crypto module carries one of these enumerated
 * reasons. They are what gets reported to the audit log — never the error's message, so
 * nothing free-form (let alone plaintext) can travel with a report.
 */
export type IntegrityReason = 'authentication_failed' | 'malformed' | 'unknown_version' | 'length_mismatch' | 'unreadable';

/**
 * The detail strings the crypto module uses, each mapped to its reason. A new detail
 * must be added here (the constructors only accept these), so no failure is unclassified.
 */
export const INTEGRITY_DETAILS = {
  'authentication failed': 'authentication_failed',
  'unknown format version': 'unknown_version',
  'not valid base64': 'malformed',
  'envelope too short': 'malformed',
  'not valid UTF-8': 'malformed',
  'unexpected key length': 'malformed',
  'unexpected field length': 'malformed',
  'malformed type': 'malformed',
  'missing body': 'malformed',
  'size does not fit the chunk count': 'length_mismatch',
  'more data than declared': 'length_mismatch',
  'less data than declared': 'length_mismatch',
} as const satisfies Record<string, IntegrityReason>;

export type IntegrityDetail = keyof typeof INTEGRITY_DETAILS;
