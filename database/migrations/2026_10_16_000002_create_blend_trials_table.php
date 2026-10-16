<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved blend trials from the blend workbench: which lots at what ratios, the
 * blend size and destination, and the projection as it stood when saved — so
 * trials can be compared, reopened and made later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blend_trials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->json('components'); // [{wine_lot_id, ratio}]
            $table->decimal('target_volume', 12, 3)->nullable();
            $table->foreignUlid('destination_vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->json('projection')->nullable();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blend_trials');
    }
};
