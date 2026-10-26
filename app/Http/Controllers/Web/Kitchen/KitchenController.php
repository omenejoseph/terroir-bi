<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Kitchen;

use App\Actions\Kitchen\AdjustKitchenStockAction;
use App\Actions\Kitchen\ChangeKitchenWorkOrderStatusAction;
use App\Actions\Kitchen\CompleteKitchenCheckAction;
use App\Actions\Kitchen\RecordKitchenCountAction;
use App\Actions\Kitchen\SaveKitchenItemAction;
use App\Actions\Kitchen\SaveKitchenWorkOrderAction;
use App\Actions\Kitchen\StartKitchenCheckAction;
use App\Enums\KitchenCheckStatus;
use App\Enums\KitchenLocation;
use App\Enums\KitchenMovementType;
use App\Enums\KitchenWorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\AdjustKitchenStockRequest;
use App\Http\Requests\Kitchen\ChangeKitchenWorkOrderStatusRequest;
use App\Http\Requests\Kitchen\KitchenItemRequest;
use App\Http\Requests\Kitchen\KitchenWorkOrderRequest;
use App\Http\Requests\Kitchen\RecordKitchenCountRequest;
use App\Http\Requests\Kitchen\StartKitchenCheckRequest;
use App\Models\KitchenCheck;
use App\Models\KitchenCheckItem;
use App\Models\KitchenItem;
use App\Models\KitchenMovement;
use App\Models\KitchenWorkOrder;
use App\Models\User;
use App\Services\Kitchen\KitchenPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kitchen (the old app's /dashboard/kitchen, docs/21 §3): stock with its adjustments, the stock-take
 * ("Inventura") and prep tickets. ADMIN and KITCHEN. Stock only moves through KitchenStockLedger.
 */
class KitchenController extends Controller
{
    /** @var list<string> */
    private const TABS = ['inventory', 'inventura', 'work-orders'];

    public function index(Request $request, KitchenPresenter $presenter): Response
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'inventory';
        $woFilters = [
            'status' => $this->str($request->query('wo_status')),
            'assigned_to' => $this->str($request->query('wo_assignee')),
            'from' => $this->date($request->query('wo_from')),
            'to' => $this->date($request->query('wo_to')),
        ];

        return Inertia::render('Kitchen/Index', [
            'tab' => $tab,
            'options' => KitchenPresenter::options(),
            // Category, then the order items were set up in, then name — how the stock sheet reads.
            'items' => fn (): array => array_values(KitchenItem::query()
                ->orderBy('category')->orderBy('sort_order')->orderBy('name')
                ->get()
                ->map(fn (KitchenItem $item): array => $presenter->item($item))
                ->all()),
            // One item's history, only while its drawer is open (?item=…).
            'itemMovements' => Inertia::optional(fn (): array => array_values(KitchenMovement::query()
                ->where('item_id', (string) $request->query('item'))
                ->with('user')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (KitchenMovement $m): array => $presenter->movement($m))
                ->all())),
            'checks' => fn (): array => array_values(KitchenCheck::query()
                ->with('user')
                ->withCount(['items', 'items as counted_count' => fn ($q) => $q->whereNotNull('physical_count')])
                ->orderByDesc('date')->orderByDesc('created_at')
                ->limit(30)
                ->get()
                ->map(fn (KitchenCheck $check): array => $presenter->checkSummary($check))
                ->all()),
            // The stock-take being counted or looked at (?check=…).
            'check' => Inertia::optional(function () use ($request, $presenter): ?array {
                $check = KitchenCheck::query()->with('user')->find((string) $request->query('check'));

                return $check instanceof KitchenCheck ? $presenter->checkDetail($check) : null;
            }),
            'workOrderFilters' => $woFilters,
            'workOrders' => fn (): array => array_values(KitchenWorkOrder::query()
                ->with(['items.item', 'user'])
                ->when($woFilters['status'] !== null, fn ($q) => $q->where('status', $woFilters['status']))
                ->when($woFilters['assigned_to'] !== null, fn ($q) => $q->whereLike('assigned_to', '%'.$woFilters['assigned_to'].'%'))
                ->when($woFilters['from'] !== null, fn ($q) => $q->where('date', '>=', $woFilters['from']))
                ->when($woFilters['to'] !== null, fn ($q) => $q->where('date', '<=', $woFilters['to']))
                ->orderByDesc('date')->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn (KitchenWorkOrder $order): array => $presenter->workOrder($order))
                ->all()),
        ]);
    }

    public function storeItem(KitchenItemRequest $request, SaveKitchenItemAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $this->userId($request));

        return back()->with('success', __('Item added.'));
    }

    public function updateItem(KitchenItemRequest $request, KitchenItem $item, SaveKitchenItemAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $this->userId($request), $item);

        return back()->with('success', __('Item updated.'));
    }

    /** Removes the item and its movements, as the old app did. */
    public function destroyItem(KitchenItem $item): RedirectResponse
    {
        $item->delete();

        return back()->with('success', __('Item deleted.'));
    }

    public function adjust(AdjustKitchenStockRequest $request, KitchenItem $item, AdjustKitchenStockAction $action): RedirectResponse
    {
        $note = $request->validated('note');
        $action->execute(
            $item,
            KitchenMovementType::from((string) $request->validated('type')),
            (string) $request->validated('quantity'),
            is_string($note) && trim($note) !== '' ? trim($note) : null,
            $this->userId($request),
        );

        return back()->with('success', __('Stock updated.'));
    }

    public function startCheck(StartKitchenCheckRequest $request, StartKitchenCheckAction $action): RedirectResponse
    {
        $location = $request->validated('location');
        $note = $request->validated('note');
        $check = $action->execute(
            (string) $request->validated('date'),
            is_string($location) ? KitchenLocation::from($location) : null,
            is_string($note) && trim($note) !== '' ? trim($note) : null,
            $this->userId($request),
        );

        return redirect('/kitchen?tab=inventura&check='.$check->getKey())->with('success', __('Stock-take started.'));
    }

    public function recordCount(RecordKitchenCountRequest $request, KitchenCheck $check, KitchenCheckItem $line, RecordKitchenCountAction $action): RedirectResponse
    {
        abort_unless($line->check_id === $check->getKey(), 404);
        $count = $request->validated('physical_count');
        $action->execute($line, $count === null ? null : (string) $count);

        return back();
    }

    public function completeCheck(Request $request, KitchenCheck $check, CompleteKitchenCheckAction $action): RedirectResponse
    {
        $action->execute($check, $this->userId($request));

        return back()->with('success', __('Stock-take completed and stock updated.'));
    }

    /** Only a stock-take still being counted can be thrown away; a completed one is history. */
    public function destroyCheck(KitchenCheck $check): RedirectResponse
    {
        if ($check->status !== KitchenCheckStatus::InProgress) {
            return back()->with('error', __('A completed stock-take cannot be deleted.'));
        }

        $check->delete();

        return redirect('/kitchen?tab=inventura')->with('success', __('Stock-take deleted.'));
    }

    public function storeWorkOrder(KitchenWorkOrderRequest $request, SaveKitchenWorkOrderAction $action): RedirectResponse
    {
        [$data, $lines] = $this->workOrderInput($request);
        $action->execute($data, $lines, $this->userId($request));

        return back()->with('success', __('Prep ticket created.'));
    }

    public function updateWorkOrder(KitchenWorkOrderRequest $request, KitchenWorkOrder $workOrder, SaveKitchenWorkOrderAction $action): RedirectResponse
    {
        [$data, $lines] = $this->workOrderInput($request);
        $action->execute($data, $lines, $this->userId($request), $workOrder);

        return back()->with('success', __('Prep ticket updated.'));
    }

    public function workOrderStatus(ChangeKitchenWorkOrderStatusRequest $request, KitchenWorkOrder $workOrder, ChangeKitchenWorkOrderStatusAction $action): RedirectResponse
    {
        $action->execute($workOrder, KitchenWorkOrderStatus::from((string) $request->validated('status')));

        return back();
    }

    public function destroyWorkOrder(KitchenWorkOrder $workOrder): RedirectResponse
    {
        $workOrder->delete();

        return back()->with('success', __('Prep ticket deleted.'));
    }

    /**
     * @return array{0: array{title: string, date: string, assigned_to: ?string, notes: ?string}, 1: list<array{item_id?: ?string, custom_name?: ?string, quantity: string|int|float, unit?: ?string, notes?: ?string}>}
     */
    private function workOrderInput(KitchenWorkOrderRequest $request): array
    {
        /** @var array{title: string, date: string, assigned_to?: ?string, notes?: ?string, items: list<array{item_id?: ?string, custom_name?: ?string, quantity: string|int|float, unit?: ?string, notes?: ?string}>} $valid */
        $valid = $request->validated();

        return [
            [
                'title' => trim($valid['title']),
                'date' => $valid['date'],
                'assigned_to' => $this->str($valid['assigned_to'] ?? null),
                'notes' => $this->str($valid['notes'] ?? null),
            ],
            $valid['items'],
        ];
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
