<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow recipe lines that aren't backed by a catalog item: a custom ingredient
 * with its own name/unit/cost. input_id becomes nullable for these lines.
 * custom_cost follows the money convention (bigInteger minor units + MoneyCast).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->string('custom_name')->nullable()->after('quantity');
            $table->string('custom_unit')->nullable()->after('custom_name');
            $table->bigInteger('custom_cost')->nullable()->after('custom_unit'); // money: minor units
        });

        Schema::table('recipe_items', function (Blueprint $table) {
            $table->foreignUlid('input_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropColumn(['custom_name', 'custom_unit', 'custom_cost']);
        });

        Schema::table('recipe_items', function (Blueprint $table) {
            $table->foreignUlid('input_id')->nullable(false)->change();
        });
    }
};
