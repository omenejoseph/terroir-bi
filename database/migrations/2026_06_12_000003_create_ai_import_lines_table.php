<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One AI-proposed record awaiting review. `payload` holds the extracted
        // fields (mapped to the target's fillable); `edited_payload` holds the
        // user's corrections. On commit, `committed_id` points at the created
        // row. Tenant-scoped (defense-in-depth alongside the parent import).
        Schema::create('ai_import_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('ai_import_id')->constrained('ai_imports')->cascadeOnDelete();
            $table->unsignedInteger('index')->default(0);  // order within the import
            $table->string('target_type');                  // AiTargetType
            $table->json('payload');                         // AI-proposed fields
            $table->json('edited_payload')->nullable();      // user corrections
            $table->string('category')->nullable();          // AI classification
            $table->decimal('confidence', 4, 3)->nullable(); // 0.000–1.000
            $table->string('status')->default('pending');    // AiImportLineStatus
            $table->ulid('committed_id')->nullable();         // created record id
            $table->timestamps();

            $table->index(['ai_import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_import_lines');
    }
};
