<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enological products are priced per gram or millilitre, often below one cent
 * (potassium metabisulphite ≈ 0.006 €/g). Whole-cent storage rounded those to
 * 0.01 €/g or to zero, overstating — or erasing — every addition's cost. Unit
 * prices stay in minor units, now with four decimals (see App\Support\Money\UnitPrice).
 * Existing whole-cent values convert as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['enological_products', 'cellar_additions'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->decimal('cost_per_unit', 18, 4)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['enological_products', 'cellar_additions'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->bigInteger('cost_per_unit')->nullable()->change();
            });
        }
    }
};
