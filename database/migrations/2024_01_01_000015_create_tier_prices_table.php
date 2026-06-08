<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tier_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignUlid('pricing_tier_id')->constrained('pricing_tiers')->cascadeOnDelete();
            $table->bigInteger('price'); // money: integer minor units
            $table->timestamps();

            $table->unique(['inventory_item_id', 'pricing_tier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tier_prices');
    }
};
