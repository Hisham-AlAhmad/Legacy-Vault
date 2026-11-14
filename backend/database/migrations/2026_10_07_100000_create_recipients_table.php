<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/07_Database_Design.md §recipients. name/relationship are sealed fields
// (docs/06_Cryptography.md §15 — version ‖ nonce ‖ ciphertext ‖ tag, sealed client-side
// with the Metadata Key); email/phone stay plaintext because delivery routing needs them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->binary('name_enc', length: 512);
            $table->binary('relationship_label_enc', length: 512);
            $table->string('email', 255)->nullable();
            $table->string('phone', 32)->nullable(); // E.164
            // JSON array rather than MySQL SET so the same migration runs on the SQLite dev
            // database; values are validated against email|sms|link on write.
            $table->json('preferred_methods');
            $table->boolean('is_emergency_contact')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipients');
    }
};
