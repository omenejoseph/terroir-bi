<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            // Removing a teammate (Team page's "Remove") is now recoverable
            // rather than an irreversible hard delete — see
            // App\Actions\Members\RemoveMemberAction.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
