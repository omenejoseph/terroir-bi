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
use App\Queries\CellarMapQuery;
use App\Queries\CellarPickerOptions;
use App\Queries\ListWineLotsQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
