<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prices and costs are stored per bottle again (case orders scale by
 * bottles_per_case). Reverts migration 000014, which had converted case-sold
 * items to per-case: divide their default_price/cost_per_unit back by
 * bottles_per_case.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory_items')
            ->where('sales_unit', 'cases')
            ->whereNotNull('default_price')
            ->update(['default_price' => DB::raw('default_price / bottles_per_case')]);

        DB::table('inventory_items')
            ->where('sales_unit', 'cases')
            ->whereNotNull('cost_per_unit')
            ->update(['cost_per_unit' => DB::raw('cost_per_unit / bottles_per_case')]);
    }

    public function down(): void
    {
        DB::table('inventory_items')
            ->where('sales_unit', 'cases')
            ->whereNotNull('default_price')
            ->update(['default_price' => DB::raw('default_price * bottles_per_case')]);

        DB::table('inventory_items')
            ->where('sales_unit', 'cases')
            ->whereNotNull('cost_per_unit')
            ->update(['cost_per_unit' => DB::raw('cost_per_unit * bottles_per_case')]);
    }
};
