<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Backend locale parity — every key in lang/en has a matching key in lang/ar
 * (AI_RULES.md #10, docs/15_Testing.md §6). Mirrors frontend/src/i18n/i18n.test.ts.
 */
class LocaleParityTest extends TestCase
{
    /** @return list<string> */
    private static function flattenKeys(array $strings, string $prefix = ''): array
    {
        $keys = [];

        foreach ($strings as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $keys = is_array($value) ? [...$keys, ...self::flattenKeys($value, $path)] : [...$keys, $path];
        }

        return $keys;
    }

    public function test_en_and_ar_have_identical_key_sets(): void
    {
        $langDir = dirname(__DIR__, 2).'/lang';

        foreach (glob($langDir.'/en/*.php') as $enFile) {
            $arFile = $langDir.'/ar/'.basename($enFile);
            $this->assertFileExists($arFile);

            $en = self::flattenKeys(require $enFile);
            $ar = self::flattenKeys(require $arFile);
            sort($en);
            sort($ar);

            $this->assertSame($en, $ar, basename($enFile).' keys differ between en and ar');
        }
    }
}
