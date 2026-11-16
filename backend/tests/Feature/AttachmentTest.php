<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsVault;
use Tests\Support\FakeClientVault;
use Tests\TestCase;

/**
 * Attachments, quota and file bundles — Phase 2c (VAULT-6, VAULT-7, NFR-3;
 * docs/19_Decision_Log.md Decisions #21–#27). Every file is encrypted with real
 * HKDF + AES-256-GCM by the test client, never stubbed (AI_RULES.md #6); the server only
 * ever stores and returns ciphertext.
 */
class AttachmentTest extends TestCase
{
    use BuildsVault;
    use RefreshDatabase;

    private const FILENAME = 'will_and_testament_final.pdf';
    private const CONTENT = "%PDF-1.7 My last will. The house goes to Layla. وصيتي الأخيرة.\n";

    private FakeClientVault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = new FakeClientVault();
        Storage::fake('attachments');
    }

    /** Every file currently on the attachments disk, relative paths. */
    private function storedFiles(): array
    {
        return Storage::disk('attachments')->allFiles();
    }

    public function test_upload_stores_only_ciphertext_and_round_trips(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $bundle = $this->createBundle($user, $recipient);

        $file = $this->encryptFile($user, $bundle, self::CONTENT, self::FILENAME);
        $declared = $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $file['declare'])->assertCreated();

        $declared->assertJsonPath('attachment.status', 'uploading')
            ->assertJsonPath('attachment.received_chunks', 0)
            ->assertJsonPath('attachment.item_id', $bundle['id'])
            ->assertJsonPath('storage.limit_bytes', 10485760)
            ->assertJsonPath('storage.used_bytes', $file['declare']['size_bytes']); // reserved before any byte arrives

        // Nothing is listed, downloadable or audited until the upload completes.
        $this->assertSame([], $this->getJson("/api/v1/vault-items/{$bundle['id']}/attachments")->json('attachments'));
        $this->get("/api/v1/attachments/{$file['declare']['id']}/content")->assertNotFound();
        $this->assertSame(0, DB::table('audit_logs')->where('event_type', 'attachment.uploaded')->count());

        $done = $this->sendChunk($file['declare']['id'], 0, 1, $file['chunks'][0])->assertOk();
        $done->assertJsonPath('received_chunk', 0)->assertJsonPath('attachment.status', 'complete');
        $attachment = $done->json('attachment');

        // The client-chosen ID is the public ID; numeric keys and the path never leave.
        $this->assertSame($file['declare']['id'], $attachment['id']);
        $this->assertArrayNotHasKey('storage_path', $attachment);
        $this->assertArrayNotHasKey('uuid', $attachment);

        $listed = $this->getJson("/api/v1/vault-items/{$bundle['id']}/attachments")->assertOk()->json('attachments');
        $this->assertSame([$attachment['id']], array_column($listed, 'id'));

        // Download → decrypt gives back the exact file, name and type.
        $ciphertext = $this->download($attachment['id']);
        $this->assertSame(
            ['filename' => self::FILENAME, 'mime_type' => 'application/pdf', 'content' => self::CONTENT],
            $this->vault->decryptAttachment($listed[0], $ciphertext, $user->id, $recipient->id, $bundle['id'], $attachment['id']),
        );

        // Raw database row: no filename, no type, no content (docs/18_Threat_Model.md scenario 1).
        $row = (array) DB::table('attachments')->where('uuid', $attachment['id'])->first();
        $dump = implode('|', array_map('strval', $row));
        foreach (['will', 'testament', 'pdf', 'PDF', 'application', 'Layla', 'وصيتي'] as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }
        $this->assertSame(61, strlen($row['attachment_key_wrapped']));
        $this->assertSame(157, strlen($row['mime_type_enc'])); // padded: the length doesn't reveal the type
        $this->assertSame(strlen(self::CONTENT) + 29, $row['size_bytes']);

        // The file on disk: a server-generated name, ciphertext only.
        $this->assertSame([$row['storage_path']], $this->storedFiles());
        $this->assertMatchesRegularExpression(
            '#\Au'.$user->id.'/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.bin\z#',
            $row['storage_path'],
        );
        $this->assertStringNotContainsString($attachment['id'], $row['storage_path']); // not the client's ID either
        $onDisk = Storage::disk('attachments')->get($row['storage_path']);
        $this->assertSame($ciphertext, $onDisk);
        foreach (['%PDF', 'last will', 'Layla', 'وصيتي', 'will_and'] as $needle) {
            $this->assertStringNotContainsString($needle, $onDisk);
        }

        // The audit entry carries IDs, size and count only — never a filename.
        $audit = json_decode(DB::table('audit_logs')->where('event_type', 'attachment.uploaded')->sole()->metadata, true);
        $this->assertEqualsCanonicalizing([
            'attachment_id' => $attachment['id'],
            'item_id' => $bundle['id'],
            'recipient_id' => $recipient->id,
            'size_bytes' => strlen(self::CONTENT) + 29,
            'chunk_count' => 1,
        ], $audit);
    }

    public function test_download_is_an_opaque_non_cacheable_attachment(): void
    {
        $user = User::factory()->create();
        $bundle = $this->createBundle($user, $this->recipient($user));
        $attachment = $this->upload($user, $bundle);

        $response = $this->get("/api/v1/attachments/{$attachment['id']}/content")->assertOk();

        $response->assertHeader('Content-Type', 'application/octet-stream');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString($attachment['id'].'.bin', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        // The path on disk is not in any header.
        $path = DB::table('attachments')->value('storage_path');
        $this->assertStringNotContainsString(basename($path), (string) $response->headers);
    }

    public function test_multi_chunk_upload_in_order_and_resume(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $message = $this->createMessage($user, $recipient); // messages can carry files too
        $content = random_bytes(FakeClientVault::CHUNK_PLAINTEXT_BYTES + 4096);

        $file = $this->encryptFile($user, $message, $content, 'video.bin', 'application/octet-stream');
        $id = $file['declare']['id'];
        $this->assertCount(2, $file['chunks']);
        $this->assertSame(FakeClientVault::CHUNK_BYTES, strlen($file['chunks'][0]));

        $this->postJson("/api/v1/vault-items/{$message['id']}/attachments", $file['declare'])->assertCreated();

        $this->sendChunk($id, 0, 2, $file['chunks'][0])->assertOk()->assertExactJson(['received_chunk' => 0]);

        // Resume: the server says how far it got.
        $this->getJson("/api/v1/attachments/{$id}")->assertOk()
            ->assertJsonPath('attachment.received_chunks', 1)
            ->assertJsonPath('attachment.status', 'uploading');

        $attachment = $this->sendChunk($id, 1, 2, $file['chunks'][1])->assertOk()->json('attachment');
        $this->assertSame(2, $attachment['received_chunks']);
        $this->assertSame(strlen($content) + 2 * 29, $attachment['size_bytes']);

        $ciphertext = $this->download($id);
        $this->assertSame($file['chunks'][0].$file['chunks'][1], $ciphertext);
        $this->assertSame(
            $content,
            $this->vault->decryptAttachment($attachment, $ciphertext, $user->id, $recipient->id, $message['id'], $id)['content'],
        );
    }

    public function test_chunks_out_of_sequence_are_rejected(): void
    {
        $user = User::factory()->create();
        $bundle = $this->createBundle($user, $this->recipient($user));
        $file = $this->encryptFile($user, $bundle, random_bytes(FakeClientVault::CHUNK_PLAINTEXT_BYTES + 10));
        $id = $file['declare']['id'];
        $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $file['declare'])->assertCreated();

        // Skipping ahead.
        $this->sendChunk($id, 1, 2, $file['chunks'][1])
            ->assertStatus(409)->assertJsonPath('error.code', 'CHUNK_SEQUENCE_ERROR');

        $this->sendChunk($id, 0, 2, $file['chunks'][0])->assertOk();

        // Repeating a chunk already stored.
        $this->sendChunk($id, 0, 2, $file['chunks'][0])
            ->assertStatus(409)->assertJsonPath('error.code', 'CHUNK_SEQUENCE_ERROR');

        // The final chunk in the wrong size, or with the wrong version byte, is malformed.
        $this->sendChunk($id, 1, 2, $file['chunks'][1].'x')->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['chunk']]]);
        $this->sendChunk($id, 1, 2, "\x02".substr($file['chunks'][1], 1))->assertStatus(422);
        $this->sendChunk($id, 1, 2, '')->assertStatus(422);

        // A total or upload ID that doesn't match the declaration.
        $this->sendChunk($id, 1, 3, $file['chunks'][1])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['chunk_total']]]);
        $this->sendChunk($id, 1, 2, $file['chunks'][1], FakeClientVault::newItemId())->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['upload_id']]]);
        $this->sendChunk($id, 1, 2, $file['chunks'][1], 'not-a-uuid')->assertStatus(422);

        // Anything bigger than one full chunk is refused outright.
        $this->sendChunk($id, 1, 2, str_repeat("\x01", FakeClientVault::CHUNK_BYTES + 1))
            ->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');

        // None of that damaged the upload: the right chunk still completes it.
        $this->getJson("/api/v1/attachments/{$id}")->assertJsonPath('attachment.received_chunks', 1);
        $this->sendChunk($id, 1, 2, $file['chunks'][1])->assertOk()->assertJsonPath('attachment.status', 'complete');
        $this->assertSame($file['chunks'][0].$file['chunks'][1], $this->download($id));

        // And nothing more is accepted afterwards.
        $this->sendChunk($id, 2, 2, $file['chunks'][1])->assertStatus(409);
        $this->sendChunk($id, 1, 2, $file['chunks'][1])->assertStatus(409);
        $this->assertSame($file['declare']['size_bytes'], Storage::disk('attachments')->size($this->storedFiles()[0]));
    }

    public function test_a_chunk_written_but_never_committed_is_overwritten_not_duplicated(): void
    {
        $user = User::factory()->create();
        $bundle = $this->createBundle($user, $this->recipient($user));
        $file = $this->encryptFile($user, $bundle, random_bytes(FakeClientVault::CHUNK_PLAINTEXT_BYTES + 10));
        $id = $file['declare']['id'];
        $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $file['declare'])->assertCreated();
        $this->sendChunk($id, 0, 2, $file['chunks'][0])->assertOk();

        // Simulate a request that wrote its bytes and then lost its transaction.
        $path = DB::table('attachments')->value('storage_path');
        Storage::disk('attachments')->append($path, 'stray bytes from a request that never committed', '');

        $this->sendChunk($id, 1, 2, $file['chunks'][1])->assertOk()->assertJsonPath('attachment.status', 'complete');
        $this->assertSame($file['chunks'][0].$file['chunks'][1], Storage::disk('attachments')->get($path));
    }

    public function test_malformed_declarations_are_rejected(): void
    {
        $user = User::factory()->create();
        $bundle = $this->createBundle($user, $this->recipient($user));
        $url = "/api/v1/vault-items/{$bundle['id']}/attachments";
        $valid = $this->encryptFile($user, $bundle, self::CONTENT)['declare'];

        $rejects = function (array $overrides, string $field) use ($url, $valid): void {
            $this->postJson($url, [...$valid, ...$overrides])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonStructure(['error' => ['fields' => [$field]]]);
        };

        $rejects(['id' => 'not-a-uuid'], 'id');
        $rejects(['id' => strtoupper(FakeClientVault::newItemId())], 'id');
        $rejects(['aad_version' => 2], 'aad_version');

        $rejects(['filename_enc' => 'not-base64!!'], 'filename_enc');
        $rejects(['filename_enc' => base64_encode("\x01".random_bytes(1100))], 'filename_enc'); // over 1024 bytes
        $rejects(['filename_enc' => base64_encode("\x02".random_bytes(40))], 'filename_enc');   // wrong version byte

        // The MIME envelope is exactly 157 bytes and the wrapped key exactly 61.
        $rejects(['mime_type_enc' => base64_encode("\x01".random_bytes(40))], 'mime_type_enc');
        $rejects(['attachment_key_wrapped' => base64_encode("\x01".random_bytes(61))], 'attachment_key_wrapped');

        // The size has to be one that many chunks can add up to.
        $rejects(['size_bytes' => 0], 'size_bytes');
        $rejects(['size_bytes' => 29], 'size_bytes');                                           // an envelope with no content
        $rejects(['size_bytes' => FakeClientVault::CHUNK_BYTES + 1], 'size_bytes');             // too big for one chunk
        $rejects(['chunk_count' => 2], 'size_bytes');                                           // too small for two
        $rejects(['chunk_count' => 0], 'chunk_count');
        $rejects(['chunk_count' => 'three'], 'chunk_count');

        $this->postJson($url, [])->assertStatus(422);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], $this->storedFiles());
    }

    public function test_an_upload_that_would_exceed_the_quota_is_refused_before_any_bytes(): void
    {
        $user = User::factory()->create();
        $bundle = $this->createBundle($user, $this->recipient($user));
        $url = "/api/v1/vault-items/{$bundle['id']}/attachments";
        $limit = 10485760; // the default: 10 MiB of ciphertext (Decision #19)
        $this->assertSame($limit, config('legacyvault.attachment_quota_bytes'));

        $declare = fn (int $sizeBytes, int $chunks): array => [
            ...$this->encryptFile($user, $bundle, 'x')['declare'],
            'size_bytes' => $sizeBytes,
            'chunk_count' => $chunks,
        ];

        // One byte over the limit: refused at declaration, with nothing uploaded.
        $this->postJson($url, $declare($limit + 1, 10))
            ->assertStatus(413)->assertJsonPath('error.code', 'QUOTA_EXCEEDED');
        $this->assertDatabaseCount('attachments', 0);

        // 6 MiB fits and is reserved immediately…
        $six = 6 * 1048576;
        $this->postJson($url, $declare($six, 6))->assertCreated()->assertJsonPath('storage.used_bytes', $six);

        // …so a second 6 MiB no longer fits, although not one content byte has arrived.
        $this->postJson($url, $declare($six, 6))->assertStatus(413)->assertJsonPath('error.code', 'QUOTA_EXCEEDED');
        $this->assertSame([], $this->storedFiles());

        // Exactly the remainder fits; one byte more does not.
        $remaining = $limit - $six;
        $this->postJson($url, $declare($remaining + 1, 4))->assertStatus(413);
        $this->postJson($url, $declare($remaining, 4))->assertCreated()
            ->assertJsonPath('storage.used_bytes', $limit);

        $this->getJson('/api/v1/vault/storage')->assertOk()->assertExactJson([
            'storage' => ['limit_bytes' => $limit, 'used_bytes' => $limit, 'trashed_bytes' => 0],
            'undo_window_days' => 30,
        ]);

        // The limit is one config value: raise it and the same request passes.
        config(['legacyvault.attachment_quota_bytes' => $limit + 1000]);
        $this->postJson($url, $declare(1000, 1))->assertCreated()->assertJsonPath('storage.limit_bytes', $limit + 1000);
    }

    public function test_quota_is_per_user_and_counts_deleted_files_until_they_are_gone(): void
    {
        config(['legacyvault.attachment_quota_bytes' => 300]);

        $user = User::factory()->create();
        $other = User::factory()->create();
        $bundle = $this->createBundle($user, $this->recipient($user));
        $othersBundle = $this->createBundle($other, $this->recipient($other, 'o@example.com'));

        $attachment = $this->upload($user, $bundle, str_repeat('a', 200)); // 229 bytes stored
        $this->actingAs($user)->getJson('/api/v1/vault/storage')->assertJsonPath('storage.used_bytes', 229);

        // No room for another 229 — but someone else's usage is their own.
        $next = $this->encryptFile($user, $bundle, str_repeat('b', 200));
        $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $next['declare'])->assertStatus(413);
        $this->upload($other, $othersBundle, str_repeat('c', 200));

        // Deleting does not free the space: the file is still on disk until purged.
        $this->actingAs($user)->deleteJson("/api/v1/attachments/{$attachment['id']}")->assertOk()
            ->assertJsonPath('storage.used_bytes', 229)
            ->assertJsonPath('storage.trashed_bytes', 229);
        $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $next['declare'])->assertStatus(413);

        // Erasing it does.
        $this->deleteJson("/api/v1/trash/attachments/{$attachment['id']}", ['password' => 'correct horse battery staple 9!'])
            ->assertOk()->assertJsonPath('storage.used_bytes', 0);
        $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $next['declare'])->assertCreated();
    }

    public function test_another_users_attachments_are_not_found(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $bundle = $this->createBundle($owner, $this->recipient($owner));
        $attachment = $this->upload($owner, $bundle);
        $pending = $this->encryptFile($owner, $bundle, self::CONTENT);
        $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $pending['declare'])->assertCreated();

        $intrudersBundle = $this->createBundle($intruder, $this->recipient($intruder, 'i@example.com'));
        $this->actingAs($intruder);

        // The owner's item and attachments don't exist as far as the intruder can tell.
        $this->getJson("/api/v1/vault-items/{$bundle['id']}/attachments")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $this->encryptFile($intruder, $bundle, 'x')['declare'])->assertNotFound();
        $this->getJson("/api/v1/attachments/{$attachment['id']}")->assertNotFound();
        $this->get("/api/v1/attachments/{$attachment['id']}/content")->assertNotFound();
        $this->deleteJson("/api/v1/attachments/{$attachment['id']}")->assertNotFound();
        $this->sendChunk($pending['declare']['id'], 0, 1, $pending['chunks'][0])->assertNotFound();
        $this->postJson("/api/v1/trash/attachments/{$attachment['id']}/restore")->assertNotFound();
        $this->deleteJson("/api/v1/trash/attachments/{$attachment['id']}", ['password' => 'correct horse battery staple 9!'])->assertNotFound();

        // Malformed IDs are the same plain 404.
        $this->getJson('/api/v1/attachments/123')->assertNotFound();
        $this->getJson('/api/v1/attachments/'.strtoupper($attachment['id']))->assertNotFound();

        // IDs are scoped per user: the intruder reusing the owner's UUID learns nothing.
        $reuse = $this->encryptFile($intruder, $intrudersBundle, 'mine');
        $reuse['declare']['id'] = $attachment['id'];
        $this->postJson("/api/v1/vault-items/{$intrudersBundle['id']}/attachments", $reuse['declare'])->assertCreated();

        // The intruder's storage numbers are their own.
        $this->getJson('/api/v1/vault/storage')->assertJsonPath('storage.used_bytes', $reuse['declare']['size_bytes']);

        // The owner's data is untouched.
        $this->assertNotSoftDeleted('attachments', ['uuid' => $attachment['id'], 'user_id' => $owner->id]);
        $this->actingAs($owner)->get("/api/v1/attachments/{$attachment['id']}/content")->assertOk();
    }

    public function test_an_attachment_cannot_be_decrypted_in_another_context(): void
    {
        $user = User::factory()->create();
        $layla = $this->recipient($user);
        $omar = $this->recipient($user, 'omar@example.com');
        $laylasBundle = $this->createBundle($user, $layla);
        $omarsBundle = $this->createBundle($user, $omar);
        $attachment = $this->upload($user, $laylasBundle, self::CONTENT);
        $ciphertext = $this->download($attachment['id']);

        $open = fn (int $userId, int $recipientId, string $itemId, string $attachmentId): ?array => $this->vault
            ->decryptAttachment($attachment, $ciphertext, $userId, $recipientId, $itemId, $attachmentId);

        $this->assertNotNull($open($user->id, $layla->id, $laylasBundle['id'], $attachment['id']));
        $this->assertNull($open($user->id + 1, $layla->id, $laylasBundle['id'], $attachment['id']));
        $this->assertNull($open($user->id, $omar->id, $laylasBundle['id'], $attachment['id']));
        $this->assertNull($open($user->id, $layla->id, $omarsBundle['id'], $attachment['id']));
        $this->assertNull($open($user->id, $layla->id, $laylasBundle['id'], FakeClientVault::newItemId()));

        // Re-pointing the row at Omar's bundle in the database makes the server list it
        // there — and it fails to decrypt as Omar's instead of being delivered to him.
        DB::table('attachments')->where('uuid', $attachment['id'])
            ->update(['vault_item_id' => DB::table('vault_items')->where('uuid', $omarsBundle['id'])->value('id')]);
        $moved = $this->getJson("/api/v1/vault-items/{$omarsBundle['id']}/attachments")->json('attachments.0');
        $this->assertSame($attachment['id'], $moved['id']);
        $this->assertNull($this->vault->decryptAttachment($moved, $ciphertext, $user->id, $omar->id, $omarsBundle['id'], $moved['id']));

        // Lying about the chunk count in the database breaks every chunk.
        $this->assertNull($this->vault->decryptAttachment([...$attachment, 'chunk_count' => 2], $ciphertext, $user->id, $layla->id, $laylasBundle['id'], $attachment['id']));
    }

    public function test_attachment_ids_and_per_item_count_are_limited(): void
    {
        config(['legacyvault.attachments.max_per_item' => 2]);

        $user = User::factory()->create();
        $bundle = $this->createBundle($user, $this->recipient($user));
        $url = "/api/v1/vault-items/{$bundle['id']}/attachments";
        $first = $this->upload($user, $bundle);

        // An ID is never reused — not while live, and not after deletion.
        $reuse = $this->encryptFile($user, $bundle, 'x');
        $reuse['declare']['id'] = $first['id'];
        $this->postJson($url, $reuse['declare'])->assertStatus(409)->assertJsonPath('error.code', 'ATTACHMENT_ID_CONFLICT');
        $this->deleteJson("/api/v1/attachments/{$first['id']}")->assertOk();
        $this->postJson($url, $reuse['declare'])->assertStatus(409);

        // Two live attachments (one still uploading) fill the item.
        $this->upload($user, $bundle);
        $this->postJson($url, $this->encryptFile($user, $bundle, 'x')['declare'])->assertCreated();
        $this->postJson($url, $this->encryptFile($user, $bundle, 'x')['declare'])
            ->assertStatus(403)->assertJsonPath('error.code', 'ATTACHMENT_LIMIT_REACHED');

        // …so the deleted one can't be restored into it either.
        $this->postJson("/api/v1/trash/attachments/{$first['id']}/restore")
            ->assertStatus(403)->assertJsonPath('error.code', 'ATTACHMENT_LIMIT_REACHED');
    }

    public function test_declared_uploads_are_rate_limited(): void
    {
        config(['legacyvault.attachments.uploads_per_hour' => 2]);

        $user = User::factory()->create();
        $bundle = $this->createBundle($user, $this->recipient($user));
        $url = "/api/v1/vault-items/{$bundle['id']}/attachments";

        $this->postJson($url, $this->encryptFile($user, $bundle, 'x')['declare'])->assertCreated();
        $this->postJson($url, $this->encryptFile($user, $bundle, 'x')['declare'])->assertCreated();
        $this->postJson($url, $this->encryptFile($user, $bundle, 'x')['declare'])
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_delete_soft_deletes_a_file_and_aborts_an_unfinished_upload(): void
    {
        $user = User::factory()->create();
        $bundle = $this->createBundle($user, $this->recipient($user));
        $kept = $this->upload($user, $bundle, 'keep');
        $gone = $this->upload($user, $bundle, 'gone');

        // No step-up password, as for items (Decision #17).
        $this->deleteJson("/api/v1/attachments/{$gone['id']}")->assertOk()->assertJsonPath('ok', true);

        $this->assertSoftDeleted('attachments', ['uuid' => $gone['id']]);
        $this->assertSame([$kept['id']], array_column($this->getJson("/api/v1/vault-items/{$bundle['id']}/attachments")->json('attachments'), 'id'));
        $this->getJson("/api/v1/attachments/{$gone['id']}")->assertNotFound();
        $this->get("/api/v1/attachments/{$gone['id']}/content")->assertNotFound();
        $this->deleteJson("/api/v1/attachments/{$gone['id']}")->assertNotFound();
        $this->assertCount(2, $this->storedFiles()); // still on disk: it can be restored

        $audit = json_decode(DB::table('audit_logs')->where('event_type', 'attachment.deleted')->sole()->metadata, true);
        $this->assertEqualsCanonicalizing(['attachment_id' => $gone['id'], 'item_id' => $bundle['id'], 'size_bytes' => 4 + 29], $audit);

        // Aborting an unfinished upload removes the row and the partial file at once.
        $file = $this->encryptFile($user, $bundle, random_bytes(FakeClientVault::CHUNK_PLAINTEXT_BYTES + 5));
        $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $file['declare'])->assertCreated();
        $this->sendChunk($file['declare']['id'], 0, 2, $file['chunks'][0])->assertOk();
        $this->assertCount(3, $this->storedFiles());
        $usedBefore = $this->getJson('/api/v1/vault/storage')->json('storage.used_bytes');

        $this->deleteJson("/api/v1/attachments/{$file['declare']['id']}")->assertOk()
            ->assertJsonPath('storage.used_bytes', $usedBefore - $file['declare']['size_bytes']);

        $this->assertDatabaseMissing('attachments', ['uuid' => $file['declare']['id']]);
        $this->assertCount(2, $this->storedFiles());
        $this->sendChunk($file['declare']['id'], 1, 2, $file['chunks'][1])->assertNotFound();
        $this->assertSame(1, DB::table('audit_logs')->where('event_type', 'attachment.upload_aborted')->count());
        // Only the deleted file is in the recently-deleted list; the aborted upload is simply gone.
        $this->assertSame([$gone['id']], array_column($this->getJson('/api/v1/trash')->json('attachments'), 'id'));
    }

    public function test_file_bundles_have_a_title_and_no_body(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $this->actingAs($user);
        $url = "/api/v1/recipients/{$recipient->id}/items";

        $bundle = $this->createBundle($user, $recipient, 'House documents — أوراق البيت');
        $this->assertSame('file_bundle', $bundle['item_type']);
        $this->assertNull($bundle['content_enc']);
        $this->assertNull(DB::table('vault_items')->where('uuid', $bundle['id'])->value('content_enc'));
        $this->assertSame(
            ['title' => 'House documents — أوراق البيت', 'body' => null],
            $this->vault->decryptItem($bundle, $user->id, $recipient->id, $bundle['id']),
        );

        // A bundle with a body is refused; a message without one still is, too.
        $id = FakeClientVault::newItemId();
        $withBody = $this->vault->encryptItem('t', '<p>b</p>', $user->id, $recipient->id, $id);
        $this->postJson($url, ['id' => $id, 'item_type' => 'file_bundle', ...$withBody])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['content_enc']]]);
        $this->postJson($url, ['id' => $id, 'item_type' => 'message', ...$this->vault->encryptItem('t', null, $user->id, $recipient->id, $id)])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['content_enc']]]);

        // Renaming rotates the key; a body can't be slipped in and the type can't change.
        $itemUrl = "/api/v1/vault-items/{$bundle['id']}";
        $renamed = $this->vault->encryptItem('Renamed', null, $user->id, $recipient->id, $bundle['id']);
        $this->patchJson($itemUrl, [...$renamed, 'content_enc' => $withBody['content_enc']])->assertStatus(422);
        $this->patchJson($itemUrl, [...$renamed, 'content_key_wrapped' => $bundle['content_key_wrapped']])->assertStatus(422);
        $updated = $this->patchJson($itemUrl, [...$renamed, 'item_type' => 'message'])->assertOk()->json('item');
        $this->assertSame('file_bundle', $updated['item_type']);
        $this->assertSame('Renamed', $this->vault->decryptItem($updated, $user->id, $recipient->id, $bundle['id'])['title']);

        // Listed alongside messages, in order.
        $message = $this->createMessage($user, $recipient);
        $this->assertSame(
            [[$bundle['id'], 'file_bundle'], [$message['id'], 'message']],
            array_map(fn (array $i): array => [$i['id'], $i['item_type']], $this->getJson($url)->json('items')),
        );

        // Editing an item never touches its attachments: they have their own keys.
        $attachment = $this->upload($user, $bundle, self::CONTENT);
        $again = $this->vault->encryptItem('Renamed again', null, $user->id, $recipient->id, $bundle['id']);
        $this->patchJson($itemUrl, $again)->assertOk();
        $this->assertSame(
            self::CONTENT,
            $this->vault->decryptAttachment($attachment, $this->download($attachment['id']), $user->id, $recipient->id, $bundle['id'], $attachment['id'])['content'],
        );
    }

    public function test_pending_and_anonymous_users_are_rejected(): void
    {
        $id = FakeClientVault::newItemId();

        $this->getJson('/api/v1/vault/storage')->assertStatus(401);
        $this->getJson("/api/v1/vault-items/{$id}/attachments")->assertStatus(401);
        $this->postJson("/api/v1/vault-items/{$id}/attachments", [])->assertStatus(401);
        $this->getJson("/api/v1/attachments/{$id}")->assertStatus(401);
        $this->getJson("/api/v1/attachments/{$id}/content")->assertStatus(401);
        $this->deleteJson("/api/v1/attachments/{$id}")->assertStatus(401);
        $this->getJson('/api/v1/trash')->assertStatus(401);
        $this->postJson("/api/v1/trash/items/{$id}/restore")->assertStatus(401);
        $this->deleteJson("/api/v1/trash/items/{$id}", ['password' => 'x'])->assertStatus(401);

        // An account that isn't active (Recovery Key never acknowledged) can't upload.
        $pending = User::factory()->pending()->create();
        $recipient = $this->recipient($pending, 'p@example.com');
        $item = $pending->vaultItems()->forceCreate([
            'uuid' => $id,
            'user_id' => $pending->id,
            'recipient_id' => $recipient->id,
            'item_type' => 'file_bundle',
            'title_enc' => "\x01".random_bytes(40),
            'content_key_wrapped' => "\x01".random_bytes(60),
            'aad_version' => 1,
        ]);
        $this->actingAs($pending);
        $file = $this->vault->encryptAttachment('x', 'a.txt', 'text/plain', $pending->id, $recipient->id, $id, FakeClientVault::newItemId());

        foreach ([
            $this->getJson('/api/v1/vault/storage'),
            $this->getJson("/api/v1/vault-items/{$item->uuid}/attachments"),
            $this->postJson("/api/v1/vault-items/{$item->uuid}/attachments", $file['declare']),
            $this->getJson('/api/v1/trash'),
        ] as $response) {
            $response->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_NOT_ACTIVE');
        }

        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame([], $this->storedFiles());
    }
}
