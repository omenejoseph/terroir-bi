<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that can receive AI-generated records.
     *
     * @var list<string>
     */
    private array $tables = ['costs', 'inflows', 'orders', 'inventory_items', 'suppliers'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table): void {
                // Records created from an AI import carry the flag so the UI can
                // badge them, and `ai_metadata` keeps the provenance (import/line
                // ids, model, confidence, source document key).
                $table->boolean('is_ai_generated')->default(false)->after('id');
                $table->json('ai_metadata')->nullable()->after('is_ai_generated');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn(['is_ai_generated', 'ai_metadata']);
            });
        }
    }
};
