<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cellar;

use App\Actions\Cellar\BulkCreateVesselsAction;
use App\Actions\Cellar\BulkDeleteVesselsAction;
use App\Actions\Cellar\CreateVesselAction;
use App\Actions\Cellar\DeleteVesselAction;
use App\Actions\Cellar\DuplicateVesselsAction;
use App\Actions\Cellar\RenameCellarRoomAction;
use App\Actions\Cellar\UpdateVesselAction;
use App\Actions\Cellar\UpdateVesselLayoutAction;
use App\DataTransferObjects\VesselData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\BulkCreateVesselsRequest;
use App\Http\Requests\Cellar\BulkDeleteVesselsRequest;
use App\Http\Requests\Cellar\DuplicateVesselsRequest;
use App\Http\Requests\Cellar\RenameRoomRequest;
use App\Http\Requests\Cellar\StoreVesselRequest;
use App\Http\Requests\Cellar\UpdateVesselLayoutRequest;
use App\Http\Requests\Cellar\UpdateVesselRequest;
use App\Models\Vessel;
use App\Queries\CellarHistoryQuery;
use App\Queries\CellarPickerOptions;
use App\Queries\VesselDetailQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia counterpart of Api\VesselController: the vessel page, and the
 * vessel writes made from the cellar map (create, bulk-add, duplicate,
 * layout, rooms) and the vessel page (edit, fault, delete).
 */
class VesselController extends Controller
{
    public function show(Vessel $vessel, VesselDetailQuery $detail, CellarHistoryQuery $history, CellarPickerOptions $options): Response
    {
        $vessel->loadMissing('vesselLots.wineLot.latestAnalysis');

        return Inertia::render('Cellar/Vessels/Show', [
            'vessel' => [...VesselData::fromModel($vessel, withLots: true)->toArray(), ...$detail->get($vessel)],
            'rooms' => $this->rooms(),
            'lots' => fn (): array => $options->lots(),
            'history' => Inertia::optional(fn (): array => $history->forVessel($vessel)),
        ]);
    }

    public function store(StoreVesselRequest $request, CreateVesselAction $action): RedirectResponse
    {
        $vessel = $action->execute($request->validated());

        return back()->with('success', __('Vessel :name created.', ['name' => $vessel->name]));
    }

    public function bulkStore(BulkCreateVesselsRequest $request, BulkCreateVesselsAction $action): RedirectResponse
    {
        $created = $action->execute($request->validated());

        return back()->with('success', __('Added :n vessels.', ['n' => $created->count()]));
    }

    public function duplicate(DuplicateVesselsRequest $request, DuplicateVesselsAction $action): RedirectResponse
    {
        $ids = array_values((array) $request->validated('vessel_ids'));
        $created = $action->execute($ids, (int) $request->validated('count'));

        return back()->with('success', __('Added :n vessels.', ['n' => $created->count()]));
    }

    /**
     * The map's autosave. Called with fetch() while dragging (204, no page
     * reload), or as an Inertia visit for one-off moves.
     */
    public function layout(UpdateVesselLayoutRequest $request, UpdateVesselLayoutAction $action): RedirectResponse|HttpResponse
    {
        /** @var list<array<string, mixed>> $updates */
        $updates = (array) $request->validated('updates', []);
        $action->execute($updates);

        return $request->wantsJson() ? response()->noContent() : back();
    }

    public function renameRoom(RenameRoomRequest $request, RenameCellarRoomAction $action): RedirectResponse
    {
        $action->execute((string) $request->validated('from'), (string) $request->validated('to'));

        return back()->with('success', __('Room renamed.'));
    }

    public function update(UpdateVesselRequest $request, Vessel $vessel, UpdateVesselAction $action): RedirectResponse
    {
        $action->execute($vessel, $request->validated());

        return back()->with('success', __('Vessel saved.'));
    }

    public function destroy(Vessel $vessel, DeleteVesselAction $action): RedirectResponse
    {
        $action->execute($vessel);

        return redirect('/cellar')->with('success', __('Vessel deleted.'));
    }

    /** The map's "Delete" on a selection: empty vessels go, vessels holding wine stay and are named. */
    public function bulkDestroy(BulkDeleteVesselsRequest $request, BulkDeleteVesselsAction $action): RedirectResponse
    {
        /** @var list<string> $ids */
        $ids = array_values((array) $request->validated('ids'));
        $result = $action->execute($ids);

        if ($result['deleted'] === 0) {
            return back()->with('error', __('Nothing deleted — :names hold wine. Empty them first.', ['names' => implode(', ', $result['kept'])]));
        }

        $message = __(':n vessel(s) deleted.', ['n' => $result['deleted']]);

        return back()->with('success', $result['kept'] === [] ? $message : $message.' '.__(':names hold wine and were kept.', ['names' => implode(', ', $result['kept'])]));
    }

    /**
     * Rooms in use, for the room suggestions on the vessel form.
     *
     * @return list<string>
     */
    private function rooms(): array
    {
        return array_values(Vessel::query()->whereNotNull('room')->distinct()->orderBy('room')->pluck('room')->map(fn ($r): string => (string) $r)->all());
    }
}
