<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            // Credential for the public supplier portal — high-entropy, stored as-is for lookup.
            $table->string('portal_token')->nullable()->unique()->after('exclude_from_stats');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn('portal_token');
        });
    }
};
