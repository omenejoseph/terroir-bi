<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bottlings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('wine_lot_id')->constrained('wine_lots')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->integer('bottle_count');
            $table->integer('bottle_volume_ml')->default(750);
            $table->decimal('volume_used', 12, 3); // liters drawn from the lot
            $table->timestamp('date')->useCurrent();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'wine_lot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bottlings');
    }
};
