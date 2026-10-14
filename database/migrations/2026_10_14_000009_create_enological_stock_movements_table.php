<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change to an enological product's stock, with its cause — additions
 * (single, bulk, SO₂, planned work), their corrections and reversals, manual
 * adjustments, the opening balance and imports. The prototype kept no history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enological_stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('enological_product_id')->constrained('enological_products')->cascadeOnDelete();
            $table->decimal('delta', 12, 3);
            $table->decimal('balance_after', 12, 3);
            $table->string('source'); // App\Enums\EnologicalStockSource
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'enological_product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enological_stock_movements');
    }
};
