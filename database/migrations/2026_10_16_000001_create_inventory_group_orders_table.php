<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order the organisation arranges its inventory groups and subcategories in (drag and drop on the
 * Inventory list). A group row has `subcategory = ''`; a subcategory row names its group. Items keep
 * their own order in `inventory_items.sort_order`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_group_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('category', 50);
            $table->string('group_name');
            $table->string('subcategory')->default('');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'category', 'group_name', 'subcategory'], 'inventory_group_orders_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_group_orders');
    }
};
