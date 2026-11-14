<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App-level device record behind the trusted-devices UI
 * (docs/07_Database_Design.md §sessions/devices, docs/02_User_Flows.md Flow 10).
 */
class Device extends Model
{
    protected $fillable = [
        'user_id',
        'fingerprint_hash',
        'label',
        'trusted',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'trusted' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
