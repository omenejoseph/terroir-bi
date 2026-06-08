<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger of every stock change. current_stock on inventory_items is
 * the running total maintained alongside these entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('type'); // App\Enums\StockMovementType
            $table->decimal('quantity', 12, 3); // signed
            $table->string('unit')->nullable();
            $table->string('note')->nullable();
            $table->string('reference')->nullable(); // order number, PROD-{sku}, INVCHECK-{date}
            $table->timestamps();

            $table->index(['tenant_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
