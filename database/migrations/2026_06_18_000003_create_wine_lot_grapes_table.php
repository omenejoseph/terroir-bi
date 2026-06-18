<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wine_lot_grapes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('wine_lot_id')->constrained('wine_lots')->cascadeOnDelete();
            $table->string('grape_variety');
            $table->decimal('percentage', 5, 2)->nullable();
            $table->bigInteger('price_per_kg')->nullable(); // money: minor units
            $table->decimal('weight_kg', 12, 3)->nullable();
            // harvest_entry_id FK is added in the Vineyards phase (harvest_entries table).
            $table->ulid('harvest_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['wine_lot_id', 'grape_variety']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wine_lot_grapes');
    }
};
