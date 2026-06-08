<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's membership of a tenant, with the roles they hold there. A user may
 * have many memberships (one per tenant) and switch the active one.
 *
 * NOTE: this table is intentionally NOT row-scoped by BelongsToTenant — it is
 * queried both per-tenant (list members) and per-user (list my tenants).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->json('roles'); // set of App\Enums\TenantRole values
            $table->string('status')->default('active'); // App\Enums\MembershipStatus
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
