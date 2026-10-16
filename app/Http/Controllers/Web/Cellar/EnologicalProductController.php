<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cellar;

use App\Actions\Cellar\AdjustEnologicalStockAction;
use App\Actions\Cellar\BulkUpdateEnologicalProductsAction;
use App\Actions\Cellar\CreateEnologicalProductAction;
use App\Actions\Cellar\DeleteEnologicalProductAction;
use App\Actions\Cellar\DraftEnologicalPurchaseOrdersAction;
use App\Actions\Cellar\ImportEnologicalProductsAction;
use App\Actions\Cellar\UpdateEnologicalProductAction;
use App\DataTransferObjects\EnologicalProductData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\AdjustEnologicalStockRequest;
use App\Http\Requests\Cellar\BulkUpdateEnologicalProductsRequest;
use App\Http\Requests\Cellar\DraftEnologicalOrdersRequest;
use App\Http\Requests\Cellar\ImportEnologicalProductsRequest;
use App\Http\Requests\Cellar\StoreEnologicalProductRequest;
use App\Http\Requests\Cellar\UpdateEnologicalProductRequest;
use App\Models\EnologicalProduct;
use App\Models\EnologicalStockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Queries\ProtocolNeedsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cellar › Products: enological products and their stock — CRUD, stock
 * adjustments and history (the ledger), paste import, bulk edit, what the
 * protocols in use will need, and draft purchase orders for the deficits.
 */
class EnologicalProductController extends Controller
{
    public function index(ProtocolNeedsQuery $needs): Response
    {
        return Inertia::render('Cellar/Products', [
            'products' => array_values(EnologicalProduct::query()->orderBy('name')->get()
                ->map(fn (EnologicalProduct $p): array => EnologicalProductData::fromModel($p)->toArray())->all()),
            'needs' => $needs->get(),
            'suppliers' => fn (): array => array_values(Supplier::query()->orderBy('company_name')->get(['id', 'company_name'])
                ->map(fn (Supplier $s): array => ['value' => (string) $s->getKey(), 'label' => (string) $s->company_name])->all()),
        ]);
    }

    /** The stock ledger for one product, newest first (the drawer's history). */
    public function movements(EnologicalProduct $enologicalProduct): JsonResponse
    {
        $rows = EnologicalStockMovement::query()
            ->with('createdBy')
            ->where('enological_product_id', $enologicalProduct->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => array_values($rows->map(fn (EnologicalStockMovement $m): array => [
            'id' => $m->getKey(),
            'date' => $m->created_at?->toIso8601String(),
            'delta' => (string) $m->delta,
            'balance_after' => (string) $m->balance_after,
            'source' => $m->source->value,
            'note' => $m->note,
            'by' => $m->createdBy?->fullName(),
        ])->all())]);
    }

    public function store(StoreEnologicalProductRequest $request, CreateEnologicalProductAction $action): RedirectResponse
    {
        $action->execute($request->validated());

        return back()->with('success', __('Product saved.'));
    }

    public function update(UpdateEnologicalProductRequest $request, EnologicalProduct $enologicalProduct, UpdateEnologicalProductAction $action): RedirectResponse
    {
        $action->execute($enologicalProduct, $request->validated());

        return back()->with('success', __('Product saved.'));
    }

    public function adjustStock(AdjustEnologicalStockRequest $request, EnologicalProduct $enologicalProduct, AdjustEnologicalStockAction $action): RedirectResponse
    {
        $note = $request->validated('note');
        $action->execute($enologicalProduct, (string) $request->validated('delta'), $note !== null ? (string) $note : null);

        return back()->with('success', __('Stock adjusted.'));
    }

    public function destroy(EnologicalProduct $enologicalProduct, DeleteEnologicalProductAction $action): RedirectResponse
    {
        $action->execute($enologicalProduct);

        return back()->with('success', __('Product removed.'));
    }

    /** Paste import: a dry run answers with the parsed rows as JSON; the real run redirects. */
    public function import(ImportEnologicalProductsRequest $request, ImportEnologicalProductsAction $action): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $dryRun = (bool) ($data['dry_run'] ?? false);
        $result = $action->execute((string) $data['text'], $dryRun);

        if ($dryRun) {
            return response()->json(['data' => $result], $result['errors'] === [] ? 200 : 422);
        }
        if ($result['errors'] !== []) {
            return back()->withErrors(['text' => $result['errors'][0]]);
        }

        return back()->with('success', __('Imported: :c new, :u updated.', ['c' => $result['created'], 'u' => $result['updated']]));
    }

    public function bulkUpdate(BulkUpdateEnologicalProductsRequest $request, BulkUpdateEnologicalProductsAction $action): RedirectResponse
    {
        $data = $request->validated();
        /** @var list<string> $ids */
        $ids = array_values($data['ids']);
        $count = $action->execute($ids, (array) $data['fields']);

        return back()->with('success', __('Updated :n products.', ['n' => $count]));
    }

    public function draftOrders(DraftEnologicalOrdersRequest $request, DraftEnologicalPurchaseOrdersAction $action): RedirectResponse
    {
        $data = $request->validated();
        /** @var list<array{enological_product_id: string, quantity: float|int|string}>|null $lines */
        $lines = isset($data['lines']) ? array_values($data['lines']) : null;
        $orders = $action->execute($lines, $this->userId($request));

        return back()->with('success', __('Drafted :n purchase orders.', ['n' => count($orders)]));
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
