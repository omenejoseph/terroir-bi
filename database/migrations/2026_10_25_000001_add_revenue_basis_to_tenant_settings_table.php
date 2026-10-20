<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tenant_settings', 'revenue_basis')) {
            return;
        }

        Schema::table('tenant_settings', function (Blueprint $table) {
            // When revenue is recognised on the dashboard: 'shipped' (invoiced —
            // shipped orders, dated by when they shipped) or 'created' (every
            // order, dated by when it was placed).
            $table->string('revenue_basis', 16)->default('shipped')->after('cash_on_hand_as_of');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn('revenue_basis');
        });
    }
};
