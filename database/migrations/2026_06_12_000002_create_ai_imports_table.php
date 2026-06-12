<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An AI data-entry batch: one uploaded document whose extracted lines
        // are reviewed and committed into real records. Tenant-scoped.
        Schema::create('ai_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type');                       // AiImportType
            $table->string('status')->default('uploaded'); // AiImportStatus
            $table->string('source_object_key')->nullable(); // R2 key of the upload
            $table->string('source_filename')->nullable();
            $table->string('source_mime')->nullable();
            $table->string('provider')->nullable();        // model provider used
            $table->string('model')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->text('error')->nullable();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_imports');
    }
};
