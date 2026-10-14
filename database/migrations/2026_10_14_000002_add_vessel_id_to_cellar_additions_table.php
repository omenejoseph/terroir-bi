<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vessel an addition went into. The prototype only wrote it into the note
 * ("→ F11"); a column lets per-vessel views and SO₂ projections read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cellar_additions', function (Blueprint $table) {
            $table->foreignUlid('vessel_id')->nullable()->after('wine_lot_id')->constrained('vessels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cellar_additions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vessel_id');
        });
    }
};
