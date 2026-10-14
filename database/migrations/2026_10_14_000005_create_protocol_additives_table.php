<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A structured additive dose within a fermentation protocol: `dose` of a
 * product per `basis` unit (per_L, per_hL, per_kg, per_100kg). Drives
 * "Plan protocol additions" on a lot and the protocol needs report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_additives', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('fermentation_template_id')->constrained('fermentation_templates')->cascadeOnDelete();
            $table->foreignUlid('enological_product_id')->nullable()->constrained('enological_products')->nullOnDelete();
            $table->string('name');
            $table->decimal('dose', 12, 4);
            $table->string('basis'); // App\Enums\DoseBasis
            $table->string('stage')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'fermentation_template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_additives');
    }
};
