<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cellar;

use App\Actions\Cellar\BulkAddAdditionsAction;
use App\Actions\Cellar\BulkAddAnalysesAction;
use App\Authorization\MembershipContext;
use App\DataTransferObjects\WineLotData;
use App\Enums\WineLotStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\BulkAdditionsRequest;
use App\Http\Requests\Cellar\BulkAnalysesRequest;
use App\Models\User;
use App\Models\Vessel;
use App\Models\WineLot;
use App\Queries\CellarAnalyticsQuery;
use App\Queries\CellarBookQuery;
use App\Queries\CellarCostsQuery;
use App\Queries\CellarMapQuery;
use App\Queries\CellarPickerOptions;
use App\Queries\ListWineLotsQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cellar home: headline figures, the cellar map (view and edit), the active
 * lots by vintage and the bottled archive.
 */
class CellarController extends Controller
{
    public function index(CellarMapQuery $map, ListWineLotsQuery $lots, CellarPickerOptions $options, MembershipContext $membership): Response
    {
        $active = WineLot::query()
            ->with(['grapes', 'latestAnalysis', 'latestAddition'])
            ->whereNotIn('status', [WineLotStatus::Bottled->value, WineLotStatus::Blended->value])
            ->orderByDesc('vintage')
            ->orderBy('name')
            ->get();

        $bottled = $lots->build(['status' => WineLotStatus::Bottled->value])
            ->withCount(['additions', 'analyses', 'bottlings'])
            ->with('latestBottling.inventoryItem')
            ->orderByDesc('vintage')
            ->orderBy('name')
            ->get();

        $manage = $membership->can('cellar.manage');

        return Inertia::render('Cellar/Index', [
            'stats' => [
                'vessels' => Vessel::query()->where('is_active', true)->count(),
                'lots' => $active->count(),
                'volume' => number_format((float) $active->sum(fn (WineLot $l): float => (float) $l->current_volume), 3, '.', ''),
            ],
            'vessels' => $map->get(),
            'lots' => array_values($active->map(fn (WineLot $l): array => WineLotData::fromModel($l)->toArray())->all()),
            'bottled' => array_values($bottled->map(fn (WineLot $l): array => WineLotData::fromModel($l)->toArray())->all()),
            'lotOptions' => fn (): array => $manage ? $options->lots() : [],
        ]);
    }

    /** Cellar › Bulk additions: one product into many lots (and vessels). */
    public function bulkAdditions(CellarPickerOptions $options): Response
    {
        return Inertia::render('Cellar/BulkAdditions', [
            'lots' => $options->lots(),
            'products' => $options->products(),
        ]);
    }

    public function storeBulkAdditions(BulkAdditionsRequest $request, BulkAddAdditionsAction $action): RedirectResponse
    {
        $created = $action->execute($request->validated(), $this->userId($request));

        return back()->with('success', __('Added to :n lots.', ['n' => $created]));
    }

    /** Cellar › Bulk analyses: a grid of lots/vessels × parameters, typed or pasted. */
    public function bulkAnalyses(CellarPickerOptions $options): Response
    {
        return Inertia::render('Cellar/BulkAnalyses', [
            'lots' => $options->lots(),
        ]);
    }

    public function storeBulkAnalyses(BulkAnalysesRequest $request, BulkAddAnalysesAction $action): RedirectResponse
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (array) $request->validated('analyses', []);
        $created = $action->execute($rows, $this->userId($request));

        return back()->with('success', __('Saved :n analyses.', ['n' => count($created)]));
    }

    /** Cellar › Analytics. Money (average cost per litre, cost waterfall) only with finance.view. */
    public function analytics(CellarAnalyticsQuery $analytics, CellarCostsQuery $costs, MembershipContext $membership): Response
    {
        $finance = $membership->can('finance.view');

        return Inertia::render('Cellar/Analytics', [
            'analytics' => $analytics->get(withCosts: $finance),
            'waterfall' => $finance
                ? array_values(collect($costs->get())->sortByDesc(fn (array $r): int => (int) $r['cost']['total'])->take(10)->values()->all())
                : null,
        ]);
    }

    /** Cellar › Costs (finance.view): cost of goods per lot, bottled lots included, with margin. */
    public function costs(CellarCostsQuery $costs): Response
    {
        return Inertia::render('Cellar/Costs', ['lots' => $costs->get(detailed: true)]);
    }

    /** Cellar › Cellar book: every cellar event in a date range, as a table or a CSV download. */
    public function book(Request $request, CellarBookQuery $query): Response|StreamedResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($data['from'] ?? now()->startOfYear()->toDateString())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now()->toDateString())->endOfDay();
        $rows = $query->get($from, $to);

        if ($request->routeIs('cellar.book.csv')) {
            return response()->streamDownload(function () use ($rows): void {
                $out = fopen('php://output', 'wb');
                if ($out === false) {
                    return;
                }
                fputcsv($out, ['Date', 'Lot', 'Name', 'Vintage', 'Event', 'Vessel', 'Quantity', 'Unit', 'Detail', 'By'], ',', '"', '');
                foreach ($rows as $r) {
                    fputcsv($out, array_values($r), ',', '"', '');
                }
                fclose($out);
            }, 'cellar-book-'.$from->toDateString().'-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return Inertia::render('Cellar/CellarBook', [
            'rows' => $rows,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
