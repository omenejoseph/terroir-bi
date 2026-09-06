<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The pre-rebate ("list") unit price, alongside the already-rebated
     * `unit_price` — so the drawer's Profitability card can show the real
     * Gross / Rebate / Net breakdown instead of reconstructing it from the
     * customer's current rebate %, which would be wrong for a historical
     * order if the rebate has since changed.
     *
     * Nullable, and left null on existing rows: there is no way to recover a
     * pre-existing order's gross price after the fact, so those orders keep
     * showing the simpler Revenue/COGS/Margin view — see OrderData::profitability().
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->bigInteger('unit_price_gross')->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_price_gross');
        });
    }
};
