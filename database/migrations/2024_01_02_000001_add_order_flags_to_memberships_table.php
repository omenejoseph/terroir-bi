<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-membership order-permission flags (source app: User.canEditOrders /
 * User.canSeeShippedOrders). In the multi-tenant model these belong on the
 * membership, not the global user. ADMIN always overrides them in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->boolean('can_edit_orders')->default(false)->after('status');
            $table->boolean('can_see_shipped_orders')->default(false)->after('can_edit_orders');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn(['can_edit_orders', 'can_see_shipped_orders']);
        });
    }
};
