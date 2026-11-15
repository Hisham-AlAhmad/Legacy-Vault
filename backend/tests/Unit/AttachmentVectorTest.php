<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Attachment;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeClientVault;

/**
 * Cross-language known-answer vector for the attachment format (docs/06_Cryptography.md
 * §12, docs/19_Decision_Log.md Decisions #21, #22, #24; docs/15_Testing.md §2). The same
 * bytes are pinned in frontend/src/crypto/attachments.test.ts, where the browser module
 * reproduces them with WebCrypto and opens them. PHP/OpenSSL producing and opening the
 * identical bytes is what the Phase 3 delivery trigger depends on.
 *
 * The file is 1 MiB + 5 bytes, so the vector covers a full chunk, a short final chunk and
 * the chunk boundary. A diff to any pinned value here is a format change and needs
 * explicit sign-off — never a snapshot update (docs/15_Testing.md §8).
 */
class AttachmentVectorTest extends TestCase
{
    private const USER_ID = 42;
    private const RECIPIENT_ID = 7;
    private const ITEM_ID = '3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5b';
    private const ATTACHMENT_ID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
    private const FILENAME = 'وصية — will.pdf';
    private const MIME_TYPE = 'application/pdf';

    private const DECLARATION = [
        'id' => self::ATTACHMENT_ID,
        'filename_enc' => 'AQUFBQUFBQUFBQUFBTdUINsj3NQ+BOqPAFFpSq5Tp5Chbke6/My9g+cM6N2ZKIbuSOk=',
        'mime_type_enc' => 'AQYGBgYGBgYGBgYGBvl0RVbBLUv7rPYhhwB5m3tfpj+Ar1P2Fo67jproY5BnQPM/Q0mAU7tSLZ65+349CQjL9R64OgVJAthpZvVoSQni0s7Yn3hac0E52ojNcdcWYXAqhHcOey0DaSH77h7QrFC4ZkR8gWwLDR4dSevIwx3BFvq35Do0SlJyfVxX0jrcb3Dh2SXx4mWSMOxlXot2aw==',
        'attachment_key_wrapped' => 'AQQEBAQEBAQEBAQEBDvmKK5ACW6WgDLThktsNC1HSaqcYIQuzTjlCdmynGzBnskTiiIZyZ3RucgR3QZFQg==',
        'size_bytes' => 1048639,
        'chunk_count' => 2,
        'aad_version' => 1,
    ];
    private const FILE_SHA256 = '393f19225f1a665167114a4e4c1b53c3d0498da84d806420e0f7de494e5bb7aa';
    private const FINAL_CHUNK = 'AREREREREREREREREW57MoE23wv+V0NUcG6wgBnkMVznGA==';

    /** Master Key = bytes 0x00…0x1f, as in the other vector tests. */
    private function fixedVault(): FakeClientVault
    {
        $vault = new FakeClientVault();
        $vault->masterKey = implode('', array_map('chr', range(0, 31)));

        return $vault;
    }

    /** 1 MiB + 5 bytes; byte i is i mod 251. */
    private static function content(): string
    {
        return substr(str_repeat(implode('', array_map('chr', range(0, 250))), 4178), 0, 1048576 + 5);
    }

    /** @return array{declare: array<string, mixed>, chunks: list<string>} */
    private function encrypt(): array
    {
        return $this->fixedVault()->encryptAttachment(
            self::content(),
            self::FILENAME,
            self::MIME_TYPE,
            self::USER_ID,
            self::RECIPIENT_ID,
            self::ITEM_ID,
            self::ATTACHMENT_ID,
            [
                'attachment_key' => implode('', array_map('chr', range(64, 95))),
                'key_nonce' => str_repeat("\x04", 12),
                'name_nonce' => str_repeat("\x05", 12),
                'mime_nonce' => str_repeat("\x06", 12),
                'chunk_nonces' => [str_repeat("\x10", 12), str_repeat("\x11", 12)],
            ],
        );
    }

    /** @return array{filename: string, mime_type: string, content: string}|null */
    private function open(string $file, array $overrides = [], int $userId = self::USER_ID, int $recipientId = self::RECIPIENT_ID, string $itemId = self::ITEM_ID, string $attachmentId = self::ATTACHMENT_ID): ?array
    {
        return $this->fixedVault()->decryptAttachment([...self::DECLARATION, ...$overrides], $file, $userId, $recipientId, $itemId, $attachmentId);
    }

    public function test_format_constants_match_the_server_model(): void
    {
        $this->assertSame(Attachment::CHUNK_PLAINTEXT_BYTES, FakeClientVault::CHUNK_PLAINTEXT_BYTES);
        $this->assertSame(Attachment::CHUNK_BYTES, FakeClientVault::CHUNK_BYTES);
        $this->assertSame(1048605, Attachment::CHUNK_BYTES);
        $this->assertSame(157, Attachment::MIME_ENVELOPE_BYTES);
        $this->assertSame(61, Attachment::WRAPPED_KEY_BYTES);
    }

    public function test_aad_format_is_pinned(): void
    {
        $this->assertSame(
            'legacy-vault|v1|attachment.name|user:42|recipient:7|item:3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5b|attachment:a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            FakeClientVault::attachmentAad('attachment.name', self::USER_ID, self::RECIPIENT_ID, self::ITEM_ID, self::ATTACHMENT_ID),
        );
        $this->assertSame(
            'legacy-vault|v1|attachment.chunk|user:42|recipient:7|item:3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5b|attachment:a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d|chunk:1/2',
            FakeClientVault::attachmentAad('attachment.chunk', self::USER_ID, self::RECIPIENT_ID, self::ITEM_ID, self::ATTACHMENT_ID, 1, 2),
        );
    }

    public function test_encrypt_with_fixed_key_and_nonces_matches_pinned_vector(): void
    {
        $encrypted = $this->encrypt();

        $this->assertSame(self::DECLARATION, $encrypted['declare']);
        $this->assertCount(2, $encrypted['chunks']);
        $this->assertSame(Attachment::CHUNK_BYTES, strlen($encrypted['chunks'][0]));
        $this->assertSame(5 + 29, strlen($encrypted['chunks'][1]));
        $this->assertSame(self::FINAL_CHUNK, base64_encode($encrypted['chunks'][1]));
        $this->assertSame(self::FILE_SHA256, hash('sha256', implode('', $encrypted['chunks'])));
        $this->assertTrue(Attachment::isPossibleSize(self::DECLARATION['size_bytes'], self::DECLARATION['chunk_count']));
        $this->assertSame(157, strlen(base64_decode(self::DECLARATION['mime_type_enc'])));
    }

    public function test_decrypt_recovers_the_file_name_and_type(): void
    {
        $this->assertSame(
            ['filename' => self::FILENAME, 'mime_type' => self::MIME_TYPE, 'content' => self::content()],
            $this->open(implode('', $this->encrypt()['chunks'])),
        );
    }

    public function test_reordered_dropped_duplicated_and_truncated_chunks_fail(): void
    {
        // Three chunks, so there is a middle one to drop.
        $vault = $this->fixedVault();
        $content = str_repeat('A', 2 * FakeClientVault::CHUNK_PLAINTEXT_BYTES + 100);
        $encrypted = $vault->encryptAttachment($content, 'a.bin', 'application/octet-stream', self::USER_ID, self::RECIPIENT_ID, self::ITEM_ID, self::ATTACHMENT_ID);
        [$c0, $c1, $c2] = $encrypted['chunks'];
        $dto = $encrypted['declare'];
        $full = FakeClientVault::CHUNK_BYTES;

        $open = fn (string $file, array $overrides = []): ?array => $vault
            ->decryptAttachment([...$dto, ...$overrides], $file, self::USER_ID, self::RECIPIENT_ID, self::ITEM_ID, self::ATTACHMENT_ID);

        $this->assertSame($content, $open($c0.$c1.$c2)['content']);

        // Reordered: the two full chunks swapped (sizes still line up).
        $this->assertNull($open($c1.$c0.$c2));
        // Duplicated: chunk 0 in chunk 1's place.
        $this->assertNull($open($c0.$c0.$c2));
        // Dropped middle chunk — with the size and count left alone, and with them "fixed up".
        $this->assertNull($open($c0.$c2));
        $this->assertNull($open($c0.$c2, ['chunk_count' => 2, 'size_bytes' => $full + strlen($c2)]));
        // Truncated: the last chunk removed, with matching metadata.
        $this->assertNull($open($c0.$c1));
        $this->assertNull($open($c0.$c1, ['chunk_count' => 2, 'size_bytes' => 2 * $full]));
        // Truncated inside the last chunk, and trailing bytes appended.
        $this->assertNull($open(substr($c0.$c1.$c2, 0, -1)));
        $this->assertNull($open($c0.$c1.$c2.'x'));
        $this->assertNull($open($c0.$c1.$c2.$c2, ['chunk_count' => 4]));
        // A chunk from another attachment of the same item, encrypted under the same key
        // material would still fail: the attachment ID is in every chunk's AAD.
        $other = $vault->encryptAttachment($content, 'a.bin', 'application/octet-stream', self::USER_ID, self::RECIPIENT_ID, self::ITEM_ID, FakeClientVault::newItemId());
        $this->assertNull($open($c0.$other['chunks'][1].$c2));
    }

    public function test_decrypt_rejects_wrong_context_swapped_parts_and_tampering(): void
    {
        $file = implode('', $this->encrypt()['chunks']);

        // Wrong user, recipient, item, attachment.
        $this->assertNull($this->open($file, [], 43));
        $this->assertNull($this->open($file, [], self::USER_ID, 8));
        $this->assertNull($this->open($file, [], self::USER_ID, self::RECIPIENT_ID, '3f2b8c1e-9a4d-4e6f-8b7a-0c1d2e3f4a5c'));
        $this->assertNull($this->open($file, [], self::USER_ID, self::RECIPIENT_ID, self::ITEM_ID, 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5e'));

        // Filename and MIME envelopes can't stand in for each other.
        $this->assertNull($this->open($file, ['filename_enc' => self::DECLARATION['mime_type_enc']]));
        $this->assertNull($this->open($file, ['mime_type_enc' => self::DECLARATION['filename_enc']]));

        // A flipped bit in any envelope, or anywhere in the file, is caught.
        foreach (['filename_enc', 'mime_type_enc', 'attachment_key_wrapped'] as $part) {
            $bytes = base64_decode(self::DECLARATION[$part]);
            $bytes[20] = chr(ord($bytes[20]) ^ 0x01);
            $this->assertNull($this->open($file, [$part => base64_encode($bytes)]), $part);
        }
        foreach ([5, 500000, Attachment::CHUNK_BYTES - 1, Attachment::CHUNK_BYTES + 20, strlen($file) - 1] as $offset) {
            $tampered = $file;
            $tampered[$offset] = chr(ord($tampered[$offset]) ^ 0x01);
            $this->assertNull($this->open($tampered), "offset {$offset}");
        }

        // A different Master Key can't unwrap the attachment key.
        $this->assertNull((new FakeClientVault())->decryptAttachment(self::DECLARATION, $file, self::USER_ID, self::RECIPIENT_ID, self::ITEM_ID, self::ATTACHMENT_ID));
    }

    public function test_the_mime_field_is_fixed_length_whatever_the_type(): void
    {
        $vault = $this->fixedVault();
        $lengths = [];

        foreach (['application/pdf', 'image/jpeg', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'] as $type) {
            $declare = $vault->encryptAttachment('x', 'f', $type, 1, 1, self::ITEM_ID, self::ATTACHMENT_ID)['declare'];
            $lengths[] = strlen(base64_decode($declare['mime_type_enc']));
        }

        $this->assertSame([157, 157, 157], $lengths);
    }
}
