<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One piece of content addressed to exactly one recipient (docs/07_Database_Design.md
 * §vault_items, VAULT-5).
 *
 * `title_enc`, `content_enc` and `content_key_wrapped` are client-side envelopes — opaque
 * ciphertext to the server, which never opens them outside the delivery trigger
 * (AI_RULES.md #13, docs/06_Cryptography.md §13). The public identifier is the
 * client-generated `uuid`; the numeric `id` never leaves the backend.
 * Always fetched through `$user->vaultItems()` / `$recipient->vaultItems()`, never
 * `VaultItem::find()` (docs/05_Security_Architecture.md §2).
 */
class VaultItem extends Model
{
    use SoftDeletes;

    public const TYPE_MESSAGE = 'message';
    public const TYPE_FILE_BUNDLE = 'file_bundle';

    /** Only messages can be created until Phase 2c adds attachments. */
    public const CREATABLE_TYPES = [self::TYPE_MESSAGE];

    /** Envelope/AAD format version — docs/06_Cryptography.md §9. */
    public const AAD_VERSION = 1;

    /** Canonical lowercase UUIDv4 — the only ID form accepted from clients (Decision #15). */
    public const UUID_PATTERN = '/\\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/';

    /** version(1) ‖ nonce(12) ‖ 32-byte key ‖ tag(16). */
    public const WRAPPED_KEY_BYTES = 61;

    protected $fillable = [
        'uuid',
        'recipient_id',
        'item_type',
        'title_enc',
        'content_enc',
        'content_key_wrapped',
        'aad_version',
        'position',
    ];

    protected $hidden = ['id', 'title_enc', 'content_enc', 'content_key_wrapped'];

    protected function casts(): array
    {
        return [
            'aad_version' => 'integer',
            'position' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }
}
