<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Suppliers\UpdateSupplierOrderStatusAction;
use App\Actions\Suppliers\UpsertSupplierPriceItemAction;
use App\DataTransferObjects\SupplierOrderData;
use App\Enums\SupplierOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Suppliers\BulkUpsertPriceItemsRequest;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierPriceItem;
use App\Services\Suppliers\SupplierTokenResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public supplier portal (token-authenticated, no login). The portal token both
 * authenticates and selects the tenant. A supplier can view their open purchase
 * orders, acknowledge (confirm) a sent order, view and bulk-upload their price
 * list. Mirrors {@see PublicOrderController}.
 */
class PublicSupplierController extends Controller
{
    /** Open POs visible to the supplier (sent to them, not yet received/cancelled). */
    private const OPEN = [SupplierOrderStatus::Sent->value, SupplierOrderStatus::Confirmed->value];

    public function show(string $token, SupplierTokenResolver $resolver): JsonResponse
    {
        $supplier = $this->resolve($token, $resolver);

        $orders = $supplier->orders()
            ->whereIn('status', self::OPEN)
            ->with('items')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SupplierOrder $o) => SupplierOrderData::fromModel($o)->toArray())
            ->all();

        $priceItems = $supplier->priceItems()->orderBy('description')->get()
            ->map(fn (SupplierPriceItem $p) => [
                'id' => $p->getKey(),
                'description' => $p->description,
                'unit_price' => $p->unit_price->jsonSerialize(),
                'unit' => $p->unit,
            ])->all();

        return response()->json([
            'data' => [
                'supplier' => [
                    'company_name' => $supplier->company_name,
                    'contact_name' => $supplier->contact_name,
                ],
                'orders' => $orders,
                'price_items' => $priceItems,
            ],
        ]);
    }

    public function importPriceItems(string $token, BulkUpsertPriceItemsRequest $request, SupplierTokenResolver $resolver, UpsertSupplierPriceItemAction $action): JsonResponse
    {
        $supplier = $this->resolve($token, $resolver);
        $this->throttle($token);

        /** @var list<array{description: string, unit_price: int, unit?: ?string}> $items */
        $items = $request->validated('items');

        $added = 0;
        $updated = 0;
        DB::transaction(function () use ($supplier, $items, $action, &$added, &$updated): void {
            foreach ($items as $row) {
                $item = $action->execute($supplier, $row);
                $item->wasRecentlyCreated ? $added++ : $updated++;
            }
        });

        return response()->json(['data' => ['added' => $added, 'updated' => $updated, 'total' => count($items)]]);
    }

    public function confirmOrder(string $token, string $order, SupplierTokenResolver $resolver, UpdateSupplierOrderStatusAction $action): JsonResponse
    {
        $supplier = $this->resolve($token, $resolver);
        $this->throttle($token);

        $po = $supplier->orders()->whereKey($order)->first();
        abort_unless($po instanceof SupplierOrder, 404);

        abort_unless($po->status === SupplierOrderStatus::Sent, 422, 'Only a sent order can be confirmed.');

        $po = $action->execute($po, SupplierOrderStatus::Confirmed);

        return response()->json(['data' => SupplierOrderData::fromModel($po->load('items'))->toArray()]);
    }

    private function resolve(string $token, SupplierTokenResolver $resolver): Supplier
    {
        $supplier = $resolver->resolve($token);
        abort_unless($supplier instanceof Supplier, 404);

        return $supplier;
    }

    private function throttle(string $token): void
    {
        $key = 'public-supplier:'.$token;
        if (RateLimiter::tooManyAttempts($key, 60)) {
            abort(429, 'Too many requests from this link. Please try again later.');
        }
        RateLimiter::hit($key, 3600);
    }
}
