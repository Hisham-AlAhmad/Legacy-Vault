<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit log (docs/05_Security_Architecture.md §17,
 * docs/07_Database_Design.md §audit_logs). Never updated or deleted by application code
 * (AI_RULES.md #14). Always written through App\Services\AuditLogger, never directly.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor_type',
        'event_type',
        'metadata',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
