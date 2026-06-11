<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Modules included in the plan (App\Enums\Module values). A tenant
            // sees only these.
            $table->json('modules')->nullable()->after('currency');
            // Stripe recurring price the plan bills on; null = free/internal plan.
            $table->string('stripe_price_id')->nullable()->after('modules');
            // Free-trial length and the two grace windows (in days) after a
            // subscription lapses: full-access grace, then read-only grace.
            $table->unsignedInteger('trial_days')->default(0)->after('stripe_price_id');
            $table->unsignedInteger('grace_full_days')->default(0)->after('trial_days');
            $table->unsignedInteger('grace_readonly_days')->default(0)->after('grace_full_days');
            $table->string('interval')->default('month')->after('grace_readonly_days');
            $table->boolean('is_active')->default(true)->after('interval');
            $table->boolean('is_public')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'modules', 'stripe_price_id', 'trial_days', 'grace_full_days',
                'grace_readonly_days', 'interval', 'is_active', 'is_public',
            ]);
        });
    }
};
