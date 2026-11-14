<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/07_Database_Design.md §encrypted_keys. Holds the three wrapped copies of each user's
// Master Key (docs/06_Cryptography.md §4). kdf_salt stores the per-wrap Argon2id salt for
// the password/recovery wraps (docs/06_Cryptography.md §7); null for the server wrap, which
// uses the SWK directly rather than a derived key.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encrypted_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('key_type', [
                'password_wrapped_master',
                'recovery_wrapped_master',
                'server_wrapped_master',
            ]);
            $table->binary('wrapped_key');       // AES-256-GCM ciphertext + tag
            $table->binary('wrap_nonce');        // 12-byte GCM nonce
            $table->binary('kdf_salt')->nullable(); // 16-byte Argon2id salt (password/recovery wraps only)
            $table->unsignedTinyInteger('wrap_version')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'key_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encrypted_keys');
    }
};
