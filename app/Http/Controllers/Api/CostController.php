<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Costs\CreateCostAction;
use App\Actions\Costs\UpdateCostAction;
use App\Actions\Costs\UpdateCostStatusAction;
use App\DataTransferObjects\CostData;
use App\Enums\CostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Costs\AddCostAttachmentRequest;
use App\Http\Requests\Costs\StoreCostRequest;
use App\Http\Requests\Costs\UpdateCostRequest;
use App\Http\Requests\Costs\UpdateCostStatusRequest;
use App\Models\Cost;
use App\Models\CostAttachment;
use App\Models\User;
use App\Queries\CostAnalyticsQuery;
use App\Queries\ListCostsQuery;
use App\Services\Uploads\PresignedUploadService;
use App\Support\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CostController extends Controller
{
    public function index(Request $request, ListCostsQuery $query): JsonResponse
    {
        $paginator = $query->paginate($this->filters($request));

        return response()->json([
            'data' => array_map(fn (Cost $c) => CostData::fromModel($c)->toArray(), $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** Tab counts (All / Invoices / Payments / Others) for the current filter context. */
    public function groupCounts(Request $request, ListCostsQuery $query): JsonResponse
    {
        // Counts ignore the tab group itself but respect the other filters.
        $base = $this->filters($request);
        unset($base['group']);

        $count = fn (?string $group): int => $query->build([...$base, ...($group ? ['group' => $group] : [])])->count();

        return response()->json(['data' => [
            'all' => $count(null),
            'invoices' => $count('invoices'),
            'payments' => $count('payments'),
            'others' => $count('others'),
        ]]);
    }

    public function categories(): JsonResponse
    {
        $existing = Cost::query()->distinct()->orderBy('category')->pluck('category')->all();
        // Invoice + Payment are always offered so the tabs can always be populated.
        $categories = array_values(array_unique([
            ListCostsQuery::INVOICE_CATEGORY,
            ListCostsQuery::PAYMENT_CATEGORY,
            ...$existing,
        ]));

        return response()->json(['data' => $categories]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'category' => $request->query('category'),
            'status' => $request->query('status'),
            'supplier_id' => $request->query('supplier_id'),
            'group' => $request->query('group'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];
    }

    public function analytics(Request $request, CostAnalyticsQuery $query): JsonResponse
    {
        [$from, $to] = Period::resolve(
            $request->query('period') !== null ? (string) $request->query('period') : null,
            $request->query('from') !== null ? (string) $request->query('from') : null,
            $request->query('to') !== null ? (string) $request->query('to') : null,
        );

        return response()->json(['data' => $query->get($from, $to)]);
    }

    public function show(Cost $cost): JsonResponse
    {
        $cost->loadMissing(['supplier', 'items', 'attachments']);

        return response()->json(['data' => CostData::fromModel($cost)->toArray()]);
    }

    public function store(StoreCostRequest $request, CreateCostAction $action): JsonResponse
    {
        $data = $request->validated();
        /** @var list<array<string, mixed>> $items */
        $items = $data['items'] ?? [];
        unset($data['items']);

        $cost = $action->execute($data, $items, $this->userId($request));

        return response()->json(['data' => CostData::fromModel($cost->load(['supplier', 'items']))->toArray()], 201);
    }

    public function update(UpdateCostRequest $request, Cost $cost, UpdateCostAction $action): JsonResponse
    {
        $cost = $action->execute($cost, $request->validated());

        return response()->json(['data' => CostData::fromModel($cost->load('supplier'))->toArray()]);
    }

    public function updateStatus(UpdateCostStatusRequest $request, Cost $cost, UpdateCostStatusAction $action): JsonResponse
    {
        $cost = $action->execute($cost, CostStatus::from((string) $request->validated('status')));

        return response()->json(['data' => CostData::fromModel($cost)->toArray()]);
    }

    public function destroy(Cost $cost): JsonResponse
    {
        $cost->delete();

        return response()->json(status: 204);
    }

    public function addAttachment(AddCostAttachmentRequest $request, Cost $cost, PresignedUploadService $uploads): JsonResponse
    {
        $key = (string) $request->validated('key');
        $size = $uploads->verifyOwnedObject('cost_attachment', $key);

        $attachment = $cost->attachments()->create([
            'object_key' => $key,
            'filename' => (string) $request->validated('filename'),
            'content_type' => (string) $request->validated('content_type'),
            'size_bytes' => $size,
        ]);

        return response()->json(['data' => [
            'id' => $attachment->getKey(),
            'filename' => $attachment->filename,
            'url' => $uploads->readUrl($key),
        ]], 201);
    }

    public function deleteAttachment(Cost $cost, CostAttachment $attachment, PresignedUploadService $uploads): JsonResponse
    {
        abort_unless($attachment->cost_id === $cost->getKey(), 404);

        $uploads->delete($attachment->object_key);
        $attachment->delete();

        return response()->json(status: 204);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
