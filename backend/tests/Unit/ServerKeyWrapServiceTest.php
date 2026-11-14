<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Crypto\ServerKeyWrapService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SWK mechanism correctness — docs/17_Roadmap.md Phase 1 ("must be correct from the start
 * since retrofitting it later means re-keying every existing account") and
 * docs/15_Testing.md §2 (fixed vectors, tamper tests, no disabled-crypto path).
 */
class ServerKeyWrapServiceTest extends TestCase
{
    private const TEST_SWK_B64 = 'VGVzdE9ubHlTZXJ2ZXJXcmFwS2V5MzJCeXRlcyEhISE=';

    private function service(): ServerKeyWrapService
    {
        return new ServerKeyWrapService(self::TEST_SWK_B64);
    }

    public function test_aes_256_gcm_known_answer_vector(): void
    {
        // NIST CAVS AES-256-GCM known-answer vector (zero key/nonce, empty plaintext):
        // tag must be 530f8afbc74536b9a963b4f1c4cb738b. Verifies the OpenSSL primitive
        // this service is built on behaves exactly as specified, independent of any
        // live key material (docs/15_Testing.md §2).
        $tag = '';
        $ciphertext = openssl_encrypt(
            '',
            'aes-256-gcm',
            str_repeat("\0", 32),
            OPENSSL_RAW_DATA,
            str_repeat("\0", 12),
            $tag,
            '',
            16,
        );

        $this->assertSame('', $ciphertext);
        $this->assertSame('530f8afbc74536b9a963b4f1c4cb738b', bin2hex($tag));
    }

    public function test_wrap_unwrap_round_trip(): void
    {
        $masterKey = random_bytes(32);
        $service = $this->service();

        $wrapped = $service->wrap($masterKey, 42);

        $this->assertNotSame($masterKey, $wrapped->ciphertext);
        $this->assertSame(12, strlen($wrapped->nonce));
        $this->assertSame(48, strlen($wrapped->ciphertext)); // 32 bytes + 16-byte tag

        $unwrapped = $service->unwrap($wrapped->ciphertext, $wrapped->nonce, 42, $wrapped->version);

        $this->assertSame($masterKey, $unwrapped);
    }

    public function test_tampered_ciphertext_fails_tag_check(): void
    {
        $service = $this->service();
        $wrapped = $service->wrap(random_bytes(32), 1);

        $tampered = $wrapped->ciphertext;
        $tampered[0] = chr(ord($tampered[0]) ^ 0x01);

        $this->expectException(RuntimeException::class);
        $service->unwrap($tampered, $wrapped->nonce, 1, $wrapped->version);
    }

    public function test_wrong_user_binding_fails_aad_check(): void
    {
        // Ciphertext relocated to another user's row must not unwrap
        // (docs/06_Cryptography.md §9 — AAD context binding).
        $service = $this->service();
        $wrapped = $service->wrap(random_bytes(32), 1);

        $this->expectException(RuntimeException::class);
        $service->unwrap($wrapped->ciphertext, $wrapped->nonce, 2, $wrapped->version);
    }

    public function test_fresh_nonce_per_wrap(): void
    {
        $service = $this->service();
        $key = random_bytes(32);

        $a = $service->wrap($key, 1);
        $b = $service->wrap($key, 1);

        $this->assertNotSame($a->nonce, $b->nonce); // docs/06_Cryptography.md §8
    }

    public function test_rejects_missing_or_malformed_swk(): void
    {
        $this->expectException(RuntimeException::class);
        new ServerKeyWrapService(null);
    }

    public function test_rejects_wrong_length_swk(): void
    {
        $this->expectException(RuntimeException::class);
        new ServerKeyWrapService(base64_encode('short'));
    }

    public function test_rejects_wrong_length_master_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->wrap('too-short', 1);
    }
}
