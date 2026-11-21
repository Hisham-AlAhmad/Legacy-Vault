/**
 * Reports client-side integrity failures to the audit log — docs/06_Cryptography.md §9,
 * docs/19_Decision_Log.md Decision #31.
 *
 * Only the browser ever opens vault content, so only the browser can see a failed GCM
 * check. Each failure is sent as IDs plus enumerated codes (the error's `part` and
 * `reason`) — never its message, never ciphertext or plaintext.
 *
 * Reporting is fire-and-forget: each distinct failure is sent at most once per tab,
 * reports are batched, nothing is retried, and every error (including 422 and 429) is
 * swallowed. A page must never break, wait or loop because a report could not be sent.
 */
import type { AttachmentIntegrityError, AttachmentPart } from '../../crypto/attachments';
import type { ItemIntegrityError, ItemPart } from '../../crypto/items';
import type { IntegrityReason } from '../../crypto/integrity';
import { api } from '../../lib/api/client';

/** Where in the app the failure was seen. */
export type IntegritySurface = 'preview' | 'list' | 'editor' | 'download' | 'trash' | 'recipients';

export type IntegrityEvent =
  | { kind: 'item'; part: ItemPart; reason: IntegrityReason; surface: IntegritySurface; itemId: string }
  | {
      kind: 'attachment';
      part: AttachmentPart;
      reason: IntegrityReason;
      surface: IntegritySurface;
      itemId: string;
      attachmentId: string;
    }
  | {
      kind: 'sealed_field';
      part: 'recipient.name' | 'recipient.relationship';
      reason: 'unreadable';
      surface: IntegritySurface;
      recipientId: number;
    }
  | { kind: 'sealed_field'; part: 'emergency_contact.name'; reason: 'unreadable'; surface: IntegritySurface };

/** The server accepts at most this many events per request (Decision #31). */
const MAX_PER_REQUEST = 10;
const FLUSH_DELAY_MS = 250;

const reported = new Set<string>();
let queue: IntegrityEvent[] = [];
let timer: ReturnType<typeof setTimeout> | null = null;

/**
 * What failed — kind, IDs and part. Not where it was seen or why, matching the server's
 * dedup, so one broken ciphertext shown on several pages is reported once.
 */
function fingerprint(event: IntegrityEvent): string {
  const ids = [
    'itemId' in event ? event.itemId : '-',
    'attachmentId' in event ? event.attachmentId : '-',
    'recipientId' in event ? String(event.recipientId) : '-',
  ];
  return [event.kind, event.part, ...ids].join('|');
}

export function reportIntegrityEvent(event: IntegrityEvent): void {
  const key = fingerprint(event);
  if (reported.has(key)) {
    return;
  }
  reported.add(key);
  queue.push(event);
  timer ??= setTimeout(() => void flushIntegrityReports(), FLUSH_DELAY_MS);
}

export function reportItemFailure(error: ItemIntegrityError, itemId: string, surface: IntegritySurface): void {
  reportIntegrityEvent({ kind: 'item', part: error.part, reason: error.reason, surface, itemId });
}

export function reportAttachmentFailure(
  error: AttachmentIntegrityError,
  itemId: string,
  attachmentId: string,
  surface: IntegritySurface,
): void {
  reportIntegrityEvent({ kind: 'attachment', part: error.part, reason: error.reason, surface, itemId, attachmentId });
}

export function reportRecipientFieldFailure(
  part: 'recipient.name' | 'recipient.relationship',
  recipientId: number,
  surface: IntegritySurface,
): void {
  reportIntegrityEvent({ kind: 'sealed_field', part, reason: 'unreadable', surface, recipientId });
}

export function reportEmergencyContactNameFailure(surface: IntegritySurface): void {
  reportIntegrityEvent({ kind: 'sealed_field', part: 'emergency_contact.name', reason: 'unreadable', surface });
}

/** Send everything queued now. Resolves once every batch has been attempted; never rejects. */
export async function flushIntegrityReports(): Promise<void> {
  if (timer !== null) {
    clearTimeout(timer);
    timer = null;
  }
  const pending = queue;
  queue = [];

  const batches: IntegrityEvent[][] = [];
  for (let i = 0; i < pending.length; i += MAX_PER_REQUEST) {
    batches.push(pending.slice(i, i + MAX_PER_REQUEST));
  }

  await Promise.all(
    batches.map((events) => api('POST', '/vault/integrity-events', { events }).catch(() => undefined)),
  );
}

/** Tests only: forget what was reported and drop anything queued. */
export function resetIntegrityReporter(): void {
  if (timer !== null) {
    clearTimeout(timer);
    timer = null;
  }
  queue = [];
  reported.clear();
}
