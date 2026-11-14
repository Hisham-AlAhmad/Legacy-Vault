<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/07_Database_Design.md §sessions/devices — app-level device records backing the
// trusted-devices UI (docs/02_User_Flows.md Flow 10). Fingerprint is a hash of UA + coarse
// network info, deliberately not invasive device fingerprinting.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint_hash', 64);
            $table->string('label', 120)->nullable();
            $table->boolean('trusted')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
