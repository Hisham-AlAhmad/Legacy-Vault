<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Recipient;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Shared steps for the attachment and trash feature tests: everything is created through
 * the real API with content encrypted by the test client (real HKDF + AES-256-GCM —
 * AI_RULES.md #6), exactly as the browser would.
 *
 * The using class provides `$this->vault` (a FakeClientVault).
 */
trait BuildsVault
{
    protected function recipient(User $user, string $email = 'layla@example.com'): Recipient
    {
        return $user->recipients()->create([
            'name_enc' => base64_decode($this->vault->sealField('Layla Haddad', 'recipient.name', $user->id)),
            'relationship_label_enc' => base64_decode($this->vault->sealField('Daughter', 'recipient.relationship', $user->id)),
            'email' => $email,
            'phone' => null,
            'preferred_methods' => ['email'],
        ]);
    }

    /** @return array<string, mixed> the created item as returned by the API */
    protected function createMessage(User $user, Recipient $recipient, string $title = 'A message'): array
    {
        $id = FakeClientVault::newItemId();

        return $this->actingAs($user)->postJson("/api/v1/recipients/{$recipient->id}/items", [
            'id' => $id,
            'item_type' => 'message',
            ...$this->vault->encryptItem($title, '<p dir="auto">Body</p>', $user->id, $recipient->id, $id),
        ])->assertCreated()->json('item');
    }

    /** @return array<string, mixed> the created file bundle as returned by the API */
    protected function createBundle(User $user, Recipient $recipient, string $title = 'Documents'): array
    {
        $id = FakeClientVault::newItemId();

        return $this->actingAs($user)->postJson("/api/v1/recipients/{$recipient->id}/items", [
            'id' => $id,
            'item_type' => 'file_bundle',
            ...$this->vault->encryptItem($title, null, $user->id, $recipient->id, $id),
        ])->assertCreated()->json('item');
    }

    /**
     * Encrypt a file for an item, client-side.
     *
     * @param array<string, mixed> $item
     * @return array{declare: array<string, mixed>, chunks: list<string>}
     */
    protected function encryptFile(User $user, array $item, string $content, string $filename = 'will.pdf', string $mime = 'application/pdf'): array
    {
        return $this->vault->encryptAttachment(
            $content,
            $filename,
            $mime,
            $user->id,
            $item['recipient_id'],
            $item['id'],
            FakeClientVault::newItemId(),
        );
    }

    /** POST one raw chunk the way the browser does: octet-stream body, three headers. */
    protected function sendChunk(string $attachmentId, int $index, int $total, string $bytes, ?string $uploadId = null): TestResponse
    {
        return $this->call(
            'POST',
            "/api/v1/attachments/{$attachmentId}/chunks",
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'Accept' => 'application/json',
                'Content-Type' => 'application/octet-stream',
                'X-Chunk-Index' => (string) $index,
                'X-Chunk-Total' => (string) $total,
                'X-Upload-Id' => $uploadId ?? $attachmentId,
            ]),
            $bytes,
        );
    }

    /**
     * Declare and fully upload a file. Returns the completed attachment as the API
     * returned it with the last chunk.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    protected function upload(User $user, array $item, string $content = 'The deed is in the blue folder.', string $filename = 'will.pdf'): array
    {
        $file = $this->encryptFile($user, $item, $content, $filename);
        $this->actingAs($user)->postJson("/api/v1/vault-items/{$item['id']}/attachments", $file['declare'])->assertCreated();

        $response = null;
        foreach ($file['chunks'] as $index => $chunk) {
            $response = $this->sendChunk($file['declare']['id'], $index, count($file['chunks']), $chunk)->assertOk();
        }

        return $response->json('attachment');
    }

    /** The stored ciphertext file, fetched through the API. */
    protected function download(string $attachmentId): string
    {
        $response = $this->get("/api/v1/attachments/{$attachmentId}/content")->assertOk();

        return file_get_contents($response->baseResponse->getFile()->getPathname());
    }
}
