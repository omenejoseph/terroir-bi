<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cellar_additions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('wine_lot_id')->constrained('wine_lots')->cascadeOnDelete();
            $table->foreignUlid('enological_product_id')->nullable()->constrained('enological_products')->nullOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('unit');
            $table->bigInteger('cost_per_unit')->nullable(); // money: minor units
            $table->bigInteger('total_cost')->nullable(); // money: minor units
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'wine_lot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cellar_additions');
    }
};
