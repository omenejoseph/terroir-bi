<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            // Dashboard "Revenue vs. target" (Figma 208:5577 / 286:781) and
            // "Target by channel" — minor units, whole-year figures a tenant
            // admin enters via Settings. Null means no target is set yet.
            $table->bigInteger('annual_revenue_target')->nullable()->after('storage_prefix');
            // Map of channel key (wholesale/retail/agency/shipshop) to its own
            // annual target, minor units. "other" is a catch-all, not a real
            // channel, so it is never a target key.
            $table->json('channel_revenue_targets')->nullable()->after('annual_revenue_target');
            // Dashboard "Runway" (Figma 208:5808) — months of cash left is
            // cash_on_hand / burn rate; burn rate is real (computed from Cost
            // and Inflow), but this figure has to come from a human.
            $table->bigInteger('cash_on_hand')->nullable()->after('channel_revenue_targets');
            $table->date('cash_on_hand_as_of')->nullable()->after('cash_on_hand');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn(['annual_revenue_target', 'channel_revenue_targets', 'cash_on_hand', 'cash_on_hand_as_of']);
        });
    }
};
