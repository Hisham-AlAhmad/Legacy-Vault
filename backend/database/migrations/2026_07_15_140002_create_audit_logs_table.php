<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/07_Database_Design.md §audit_logs. Append-only: no updated_at, no deleted_at, and no
// application code path may UPDATE/DELETE rows (AI_RULES.md #14). user_id is deliberately
// NOT a foreign key — audit rows are retained (anonymized) even after the user is purged
// (docs/05_Security_Architecture.md §17, §21).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('actor_type', ['user', 'system', 'emergency_contact', 'admin']);
            $table->string('event_type', 80);
            $table->json('metadata')->nullable(); // no secrets, ever — serializer allow-list
            $table->binary('ip_address')->nullable(); // 4/16-byte packed via inet_pton
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
