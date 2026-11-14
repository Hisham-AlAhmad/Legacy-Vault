<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Schema per docs/07_Database_Design.md §users. Column names deliberately match the doc
// (password_hash, not Laravel's default `password`).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password_hash'); // Argon2id PHC string
            $table->enum('status', ['pending', 'active', 'suspended', 'deceased_processing', 'purged'])
                ->default('pending')
                ->index(); // used heavily by the check-in sweep (Phase 3)
            $table->boolean('two_factor_enabled')->default(false);
            // TOTP secret encrypted with an app-level server key (Laravel encrypted cast),
            // NOT the vault Master Key — 2FA must work even when the vault is locked
            // (docs/07_Database_Design.md §users note).
            $table->text('two_factor_secret_enc')->nullable();
            // Secret held during enrollment, before the user confirms with a valid code.
            $table->text('two_factor_pending_secret_enc')->nullable();
            $table->json('two_factor_backup_codes_hash')->nullable();
            $table->enum('locale', ['en', 'ar'])->default('en');
            $table->unsignedSmallInteger('failed_login_count')->default(0);
            $table->timestamp('locked_until')->nullable();
            // Blocks pending→active until set (docs/02_User_Flows.md Flow 1 edge case).
            $table->timestamp('recovery_key_acknowledged_at')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            // The app-level device link (docs/07_Database_Design.md §sessions/devices) is
            // carried inside the session payload (`device_id`) — the database session
            // driver doesn't write custom columns, so a dedicated column would stay stale.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
