<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\DTOs\Vault\StorageUsageDto;
use App\Models\User;

/**
 * Attachment quota — VAULT-7, NFR-3, docs/19_Decision_Log.md Decisions #19 and #26.
 *
 * This is the single place the limit is read. Usage counts every attachment row that
 * still exists: completed files, uploads in progress (their declared size is a
 * reservation) and soft-deleted files, which keep occupying the disk until purged.
 * Everything is ciphertext bytes, so nothing is decrypted to enforce it.
 */
final class StorageQuota
{
    /** The user's limit in bytes. A per-plan limit would replace this one lookup. */
    public function limitFor(User $user): int
    {
        return (int) config('legacyvault.attachment_quota_bytes');
    }

    public function usageFor(User $user): StorageUsageDto
    {
        $used = (int) $user->attachments()->withTrashed()->sum('size_bytes');
        $trashed = (int) $user->attachments()->onlyTrashed()->sum('size_bytes');

        return new StorageUsageDto(
            limitBytes: $this->limitFor($user),
            usedBytes: $used,
            trashedBytes: $trashed,
        );
    }

    /** Whether `$bytes` more would still fit. Call inside the transaction that reserves them. */
    public function hasRoomFor(User $user, int $bytes): bool
    {
        $used = (int) $user->attachments()->withTrashed()->sum('size_bytes');

        return $used + $bytes <= $this->limitFor($user);
    }
}
