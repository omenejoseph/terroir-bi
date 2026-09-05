<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A minimal, append-only trail of security-sensitive platform-admin
     * actions (suspend/unsuspend a user, trigger a password reset,
     * start/stop impersonation). Deliberately narrow for now — see
     * App\Services\Audit\AuditLogger's docblock; broader "log every action"
     * instrumentation is a separate, later task.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Nullable: an actor's account may later be deleted without
            // taking the historical log entry with it.
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
