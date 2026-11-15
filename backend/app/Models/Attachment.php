<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One encrypted file attached to a vault item (docs/07_Database_Design.md §attachments,
 * VAULT-6/7).
 *
 * `filename_enc`, `mime_type_enc` and `attachment_key_wrapped` are client-side envelopes,
 * and the file on disk is a run of chunk envelopes — all opaque to the server, which
 * never opens them outside the delivery trigger (AI_RULES.md #13,
 * docs/06_Cryptography.md §12). The public identifier is the client-generated `uuid`.
 * Always fetched through `$user->attachments()` / `$item->attachments()` on request
 * paths, never `Attachment::find()` (docs/05_Security_Architecture.md §2).
 */
class Attachment extends Model
{
    use SoftDeletes;

    /** Envelope/AAD format version — docs/06_Cryptography.md §9. */
    public const AAD_VERSION = 1;

    /** version(1) ‖ nonce(12) … tag(16) — the envelope around every ciphertext. */
    public const ENVELOPE_OVERHEAD_BYTES = 29;

    /**
     * Chunk size. A format constant shared with the client (crypto/constants.ts) and
     * pinned by the cross-language vector — docs/19_Decision_Log.md Decision #22.
     */
    public const CHUNK_PLAINTEXT_BYTES = 1048576;

    /** A full chunk as stored and transmitted: plaintext + envelope overhead. */
    public const CHUNK_BYTES = self::CHUNK_PLAINTEXT_BYTES + self::ENVELOPE_OVERHEAD_BYTES;

    /** version(1) ‖ nonce(12) ‖ 32-byte key ‖ tag(16). */
    public const WRAPPED_KEY_BYTES = 61;

    /** The MIME type is padded to 128 bytes before sealing (Decision #24). */
    public const MIME_ENVELOPE_BYTES = 128 + self::ENVELOPE_OVERHEAD_BYTES;

    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_COMPLETE = 'complete';

    protected $fillable = [
        'uuid',
        'vault_item_id',
        'storage_path',
        'filename_enc',
        'mime_type_enc',
        'attachment_key_wrapped',
        'size_bytes',
        'chunk_count',
        'received_chunks',
        'completed_at',
        'aad_version',
    ];

    protected $attributes = [
        'received_chunks' => 0,
    ];

    protected $hidden = ['id', 'storage_path', 'filename_enc', 'mime_type_enc', 'attachment_key_wrapped'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'chunk_count' => 'integer',
            'received_chunks' => 'integer',
            'aad_version' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vaultItem(): BelongsTo
    {
        return $this->belongsTo(VaultItem::class);
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * The exact size chunk `$index` must have: every chunk is full except the last, which
     * is whatever remains of the declared total (Decision #22).
     */
    public function expectedChunkBytes(int $index): int
    {
        return $index < $this->chunk_count - 1
            ? self::CHUNK_BYTES
            : $this->size_bytes - ($this->chunk_count - 1) * self::CHUNK_BYTES;
    }

    /** Whether `$sizeBytes` is a total that `$chunkCount` chunks can add up to. */
    public static function isPossibleSize(int $sizeBytes, int $chunkCount): bool
    {
        return $chunkCount >= 1
            && $sizeBytes >= ($chunkCount - 1) * self::CHUNK_BYTES + self::ENVELOPE_OVERHEAD_BYTES + 1
            && $sizeBytes <= $chunkCount * self::CHUNK_BYTES;
    }
}
