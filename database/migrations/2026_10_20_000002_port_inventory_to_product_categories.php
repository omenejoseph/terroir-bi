<?php

declare(strict_types=1);

use App\Services\Inventory\ProductCategoryBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Ports what already exists to the category + vintage-chain model: one category row per distinct
 * group/subcategory (in the order the organisation arranged them), the flag set where items carry
 * vintages, every item linked, and each wine's vintages chained oldest → newest.
 *
 * The logic lives in ProductCategoryBackfill so it can be tested and re-run (it is idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ProductCategoryBackfill)->run();
    }

    public function down(): void
    {
        // Nothing to undo: the schema migration drops the columns and the table.
    }
};
