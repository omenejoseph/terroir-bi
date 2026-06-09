<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a movement as a stocktake correction rather than a real physical
 * exit/entry. Reconciliation rows are excluded from spend/COGS/exit analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->boolean('is_reconciliation')->default(false)->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('is_reconciliation');
        });
    }
};
