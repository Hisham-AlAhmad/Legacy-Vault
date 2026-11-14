<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/07_Database_Design.md §emergency_contacts — a distinct role from recipients, with no
// vault access (EC-1, EC-2). One per user in v1. Name is a sealed field; email/phone are
// plaintext because the confirmation link must be sent to them (Phase 3).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->binary('name_enc', length: 512);
            $table->string('email', 255);
            $table->string('phone', 32)->nullable(); // E.164
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');
    }
};
