<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cellar_tasting_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('wine_lot_id')->constrained('wine_lots')->cascadeOnDelete();
            $table->foreignUlid('vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->foreignUlid('tasting_report_id')->nullable()->constrained('tasting_reports')->nullOnDelete();
            $table->foreignUlid('created_by_id')->constrained('users');
            $table->timestamp('date')->useCurrent();
            $table->string('appearance')->nullable();
            $table->string('nose')->nullable();
            $table->string('palate')->nullable();
            $table->string('overall')->nullable();
            $table->integer('score')->nullable(); // 0–100
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'wine_lot_id', 'date']);
            $table->index(['tenant_id', 'tasting_report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cellar_tasting_notes');
    }
};
