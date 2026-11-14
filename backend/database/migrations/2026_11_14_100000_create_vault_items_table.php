<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/07_Database_Design.md §vault_items. Title, body and the wrapped content key are
// client-side envelopes (version ‖ nonce ‖ ciphertext ‖ tag — docs/06_Cryptography.md §8,
// §13); each carries its own nonce, so there is deliberately no shared `nonce` column
// (docs/19_Decision_Log.md Decision #14). The server never opens them (AI_RULES.md #13).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_items', function (Blueprint $table) {
            $table->id(); // internal only — the API addresses items by `uuid`
            // Client-generated UUIDv4, bound into the AAD (Decision #15).
            $table->uuid('uuid');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            // String rather than MySQL ENUM so the same migration runs on the SQLite dev
            // database; validated against message|file_bundle on write.
            $table->string('item_type', 20);
            $table->binary('title_enc', length: 1024);
            $table->binary('content_enc')->nullable(); // widened to LONGBLOB on MySQL below
            $table->binary('content_key_wrapped', length: 61);
            $table->unsignedTinyInteger('aad_version');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Per user, so the constraint can't be used to probe other accounts' IDs.
            $table->unique(['user_id', 'uuid']);
            $table->index(['recipient_id', 'position']);
        });

        // Laravel's binary() without a length is BLOB (64 KiB) on MySQL; the body envelope
        // may be up to 256 KiB. SQLite's BLOB has no such limit. Static DDL, no user input.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE vault_items MODIFY content_enc LONGBLOB NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_items');
    }
};
