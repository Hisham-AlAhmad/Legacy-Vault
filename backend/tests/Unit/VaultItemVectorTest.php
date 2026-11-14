<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeClientVault;

/**
 * Cross-language known-answer vector for the vault item ciphertext format
 * (docs/06_Cryptography.md §9, §13; docs/15_Testing.md §2). The same bytes are pinned in
 * frontend/src/crypto/items.test.ts, where the browser module opens them and reproduces
 * them with WebCrypto. PHP/OpenSSL producing and opening the identical bytes is exactly
 * what the Phase 3 delivery trigger depends on.
 *
 * A diff to any pinned value here is a format change and needs explicit sign-off — never
 * a snapshot update (docs/15_Testing.md §8).
 */
class VaultItemVectorTest extends TestCase
{
    private const USER_ID = 42;
    private const RECIPIENT_ID = 7;
    private const ITEM_ID = '3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5b';
    private const TITLE = 'For Layla — إلى ليلى';
    private const BODY = '<p dir="auto">Read this first. اقرئي هذا أولاً.</p>';

    private const CONTENT_WRAP_KEY = '70SHEEyckdayOFBnIvxIcu6dV5yXiYPoDgC473e1Nm0=';
    private const ITEM = [
        'title_enc' => 'AQICAgICAgICAgICAithR5tgbZLyhW9Ai+7nlSEy0KIkg8FzMPJOPQqKO8JcTmt/saOcgTe6QoA6oA==',
        'content_enc' => 'AQMDAwMDAwMDAwMDAwmiZzKAsgr+99ebC58AubKhNYq6RfoRx+rLJc+u7JWazIAhi7IJvokctc9YSQEK8Lrl+PmrzivGNsBkU/+N7phTx49bqTeSER70jDzutojk',
        'content_key_wrapped' => 'AQEBAQEBAQEBAQEBAd2Yih6NjZUfPfT1AwvLnvz9eNrfJdOxhn2LyzjL2OoIT+Vy8BVrdgMkKbnbjUP7xw==',
        'aad_version' => 1,
    ];

    /** Master Key = bytes 0x00…0x1f, as in SealedFieldVectorTest. */
    private function fixedVault(): FakeClientVault
    {
        $vault = new FakeClientVault();
        $vault->masterKey = implode('', array_map('chr', range(0, 31)));

        return $vault;
    }

    public function test_content_wrap_key_matches_pinned_hkdf_output(): void
    {
        $vault = $this->fixedVault();

        $this->assertSame(self::CONTENT_WRAP_KEY, base64_encode($vault->contentWrapKey()));
        // Domain separation: a different HKDF label gives an unrelated key.
        $this->assertNotSame($vault->metadataKey(), $vault->contentWrapKey());
    }

    public function test_aad_format_is_pinned(): void
    {
        $this->assertSame(
            'legacy-vault|v1|item.content|user:42|recipient:7|item:3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5b',
            FakeClientVault::itemAad('item.content', self::USER_ID, self::RECIPIENT_ID, self::ITEM_ID),
        );
    }

    public function test_encrypt_with_fixed_key_and_nonces_matches_pinned_vector(): void
    {
        $item = $this->fixedVault()->encryptItem(self::TITLE, self::BODY, self::USER_ID, self::RECIPIENT_ID, self::ITEM_ID, [
            'content_key' => implode('', array_map('chr', range(32, 63))),
            'key_nonce' => str_repeat("\x01", 12),
            'title_nonce' => str_repeat("\x02", 12),
            'content_nonce' => str_repeat("\x03", 12),
        ]);

        $this->assertSame(self::ITEM, $item);
        $this->assertSame(61, strlen(base64_decode($item['content_key_wrapped'])));
    }

    public function test_decrypt_recovers_the_plaintext(): void
    {
        $this->assertSame(
            ['title' => self::TITLE, 'body' => self::BODY],
            $this->fixedVault()->decryptItem(self::ITEM, self::USER_ID, self::RECIPIENT_ID, self::ITEM_ID),
        );
    }

    public function test_decrypt_rejects_wrong_context_swapped_parts_and_tampering(): void
    {
        $vault = $this->fixedVault();

        // Wrong user, wrong recipient, wrong item.
        $this->assertNull($vault->decryptItem(self::ITEM, 43, self::RECIPIENT_ID, self::ITEM_ID));
        $this->assertNull($vault->decryptItem(self::ITEM, self::USER_ID, 8, self::ITEM_ID));
        $this->assertNull($vault->decryptItem(self::ITEM, self::USER_ID, self::RECIPIENT_ID, '3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5c'));

        // Title and body can't be swapped for each other, even under the same key.
        $swapped = [...self::ITEM, 'title_enc' => self::ITEM['content_enc'], 'content_enc' => self::ITEM['title_enc']];
        $this->assertNull($vault->decryptItem($swapped, self::USER_ID, self::RECIPIENT_ID, self::ITEM_ID));

        // A single flipped bit in any of the three envelopes is caught.
        foreach (['title_enc', 'content_enc', 'content_key_wrapped'] as $part) {
            $bytes = base64_decode(self::ITEM[$part]);
            $bytes[20] = chr(ord($bytes[20]) ^ 0x01);
            $tampered = [...self::ITEM, $part => base64_encode($bytes)];

            $this->assertNull($vault->decryptItem($tampered, self::USER_ID, self::RECIPIENT_ID, self::ITEM_ID), $part);
        }

        // A different Master Key can't unwrap the content key.
        $this->assertNull((new FakeClientVault())->decryptItem(self::ITEM, self::USER_ID, self::RECIPIENT_ID, self::ITEM_ID));
    }
}
