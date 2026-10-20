<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recipe line can now be wine from the cellar (wine_lot_id — costed per litre from the lot) and any
 * line holding a liquid or a weight can be entered in a unit that converts (ml, cl, l, g, kg): `unit` is
 * the unit `quantity` is expressed in, null meaning the input's own unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->string('unit', 20)->nullable()->after('quantity');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('unit'); // the order the lines were arranged in
            $table->foreignUlid('wine_lot_id')->nullable()->after('input_id')->constrained('wine_lots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wine_lot_id');
            $table->dropColumn(['unit', 'sort_order']);
        });
    }
};
