<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost brought into a lot by blending (minor units): the source lots' cost per
 * litre × litres taken. The prototype dropped it, so a blend had no grape cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wine_lots', function (Blueprint $table) {
            $table->bigInteger('carried_cost')->default(0)->after('grape_cost');
        });
    }

    public function down(): void
    {
        Schema::table('wine_lots', function (Blueprint $table) {
            $table->dropColumn('carried_cost');
        });
    }
};
