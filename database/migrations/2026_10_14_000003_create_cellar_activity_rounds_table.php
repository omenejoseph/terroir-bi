<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named batch of planned cellar activities ("SO₂ 14.10.", "Protocol Red —
 * Plavac 2026"), mirrored as one card on the default Work Orders board.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cellar_activity_rounds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->foreignUlid('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cellar_activity_rounds');
    }
};
