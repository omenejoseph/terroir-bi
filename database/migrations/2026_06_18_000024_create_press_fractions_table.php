<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('press_fractions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('harvest_entry_id')->constrained('harvest_entries')->cascadeOnDelete();
            $table->foreignUlid('wine_lot_id')->nullable()->constrained('wine_lots')->nullOnDelete();
            $table->foreignUlid('vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->string('fraction_type'); // App\Enums\PressFractionType
            $table->decimal('volume_liters', 12, 3);
            $table->decimal('yield_percent', 5, 2)->nullable();
            $table->string('press_program')->nullable();
            $table->decimal('pressure_bar', 5, 2)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'harvest_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('press_fractions');
    }
};
