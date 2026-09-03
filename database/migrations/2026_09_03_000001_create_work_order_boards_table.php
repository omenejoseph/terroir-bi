<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named board (Figma 267:1781's "Cellar Operations", "Vineyard &
 * Maintenance") — the real entity the picker's category-derived stand-in
 * (App\Services\Tasks\WorkOrderBoardPresenter) was always missing.
 * `sort_order` supports a future drag-to-reorder of the picker itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_boards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_boards');
    }
};
