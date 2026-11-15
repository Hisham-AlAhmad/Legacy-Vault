<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The only code that touches attachment files on disk (docs/19_Decision_Log.md
 * Decision #23). Files are opaque ciphertext: this class moves bytes and never
 * interprets them (AI_RULES.md #13).
 *
 * Every path is built here from a server-generated UUID — nothing a client sent is ever
 * part of one (docs/05_Security_Architecture.md §11).
 */
final class AttachmentStorage
{
    /** A fresh storage path for a new attachment: `u{user_id}/{uuid}.bin`. Fixed for the row's life. */
    public function newPath(User $user): string
    {
        return 'u'.$user->id.'/'.Str::uuid()->toString().'.bin';
    }

    /**
     * Write the next chunk at its exact offset in the attachment's file.
     *
     * The file must already hold the chunks recorded so far. If it is longer — an earlier
     * request wrote its chunk but its database transaction never committed — the surplus
     * is cut off first, so the chunk is overwritten rather than duplicated and disk and
     * database can't drift apart. Returns false if the file is shorter than it should
     * be: stored chunks are missing and the upload can't continue.
     *
     * Plain fopen/fseek/fwrite under a lock, never an "append" helper: the layout is
     * fixed-offset (docs/19_Decision_Log.md Decisions #22, #25).
     */
    public function writeChunk(Attachment $attachment, int $index, string $bytes): bool
    {
        $disk = $this->disk();
        $disk->makeDirectory(dirname($attachment->storage_path));

        $offset = $index * Attachment::CHUNK_BYTES;
        $handle = fopen($disk->path($attachment->storage_path), 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Attachment storage is not writable.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Attachment storage could not be locked.');
            }

            if (fstat($handle)['size'] < $offset) {
                return false;
            }

            if (! ftruncate($handle, $offset)
                || fseek($handle, $offset) !== 0
                || fwrite($handle, $bytes) !== strlen($bytes)
                || ! fflush($handle)) {
                throw new RuntimeException('Attachment chunk could not be written.');
            }

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Bytes currently on disk for this attachment (0 if there is no file yet). */
    public function storedBytes(Attachment $attachment): int
    {
        $disk = $this->disk();

        return $disk->exists($attachment->storage_path) ? $disk->size($attachment->storage_path) : 0;
    }

    /** Absolute path of the attachment's ciphertext file, or null if it is missing. */
    public function filePath(Attachment $attachment): ?string
    {
        $disk = $this->disk();

        return $disk->exists($attachment->storage_path) ? $disk->path($attachment->storage_path) : null;
    }

    /**
     * Remove the attachment's bytes, finished or partial. Returns true when nothing is
     * left on disk — a file that was already missing counts as deleted (Decision #29).
     */
    public function delete(Attachment $attachment): bool
    {
        $disk = $this->disk();

        try {
            if ($disk->exists($attachment->storage_path)) {
                $disk->delete($attachment->storage_path);
            }
        } catch (Throwable) {
            return false;
        }

        return ! $disk->exists($attachment->storage_path);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('legacyvault.attachments.disk'));
    }
}
