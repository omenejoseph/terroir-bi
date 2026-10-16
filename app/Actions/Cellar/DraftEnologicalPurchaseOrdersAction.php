<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Actions\Suppliers\CreateSupplierOrderAction;
use App\Models\EnologicalProduct;
use App\Models\Supplier;
use App\Queries\ProtocolNeedsQuery;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Generate purchase order" from protocol needs: one draft supplier order per
 * supplier, a line per product at its cost per unit. Lines can be given
 * explicitly; otherwise every product with a deficit and a supplier is ordered.
 */
class DraftEnologicalPurchaseOrdersAction
{
    public function __construct(
        private readonly CreateSupplierOrderAction $createOrder,
        private readonly ProtocolNeedsQuery $needs,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{enological_product_id: string, quantity: float|int|string}>|null  $lines
     * @return list<array{id: string, order_number: string, supplier: string, lines: int}>
     */
    public function execute(?array $lines, string $userId): array
    {
        $lines ??= array_values(array_map(
            fn (array $n): array => ['enological_product_id' => (string) $n['enological_product_id'], 'quantity' => $n['deficit']],
            array_filter($this->needs->get(), fn (array $n): bool => $n['deficit'] > 0 && $n['enological_product_id'] !== null),
        ));

        $products = EnologicalProduct::query()->whereIn('id', array_column($lines, 'enological_product_id'))->get()->keyBy('id');
        $bySupplier = [];
        foreach ($lines as $line) {
            $product = $products[$line['enological_product_id']] ?? null;
            if ($product !== null && $product->supplier_id !== null && (float) $line['quantity'] > 0) {
                $bySupplier[$product->supplier_id][] = ['product' => $product, 'quantity' => (string) $line['quantity']];
            }
        }

        if ($bySupplier === []) {
            throw ValidationException::withMessages(['lines' => 'Nothing to order — no product with a shortfall has a supplier.']);
        }

        return DB::transaction(function () use ($bySupplier, $userId): array {
            $created = [];
            foreach ($bySupplier as $supplierId => $items) {
                $supplier = Supplier::query()->whereKey($supplierId)->firstOrFail();
                $order = $this->createOrder->execute($supplier, ['notes' => 'Drafted from protocol needs'], array_map(fn (array $i): array => [
                    'description' => $i['product']->name,
                    'quantity' => $i['quantity'],
                    'unit' => $i['product']->unit,
                    // Supplier order lines take whole cents; the draft is reviewed before it's sent.
                    'unit_price' => $i['product']->cost_per_unit?->roundedMinor() ?? 0,
                ], $items), $userId);

                $created[] = ['id' => (string) $order->getKey(), 'order_number' => $order->order_number, 'supplier' => $supplier->company_name, 'lines' => count($items)];
            }

            $this->audit->record(Auth::user(), 'enological_product.orders_drafted', null, ['orders' => $created]);

            return $created;
        });
    }
}
