<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_checks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('performed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference');
            $table->unsignedInteger('items_counted')->default(0);
            $table->unsignedInteger('items_adjusted')->default(0);
            $table->decimal('net_difference', 14, 3)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('inventory_check_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('inventory_check_id')->constrained('inventory_checks')->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->string('name');
            $table->string('sku');
            $table->decimal('system_count', 14, 3);
            $table->decimal('physical_count', 14, 3);
            $table->decimal('difference', 14, 3);
            $table->timestamps();

            $table->index(['inventory_check_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_check_lines');
        Schema::dropIfExists('inventory_checks');
    }
};
