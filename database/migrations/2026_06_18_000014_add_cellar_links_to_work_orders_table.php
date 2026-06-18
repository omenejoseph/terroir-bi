<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignUlid('wine_lot_id')->nullable()->after('assignee_id')->constrained('wine_lots')->nullOnDelete();
            $table->foreignUlid('vessel_id')->nullable()->after('wine_lot_id')->constrained('vessels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wine_lot_id');
            $table->dropConstrainedForeignId('vessel_id');
        });
    }
};
