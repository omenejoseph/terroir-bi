<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prices and costs are now stored per sales unit (not per bottle). For items
 * sold in cases, convert the existing per-bottle default_price/cost_per_unit to
 * per-case by multiplying by bottles_per_case, preserving current case totals.
 */
return new class extends Migration
{
    public function up(): void
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

    public function down(): void
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
};
