<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('is_cooperant')->default(false)->after('id');
        });

        Schema::table('wine_lot_grapes', function (Blueprint $table) {
            $table->foreign('harvest_entry_id')->references('id')->on('harvest_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wine_lot_grapes', function (Blueprint $table) {
            $table->dropForeign(['harvest_entry_id']);
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('is_cooperant');
        });
    }
};
