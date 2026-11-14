<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;

/**
 * Single write path for the append-only audit log
 * (docs/05_Security_Architecture.md §17, AI_RULES.md #14).
 *
 * Audit rows are written synchronously, in the caller's transaction where one is open
 * (docs/04_System_Architecture.md §4) — never queued, so an entry can't be lost to a
 * failed background job. Metadata is filtered through an allow-list serializer so secrets
 * can never leak into the log even by accident (fail closed, §16).
 */
final class AuditLogger
{
    /** Only these metadata keys are ever persisted — extend deliberately, never bypass. */
    private const ALLOWED_METADATA_KEYS = [
        'email_domain',
        'session_id_hash',
        'device_id',
        'device_label',
        'key_type',
        'locale',
        'reason',
        'user_agent',
        'wrap_version',
        'event_source',
        'failed_count',
        // Phase 2a — record IDs and field/method names only, never names or contact details.
        'recipient_id',
        'linked_recipient_id',
        'preferred_methods',
        'changed_fields',
    ];

    public function log(
        ?int $userId,
        string $actorType,
        string $eventType,
        array $metadata = [],
        ?string $ipAddress = null,
    ): void {
        AuditLog::query()->create([
            'user_id' => $userId,
            'actor_type' => $actorType,
            'event_type' => $eventType,
            'metadata' => array_intersect_key($metadata, array_flip(self::ALLOWED_METADATA_KEYS)),
            'ip_address' => $ipAddress !== null ? (inet_pton($ipAddress) ?: null) : null,
        ]);
    }
}
