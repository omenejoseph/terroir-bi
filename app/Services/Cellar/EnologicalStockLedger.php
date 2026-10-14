<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Enums\EnologicalStockSource;
use App\Models\EnologicalProduct;
use App\Models\EnologicalStockMovement;
use App\Services\Audit\AuditLogger;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single sink for enological stock: every change locks the product,
 * refuses to go below zero (the one stock rule), writes a movement with its
 * cause and an `enological_product.stock_adjusted` audit entry.
 */
class EnologicalStockLedger
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function move(
        EnologicalProduct|string $product,
        string $delta,
        EnologicalStockSource $source,
        ?Model $reference = null,
        ?string $note = null,
        string $errorField = 'quantity',
    ): EnologicalProduct {
        $delta = Quantity::normalize($delta);

        return DB::transaction(function () use ($product, $delta, $source, $reference, $note, $errorField): EnologicalProduct {
            /** @var EnologicalProduct $locked */
            $locked = EnologicalProduct::query()
                ->whereKey($product instanceof EnologicalProduct ? $product->getKey() : $product)
                ->lockForUpdate()
                ->firstOrFail();

            $before = Quantity::normalize((string) $locked->current_stock);
            $after = Quantity::add($before, $delta);
            if (Quantity::isNegative($after)) {
                throw ValidationException::withMessages([
                    $errorField => "Not enough {$locked->name} in stock — {$before} {$locked->unit} available, ".Quantity::negate($delta).' needed.',
                ]);
            }

            $locked->current_stock = $after;
            $locked->save();

            EnologicalStockMovement::create([
                'enological_product_id' => $locked->getKey(),
                'delta' => $delta,
                'balance_after' => $after,
                'source' => $source,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'note' => $note,
                'created_by_id' => Auth::id(),
            ]);

            $this->audit->record(Auth::user(), 'enological_product.stock_adjusted', $locked, [
                'delta' => $delta,
                'before' => $before,
                'after' => $after,
                'source' => $source->value,
                'reference_id' => $reference?->getKey(),
                'note' => $note,
            ]);

            return $locked;
        });
    }
}
