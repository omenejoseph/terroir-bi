<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fermentation_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('wine_type')->nullable(); // App\Enums\WineType
            $table->string('yeast_strain')->nullable();
            $table->decimal('target_temp_min', 5, 2)->nullable();
            $table->decimal('target_temp_max', 5, 2)->nullable();
            $table->string('punchdown_schedule')->nullable();
            $table->string('maceration')->nullable();
            $table->string('nutrients')->nullable();
            $table->boolean('mlf')->default(false);
            $table->text('description')->nullable();
            $table->integer('estimated_duration')->nullable(); // days
            // [{ id, name, dayStart, dayEnd, tempMin, tempMax, actions: [{ id, type, description }] }]
            $table->json('stages')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::table('wine_lots', function (Blueprint $table) {
            $table->foreign('fermentation_template_id')->references('id')->on('fermentation_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wine_lots', function (Blueprint $table) {
            $table->dropForeign(['fermentation_template_id']);
        });
        Schema::dropIfExists('fermentation_templates');
    }
};
