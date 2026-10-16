<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's default Work Orders board — where system-created cards (cellar
 * activity rounds, protocol tasks) land. Mirrors the prototype's Board.isDefault.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Safe to repeat: a deploy that failed while recording this migration had already added the column.
        if (Schema::hasColumn('work_order_boards', 'is_default')) {
            return;
        }

        Schema::table('work_order_boards', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('sort_order');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('work_order_boards', 'is_default')) {
            return;
        }

        Schema::table('work_order_boards', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
