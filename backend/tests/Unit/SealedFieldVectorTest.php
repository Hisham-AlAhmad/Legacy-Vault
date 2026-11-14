<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeClientVault;

/**
 * Cross-language known-answer vector for sealed metadata fields
 * (docs/06_Cryptography.md §15, docs/15_Testing.md §2). The same bytes are pinned in
 * frontend/src/crypto/crypto.test.ts: the browser opens what PHP/OpenSSL seals, which is
 * exactly what the Phase 3 delivery path will rely on in the other direction.
 */
class SealedFieldVectorTest extends TestCase
{
    private const SEALED = 'AQcHBwcHBwcHBwcHB3b7P3y4kslhD1em3quJ5lR1PRXVKL4OfazEaL5GtJ5cyPKwRnKytmDf';

    private function fixedVault(): FakeClientVault
    {
        $vault = new FakeClientVault();
        $vault->masterKey = implode('', array_map('chr', range(0, 31)));

        return $vault;
    }

    public function test_metadata_key_matches_pinned_hkdf_output(): void
    {
        $this->assertSame(
            'kqXUSFpkvNwIfOp0Me/n2cbHcsiZysha1e6R29SNN+0=',
            base64_encode($this->fixedVault()->metadataKey()),
        );
    }

    public function test_seal_with_fixed_nonce_matches_pinned_vector(): void
    {
        $sealed = $this->fixedVault()->sealField('Layla Haddad — ليلى', 'recipient.name', 42, str_repeat("\x07", 12));

        $this->assertSame(self::SEALED, $sealed);
    }

    public function test_open_rejects_wrong_field_user_or_tampering(): void
    {
        $vault = $this->fixedVault();

        $this->assertSame('Layla Haddad — ليلى', $vault->openField(self::SEALED, 'recipient.name', 42));
        $this->assertNull($vault->openField(self::SEALED, 'recipient.relationship', 42));
        $this->assertNull($vault->openField(self::SEALED, 'recipient.name', 43));

        $bytes = base64_decode(self::SEALED);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 0x01);
        $this->assertNull($vault->openField(base64_encode($bytes), 'recipient.name', 42));
    }
}
