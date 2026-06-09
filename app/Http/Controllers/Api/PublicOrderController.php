<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Orders\CreateOrderAction;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Queries\PublicCatalogQuery;
use App\Services\Orders\PublicTokenResolver;
use App\Services\Pricing\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Self-service ordering via a customer's order token (flow 02). The token both
 * authenticates and selects the tenant; prices are never trusted from the
 * client; orders are attributed to a tenant system user.
 */
class PublicOrderController extends Controller
{
    public function catalog(string $token, PublicTokenResolver $resolver, PublicCatalogQuery $catalog, PricingService $pricing): JsonResponse
    {
        $customer = $resolver->resolve($token);
        abort_unless($customer instanceof Customer, 404);

        $items = $catalog->forCustomer($customer);
        $prices = $pricing->resolveForCustomer($customer, $items);

        $products = $items->map(function (InventoryItem $item) use ($customer, $prices) {
            $row = [
                'id' => $item->getKey(),
                'name' => $item->name,
                'sku' => $item->sku,
                'vintage' => $item->vintage,
                'unit' => $item->unit,
                'bottles_per_case' => $item->bottles_per_case,
            ];

            if (! $customer->hide_prices) {
                $row['price'] = $prices[$item->getKey()]->jsonSerialize();
            }

            return $row;
        })->all();

        return response()->json([
            'data' => [
                'customer' => [
                    'company_name' => $customer->company_name,
                    'hide_prices' => $customer->hide_prices,
                    'allow_single_bottle' => $customer->allow_single_bottle,
                ],
                'products' => $products,
            ],
        ]);
    }

    public function store(string $token, Request $request, PublicTokenResolver $resolver, PricingService $pricing, CreateOrderAction $action): JsonResponse
    {
        $customer = $resolver->resolve($token);
        abort_unless($customer instanceof Customer, 404);

        $key = 'public-order:'.$token;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            abort(429, 'Too many orders from this link. Please try again later.');
        }
        RateLimiter::hit($key, 3600);

        $validated = $this->validatePayload($request, $customer);
        $defaultUnit = $customer->allow_single_bottle ? 'bottles' : 'cases';

        // Server is the source of truth on price: reject any client mismatch and
        // strip client prices so the order is created at resolved values.
        $items = [];
        foreach ($validated['items'] as $line) {
            $item = InventoryItem::query()->whereKey((string) $line['inventory_item_id'])->firstOrFail();
            $unitType = $line['unit_type'] ?? $defaultUnit;
            $resolved = $this->resolvedUnitPrice($pricing, $customer, $item, $unitType);

            if (isset($line['unit_price']) && (int) $line['unit_price'] !== $resolved) {
                throw ValidationException::withMessages([
                    'items' => 'Submitted price does not match the current catalog price.',
                ]);
            }

            $items[] = [
                'inventory_item_id' => $item->getKey(),
                'quantity' => (int) $line['quantity'],
                'unit_type' => $unitType,
            ];
        }

        $systemUserId = $resolver->systemUserId($customer);
        abort_if($systemUserId === null, 500, 'No tenant user to attribute the order to.');

        $order = $action->execute($customer, $systemUserId, [
            'items' => $items,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(['data' => ['order_number' => $order->order_number]], 201);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, notes?: ?string}
     */
    private function validatePayload(Request $request, Customer $customer): array
    {
        $units = $customer->allow_single_bottle ? ['bottles', 'cases'] : ['cases'];

        /** @var array{items: array<int, array<string, mixed>>, notes?: ?string} $validated */
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_item_id' => [
                'required', 'string',
                Rule::exists('inventory_items', 'id')->where('tenant_id', $customer->tenant_id),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99999'],
            'items.*.unit_type' => ['sometimes', Rule::in($units)],
            'items.*.unit_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        return $validated;
    }

    private function resolvedUnitPrice(PricingService $pricing, Customer $customer, InventoryItem $item, string $unitType): int
    {
        $base = $pricing->resolve($customer, $item)->getMinorAmount();

        return $unitType === 'cases' ? $base * max(1, (int) $item->bottles_per_case) : $base;
    }
}
