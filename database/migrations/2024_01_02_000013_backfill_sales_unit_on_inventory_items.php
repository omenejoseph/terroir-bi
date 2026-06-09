<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * sales_unit becomes a required enum (bottles|cases). Backfill existing rows:
 * anything stocked/named in cases → 'cases', otherwise 'bottles'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory_items')
            ->whereNull('sales_unit')
            ->where('unit', 'like', '%case%')
            ->update(['sales_unit' => 'cases']);

        DB::table('inventory_items')
            ->whereNull('sales_unit')
            ->update(['sales_unit' => 'bottles']);
    }

    public function down(): void
    {
        // No-op: backfilled values are valid going forward.
    }
};
