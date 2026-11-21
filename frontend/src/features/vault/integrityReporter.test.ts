/**
 * Integrity reporting — docs/19_Decision_Log.md Decision #31: IDs and enumerated codes
 * only, one report per distinct failure per tab, batched to the server's limit, and never
 * able to throw into the page.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createItem, decryptItem, ItemIntegrityError } from '../../crypto/items';
import { generateMasterKey } from '../../crypto';

const apiMock = vi.hoisted(() => vi.fn());
vi.mock('../../lib/api/client', () => ({ api: apiMock }));

const { flushIntegrityReports, reportIntegrityEvent, reportItemFailure, resetIntegrityReporter } = await import('./integrityReporter');

const ITEM = '0b7f3a52-6c1e-4d8a-9f2b-3c4d5e6f7a8b';

describe('integrityReporter', () => {
  beforeEach(() => {
    resetIntegrityReporter();
    apiMock.mockReset();
    apiMock.mockResolvedValue({ recorded: 1 });
  });
  afterEach(() => resetIntegrityReporter());

  it('sends the enumerated reason of a real failure, never its message', async () => {
    const masterKey = generateMasterKey();
    const { itemId, encrypted } = await createItem({ title: 'For Layla', body: '<p>Secret words</p>' }, masterKey, { userId: 1, recipientId: 2 });

    // Served under another recipient: the AAD no longer matches.
    const error = await decryptItem(encrypted, masterKey, { userId: 1, recipientId: 3, itemId }).catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ItemIntegrityError);
    reportItemFailure(error as ItemIntegrityError, itemId, 'preview');
    await flushIntegrityReports();

    expect(apiMock).toHaveBeenCalledTimes(1);
    const [method, path, body] = apiMock.mock.calls[0];
    expect([method, path]).toEqual(['POST', '/vault/integrity-events']);
    expect(body).toEqual({ events: [{ kind: 'item', part: 'item.key', reason: 'authentication_failed', surface: 'preview', itemId }] });
    expect(JSON.stringify(body)).not.toMatch(/Layla|Secret|failed \(/);
  });

  it('reports one failure once, wherever it is seen', async () => {
    const event = { kind: 'item', part: 'item.title', reason: 'authentication_failed', itemId: ITEM } as const;
    reportIntegrityEvent({ ...event, surface: 'list' });
    reportIntegrityEvent({ ...event, surface: 'editor' });
    reportIntegrityEvent({ ...event, surface: 'preview', reason: 'malformed' });
    reportIntegrityEvent({ ...event, part: 'item.content', surface: 'preview' }); // a different part is a different failure
    await flushIntegrityReports();

    expect(apiMock).toHaveBeenCalledTimes(1);
    expect(apiMock.mock.calls[0][2].events).toHaveLength(2);

    reportIntegrityEvent({ ...event, surface: 'trash' });
    await flushIntegrityReports();
    expect(apiMock).toHaveBeenCalledTimes(1); // still the same tab
  });

  it('batches to at most ten events per request', async () => {
    for (let i = 0; i < 23; i++) {
      reportIntegrityEvent({ kind: 'sealed_field', part: 'recipient.name', reason: 'unreadable', surface: 'recipients', recipientId: i + 1 });
    }
    await flushIntegrityReports();

    expect(apiMock.mock.calls.map((call) => call[2].events.length)).toEqual([10, 10, 3]);
  });

  it('flushes on its own shortly after a report', async () => {
    vi.useFakeTimers();
    try {
      reportIntegrityEvent({ kind: 'sealed_field', part: 'emergency_contact.name', reason: 'unreadable', surface: 'recipients' });
      expect(apiMock).not.toHaveBeenCalled();
      await vi.advanceTimersByTimeAsync(300);
      expect(apiMock).toHaveBeenCalledTimes(1);
    } finally {
      vi.useRealTimers();
    }
  });

  it('swallows every failure to send, and never retries', async () => {
    apiMock.mockRejectedValue(Object.assign(new Error('Too many requests'), { status: 429 }));
    reportIntegrityEvent({ kind: 'item', part: 'item.content', reason: 'authentication_failed', surface: 'preview', itemId: ITEM });

    await expect(flushIntegrityReports()).resolves.toBeUndefined();
    await flushIntegrityReports();
    expect(apiMock).toHaveBeenCalledTimes(1);
  });
});
