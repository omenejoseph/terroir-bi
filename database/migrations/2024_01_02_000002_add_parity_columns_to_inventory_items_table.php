<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog/portal + vintage-grouping columns the source app's main carries that
 * weren't in the first migration snapshot. See docs/10-migration-deltas.md §C.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('unit_size')->nullable()->after('vintage');      // e.g. 750ml
            $table->string('sales_unit')->nullable()->after('unit');         // bottle/case default on order forms
            $table->integer('pack_size')->default(1)->after('bottles_per_case');
            $table->boolean('hide_from_portal')->default(false)->after('is_for_sale');
            $table->boolean('is_auto_created')->default(false)->after('hide_from_portal');
            $table->timestamp('auto_created_at')->nullable()->after('is_auto_created');
            $table->foreignUlid('base_product_id')->nullable()->after('auto_created_at')
                ->constrained('inventory_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('base_product_id');
            $table->dropColumn([
                'unit_size', 'sales_unit', 'pack_size', 'hide_from_portal',
                'is_auto_created', 'auto_created_at',
            ]);
        });
    }
};
