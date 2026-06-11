<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inflow_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('inflow_id')->constrained('inflows')->cascadeOnDelete();
            $table->json('changes'); // [{ field, old, new }] — raw values, formatted client-side
            $table->foreignUlid('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['inflow_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inflow_changes');
    }
};
