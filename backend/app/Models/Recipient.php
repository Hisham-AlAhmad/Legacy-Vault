<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person who receives content addressed to them (docs/07_Database_Design.md §recipients).
 *
 * `name_enc` / `relationship_label_enc` are sealed fields — opaque ciphertext to the server
 * (docs/06_Cryptography.md §15). Only email/phone are plaintext, for delivery routing.
 * Always fetched through `$user->recipients()`, never `Recipient::find()`
 * (docs/05_Security_Architecture.md §2).
 */
class Recipient extends Model
{
    use SoftDeletes;

    public const METHOD_EMAIL = 'email';
    public const METHOD_SMS = 'sms';
    public const METHOD_LINK = 'link';
    public const METHODS = [self::METHOD_EMAIL, self::METHOD_SMS, self::METHOD_LINK];

    protected $fillable = [
        'name_enc',
        'relationship_label_enc',
        'email',
        'phone',
        'preferred_methods',
        'is_emergency_contact',
    ];

    protected $attributes = [
        'is_emergency_contact' => false,
    ];

    protected $hidden = ['name_enc', 'relationship_label_enc'];

    protected function casts(): array
    {
        return [
            'preferred_methods' => 'array',
            'is_emergency_contact' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Live items addressed to this recipient (soft-deleted ones are excluded by scope). */
    public function vaultItems(): HasMany
    {
        return $this->hasMany(VaultItem::class);
    }
}
