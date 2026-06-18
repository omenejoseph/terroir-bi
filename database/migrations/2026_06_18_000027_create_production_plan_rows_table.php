<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_plan_rows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('plan_id')->constrained('production_plans')->cascadeOnDelete();
            $table->foreignUlid('base_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignUlid('created_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->string('new_vintage')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('plan_unit')->default('bottles'); // App\Enums\PlanUnit
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_plan_rows');
    }
};
