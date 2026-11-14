<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One wrapped copy of a user's Master Key (docs/06_Cryptography.md §4,
 * docs/07_Database_Design.md §encrypted_keys).
 *
 * The `server_wrapped_master` row may only ever be read for unwrapping by
 * DeliveryTriggerService via ServerWrappedKeyRepository (docs/06_Cryptography.md §5,
 * AI_RULES.md #13) — never queried ad hoc.
 */
class EncryptedKey extends Model
{
    public const TYPE_PASSWORD_WRAPPED = 'password_wrapped_master';
    public const TYPE_RECOVERY_WRAPPED = 'recovery_wrapped_master';
    public const TYPE_SERVER_WRAPPED = 'server_wrapped_master';

    protected $fillable = [
        'user_id',
        'key_type',
        'wrapped_key',
        'wrap_nonce',
        'kdf_salt',
        'wrap_version',
    ];

    protected $hidden = ['wrapped_key', 'wrap_nonce', 'kdf_salt'];

    protected function casts(): array
    {
        return [
            'wrap_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
