<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\DTOs\Vault\VaultItemCiphertext;
use App\Exceptions\ApiException;
use App\Models\VaultItem;
use App\Rules\SealedField;

/**
 * Shared by the create and update vault-item requests: both carry the same three
 * envelopes (docs/06_Cryptography.md §13). Structure only — the server can't read any of
 * it, so these are abuse limits, not content checks (docs/08_API_Specification.md).
 */
trait ValidatesItemCiphertext
{
    /**
     * An oversized body is 413, not a 422 field error. Checked on the base64 length
     * before anything is decoded, so a huge payload costs nothing to reject.
     */
    protected function prepareForValidation(): void
    {
        $content = $this->input('content_enc');
        $maxBase64Length = (int) (ceil(self::maxContentBytes() / 3) * 4);

        if (is_string($content) && strlen($content) > $maxBase64Length) {
            throw new ApiException('PAYLOAD_TOO_LARGE', 'legacyvault.vault.item_too_large', 413);
        }
    }

    /**
     * A message carries a body; a file bundle must not (docs/19_Decision_Log.md
     * Decision #27) — its `content_enc` has to be absent or null.
     *
     * @return array<string, list<mixed>>
     */
    protected function ciphertextRules(string $itemType): array
    {
        return [
            'title_enc' => ['required', 'string', new SealedField((int) config('legacyvault.vault_items.max_title_bytes'))],
            'content_enc' => $itemType === VaultItem::TYPE_FILE_BUNDLE
                ? ['prohibited']
                : ['required', 'string', new SealedField(self::maxContentBytes())],
            'content_key_wrapped' => ['required', 'string', SealedField::exactly(VaultItem::WRAPPED_KEY_BYTES)],
            'aad_version' => ['required', 'integer', 'in:'.VaultItem::AAD_VERSION],
        ];
    }

    public function toCiphertext(): VaultItemCiphertext
    {
        $validated = $this->validated();

        return new VaultItemCiphertext(
            titleEnc: base64_decode($validated['title_enc'], true),
            contentEnc: isset($validated['content_enc']) ? base64_decode($validated['content_enc'], true) : null,
            contentKeyWrapped: base64_decode($validated['content_key_wrapped'], true),
            aadVersion: (int) $validated['aad_version'],
        );
    }

    private static function maxContentBytes(): int
    {
        return (int) config('legacyvault.vault_items.max_content_bytes');
    }
}
