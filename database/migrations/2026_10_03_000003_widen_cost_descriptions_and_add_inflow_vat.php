<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two gaps the legacy import exposed:
 *  - cost and cost-line descriptions were capped at 255 characters (real courier and notary
 *    lines run to ~400), and the form rules already allowed longer text than the column held;
 *  - money-received entries carried a VAT amount in the old app but had nowhere to keep it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('costs', function (Blueprint $table): void {
            $table->text('description')->nullable()->change();
        });

        Schema::table('cost_items', function (Blueprint $table): void {
            $table->text('description')->change();
        });

        Schema::table('inflows', function (Blueprint $table): void {
            // Minor units like every money column; null = not recorded (distinct from a real 0).
            $table->bigInteger('vat_amount')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('inflows', function (Blueprint $table): void {
            $table->dropColumn('vat_amount');
        });

        Schema::table('cost_items', function (Blueprint $table): void {
            $table->string('description')->change();
        });

        Schema::table('costs', function (Blueprint $table): void {
            $table->string('description')->nullable()->change();
        });
    }
};
