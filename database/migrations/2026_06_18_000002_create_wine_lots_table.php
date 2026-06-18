<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wine_lots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('lot_number'); // LOT-YYYY-NNN, unique per tenant
            $table->string('name');
            $table->string('grape_variety');
            $table->string('vintage');
            $table->string('vineyard')->nullable();
            $table->string('wine_type')->nullable(); // App\Enums\WineType
            $table->decimal('initial_volume', 12, 3);
            $table->decimal('current_volume', 12, 3);
            $table->string('status')->default('FERMENTING'); // App\Enums\WineLotStatus
            $table->bigInteger('grape_cost')->nullable(); // money: minor units
            $table->bigInteger('grape_price_per_kg')->nullable(); // money: minor units
            $table->decimal('harvest_weight_kg', 12, 3)->nullable();
            $table->ulid('fermentation_template_id')->nullable(); // FK added with the templates table (Phase D)
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'lot_number']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wine_lots');
    }
};
