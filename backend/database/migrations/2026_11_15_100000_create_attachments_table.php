<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/07_Database_Design.md §attachments. The filename, MIME type and wrapped key are
// client-side envelopes (docs/06_Cryptography.md §12); the file itself lives on the
// private `attachments` disk as concatenated chunk envelopes. The server never opens any
// of it (AI_RULES.md #13). `size_bytes` is ciphertext bytes and is plaintext by necessity:
// the quota is enforced without decrypting (docs/19_Decision_Log.md Decisions #19, #26).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id(); // internal only — the API addresses attachments by `uuid`
            // Client-generated UUIDv4, bound into the AAD; also the upload ID (Decision #21).
            $table->uuid('uuid');
            // Owner, stored directly so quota usage is one indexed sum (Decision #26).
            // RESTRICT, not CASCADE, on both: a cascade would delete rows and leave their
            // files on disk. Parents are removed only by VaultPurger, files first
            // (Decision #23).
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('vault_item_id')->constrained()->restrictOnDelete();
            // Server-generated, never derived from client input (docs/05 §11, Decision #23).
            $table->string('storage_path', 255);
            $table->binary('filename_enc', length: 1024);
            $table->binary('mime_type_enc', length: 157);
            $table->binary('attachment_key_wrapped', length: 61);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedSmallInteger('chunk_count');
            $table->unsignedSmallInteger('received_chunks')->default(0);
            $table->timestamp('completed_at')->nullable(); // null = upload in progress
            $table->unsignedTinyInteger('aad_version');
            $table->timestamps();
            $table->softDeletes();

            // Per user, so the constraint can't be used to probe other accounts' IDs.
            $table->unique(['user_id', 'uuid']);
            $table->index('vault_item_id');
            $table->index('deleted_at'); // the purge sweep (Decision #29)
        });

        // The purge sweep scans these by deleted_at too.
        Schema::table('recipients', function (Blueprint $table) {
            $table->index('deleted_at');
        });
        Schema::table('vault_items', function (Blueprint $table) {
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('vault_items', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
        });
        Schema::table('recipients', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
        });
        Schema::dropIfExists('attachments');
    }
};
