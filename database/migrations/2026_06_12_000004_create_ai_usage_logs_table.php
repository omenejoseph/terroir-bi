<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A local record of every AI call's token usage and estimated cost.
        // Cloudflare's Gateway Logs API is the reconciliation source of truth
        // for spend; this table powers fast in-app/back-office rendering and
        // survives CF API hiccups. Tenant-scoped for per-tenant attribution.
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUlid('ai_import_id')->nullable()->constrained('ai_imports')->nullOnDelete();
            $table->string('capability');                 // AiCapability
            $table->string('feature')->nullable();        // e.g. ai_import:bank_statement
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->boolean('ok')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['capability', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
