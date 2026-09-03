<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which board a task is organized under — independent of `category` (what
 * kind of work it is). Nullable: a task with no board still shows under
 * "All work"; deleting a board un-assigns its tasks rather than deleting them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignUlid('board_id')->nullable()->after('category')
                ->constrained('work_order_boards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('board_id');
        });
    }
};
