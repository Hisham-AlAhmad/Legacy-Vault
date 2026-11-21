<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\IntegrityEventData;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * POST /vault/integrity-events — records client-reported decryption/integrity failures
 * as `integrity.check_failed` (docs/06_Cryptography.md §9, docs/19_Decision_Log.md
 * Decision #31).
 *
 * The server can't verify a report (it never decrypts — AI_RULES.md #13), so it bounds
 * and labels it instead: every ID must be one of the caller's own rows, the logged
 * recipient comes from the database row wherever there is one, a repeat of the same
 * failure within the dedup window is dropped, and at most `daily_budget` events are
 * recorded per user per 24 h. Entries carry `event_source: client`.
 */
final class RecordIntegrityEventsAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Validate ownership of every referenced row, then record the events not already
     * recorded in the dedup window. All-or-nothing: one bad reference records nothing.
     *
     * @param list<IntegrityEventData> $events
     * @return int how many new audit rows were written
     *
     * @throws ValidationException when an ID isn't one of the caller's rows
     * @throws ApiException 429 when the daily event budget would be exceeded
     */
    public function execute(User $user, array $events, ?string $ipAddress): int
    {
        $resolved = [];
        foreach ($events as $index => $event) {
            $resolved[] = [$event, $this->resolveRecipientId($user, $event, $index)];
        }

        // Dedup by what failed (kind, IDs, part) — not where it was seen or why — so one
        // broken ciphertext seen on several pages is one row per window.
        $fresh = [];
        foreach ($resolved as [$event, $recipientId]) {
            $key = $this->dedupKey($user, $event);
            if (! isset($fresh[$key]) && ! Cache::has($key)) {
                $fresh[$key] = [$event, $recipientId];
            }
        }

        if ($fresh === []) {
            return 0;
        }

        $budgetKey = 'integrity-events:'.$user->id;
        $budget = (int) config('legacyvault.integrity_events.daily_budget');
        if (RateLimiter::remaining($budgetKey, $budget) < count($fresh)) {
            throw new ApiException('RATE_LIMITED', 'legacyvault.errors.rate_limited', 429);
        }

        $ttl = now()->addHours((int) config('legacyvault.integrity_events.dedup_hours'));
        $recorded = 0;

        DB::transaction(function () use ($user, $fresh, $ipAddress, $budgetKey, $ttl, &$recorded): void {
            foreach ($fresh as $key => [$event, $recipientId]) {
                // add() is atomic: a concurrent identical report loses here instead of
                // writing a second row.
                if (! Cache::add($key, true, $ttl)) {
                    continue;
                }
                RateLimiter::hit($budgetKey, 86_400);

                $this->audit->log($user->id, 'user', 'integrity.check_failed', array_filter([
                    'kind' => $event->kind,
                    'part' => $event->part,
                    'reason' => $event->reason,
                    'surface' => $event->surface,
                    'item_id' => $event->itemId,
                    'attachment_id' => $event->attachmentId,
                    'recipient_id' => $recipientId,
                    'event_source' => 'client',
                ], static fn ($value): bool => $value !== null), $ipAddress);
                $recorded++;
            }
        });

        return $recorded;
    }

    /**
     * The recipient the failed ciphertext belongs to, read from the caller's own rows
     * (soft-deleted included — the trash list decrypts too). Items and attachments take
     * it from the item row, never from the client. Null for the emergency contact.
     *
     * @throws ValidationException
     */
    private function resolveRecipientId(User $user, IntegrityEventData $event, int $index): ?int
    {
        if ($event->itemId !== null) {
            $item = $user->vaultItems()->withTrashed()->where('uuid', $event->itemId)->first();
            $attachmentOk = $event->attachmentId === null || ($item !== null && $user->attachments()->withTrashed()
                ->where('uuid', $event->attachmentId)
                ->where('vault_item_id', $item->id)
                ->exists());

            if ($item === null || ! $attachmentOk) {
                throw self::unknownReference($index);
            }

            return (int) $item->recipient_id;
        }

        if ($event->recipientId !== null) {
            if (! $user->recipients()->withTrashed()->whereKey($event->recipientId)->exists()) {
                throw self::unknownReference($index);
            }

            return $event->recipientId;
        }

        return null;
    }

    private function dedupKey(User $user, IntegrityEventData $event): string
    {
        $fingerprint = implode('|', [
            $event->kind,
            $event->part,
            $event->itemId ?? '-',
            $event->attachmentId ?? '-',
            $event->recipientId ?? '-',
        ]);

        return 'integrity-event:'.$user->id.':'.hash('sha256', $fingerprint);
    }

    private static function unknownReference(int $index): ValidationException
    {
        return ValidationException::withMessages([
            "events.$index" => __('legacyvault.validation.unknown_vault_reference'),
        ]);
    }
}
