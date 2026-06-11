<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Suppliers\CreateSupplierAction;
use App\Actions\Suppliers\SupplierPortalTokenAction;
use App\Actions\Suppliers\UpdateSupplierAction;
use App\Actions\Suppliers\UpsertSupplierPriceItemAction;
use App\DataTransferObjects\SupplierData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Suppliers\MergeSuppliersRequest;
use App\Http\Requests\Suppliers\StorePriceItemRequest;
use App\Http\Requests\Suppliers\StoreSupplierRequest;
use App\Http\Requests\Suppliers\UpdatePriceItemRequest;
use App\Http\Requests\Suppliers\UpdateSupplierRequest;
use App\Models\Cost;
use App\Models\Supplier;
use App\Models\SupplierPriceChange;
use App\Models\SupplierPriceItem;
use App\Queries\ListSuppliersQuery;
use App\Services\Suppliers\SupplierMergeService;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    /** The supplier's current public portal token (admins with suppliers.manage). */
    public function showToken(Supplier $supplier): JsonResponse
    {
        return response()->json(['data' => ['portal_token' => $supplier->portal_token]]);
    }

    public function generateToken(Supplier $supplier, SupplierPortalTokenAction $action): JsonResponse
    {
        $result = $action->generate($supplier);

        return response()->json([
            'data' => $result['supplier']->toArray() + ['portal_token' => $result['token']],
        ]);
    }

    public function revokeToken(Supplier $supplier, SupplierPortalTokenAction $action): JsonResponse
    {
        return response()->json(['data' => $action->revoke($supplier)->toArray()]);
    }

    public function index(Request $request, ListSuppliersQuery $query): JsonResponse
    {
        $paginator = $query->paginate([
            'search' => $request->query('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
        ]);

        return response()->json([
            'data' => array_map(fn (Supplier $s) => SupplierData::fromModel($s)->toArray(), $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $supplier->loadMissing('priceItems')->loadCount(['priceItems', 'priceChanges']);

        return response()->json(['data' => SupplierData::fromModel($supplier)->toArray()]);
    }

    /** Summary cards: price items always; cost totals only with finance visibility. */
    public function stats(Request $request, Supplier $supplier, TenantContext $tenant): JsonResponse
    {
        $data = ['price_items' => $supplier->priceItems()->count()];

        if ($request->user()?->can('finance.view')) {
            $costs = Cost::query()->where('supplier_id', $supplier->getKey())->get(['total_amount']);
            $currency = $tenant->current()?->settings()->first()?->default_currency;
            $currency ??= CurrencyRegistry::default()->code;
            $total = (int) $costs->sum(fn (Cost $c) => $c->total_amount->getMinorAmount());

            $data['cost_entries'] = $costs->count();
            $data['total_costs'] = Money::fromMinor($total, $currency)->jsonSerialize();
        }

        return response()->json(['data' => $data]);
    }

    /** Audit log of cost changes for the supplier's price-list lines (newest first). */
    public function priceChanges(Supplier $supplier): JsonResponse
    {
        $changes = $supplier->priceChanges()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (SupplierPriceChange $c) => [
                'id' => $c->getKey(),
                'description' => $c->description,
                'unit' => $c->unit,
                'old_price' => $c->old_price?->jsonSerialize(),
                'new_price' => $c->new_price->jsonSerialize(),
                'created_at' => $c->created_at?->toIso8601String(),
            ])->all();

        return response()->json(['data' => $changes]);
    }

    public function mergePreview(MergeSuppliersRequest $request, SupplierMergeService $service): JsonResponse
    {
        $winner = Supplier::query()->whereKey((string) $request->validated('winner_id'))->firstOrFail();
        /** @var list<string> $losers */
        $losers = array_values((array) $request->validated('loser_ids'));

        return response()->json(['data' => $service->preview($winner, $losers)]);
    }

    public function merge(MergeSuppliersRequest $request, SupplierMergeService $service): JsonResponse
    {
        $winner = Supplier::query()->whereKey((string) $request->validated('winner_id'))->firstOrFail();
        /** @var list<string> $losers */
        $losers = array_values((array) $request->validated('loser_ids'));

        return response()->json(['data' => $service->merge($winner, $losers)]);
    }

    public function store(StoreSupplierRequest $request, CreateSupplierAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($request->validated())->toArray()], 201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier, UpdateSupplierAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($supplier, $request->validated())->toArray()]);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->delete();

        return response()->json(status: 204);
    }

    public function addPriceItem(StorePriceItemRequest $request, Supplier $supplier, UpsertSupplierPriceItemAction $action): JsonResponse
    {
        /** @var array{description: string, unit_price: int, unit?: ?string, notes?: ?string, inventory_item_id?: ?string} $attributes */
        $attributes = $request->validated();
        $item = $action->execute($supplier, $attributes);

        return response()->json(['data' => $this->priceItem($item)], 201);
    }

    public function updatePriceItem(UpdatePriceItemRequest $request, Supplier $supplier, SupplierPriceItem $priceItem): JsonResponse
    {
        abort_unless($priceItem->supplier_id === $supplier->getKey(), 404);
        $priceItem->fill($request->validated());
        $priceItem->last_updated = now();
        $priceItem->save();

        return response()->json(['data' => $this->priceItem($priceItem)]);
    }

    public function deletePriceItem(Supplier $supplier, SupplierPriceItem $priceItem): JsonResponse
    {
        abort_unless($priceItem->supplier_id === $supplier->getKey(), 404);
        $priceItem->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function priceItem(SupplierPriceItem $item): array
    {
        return [
            'id' => $item->getKey(),
            'inventory_item_id' => $item->inventory_item_id,
            'description' => $item->description,
            'unit_price' => $item->unit_price->jsonSerialize(),
            'unit' => $item->unit,
            'notes' => $item->notes,
            'last_updated' => $item->last_updated?->toIso8601String(),
        ];
    }
}
