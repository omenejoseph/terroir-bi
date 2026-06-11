<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-to-one billing record for a tenant. Kept separate from `tenants` so
     * Stripe/billing concerns stay out of the core (stancl) tenant model and so
     * tenants that never bill carry no billing columns. `stripe_status` is left
     * as a raw string — Stripe owns that vocabulary.
     */
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->string('stripe_status')->nullable();
            $table->string('stripe_price_id')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index('stripe_customer_id');
            $table->index('stripe_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
    }
};
