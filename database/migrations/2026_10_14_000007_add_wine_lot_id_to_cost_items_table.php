<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cost line can be charged to a wine lot (barrels, contract work, …); it
 * then counts in that lot's cost. The prototype's CostItem.wineLotId, which
 * the first legacy import dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_items', function (Blueprint $table) {
            $table->foreignUlid('wine_lot_id')->nullable()->after('inventory_item_id')->constrained('wine_lots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cost_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wine_lot_id');
        });
    }
};
