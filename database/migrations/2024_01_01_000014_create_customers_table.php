<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('company_name');
            $table->string('contact_name')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true); // soft-delete-when-referenced flag
            $table->decimal('rebate_percent', 5, 2)->default(0); // customer-level discount override
            $table->boolean('exclude_from_stats')->default(false);
            $table->boolean('hide_prices')->default(false);
            $table->string('order_token')->nullable()->unique(); // high-entropy self-service secret
            $table->foreignUlid('pricing_tier_id')->nullable()->constrained('pricing_tiers')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
