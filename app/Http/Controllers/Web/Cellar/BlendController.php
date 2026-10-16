<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cellar;

use App\Actions\Cellar\ExecuteBlendAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\ExecuteBlendRequest;
use App\Http\Requests\Cellar\ProjectBlendRequest;
use App\Models\User;
use App\Models\Vessel;
use App\Queries\CellarPickerOptions;
use App\Services\Cellar\BlendProjector;
use App\Support\Cellar\CellarPreselect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cellar › Blend: combine vessels into a new lot, or project a blend by lot
 * ratios (analysis graded by the server's BlendProjector, pH through [H⁺])
 * and execute it. Same Actions as Api\WineLotController.
 */
class BlendController extends Controller
{
    public function index(Request $request, CellarPickerOptions $options): Response
    {
        return Inertia::render('Cellar/Blend', [
            'preselect' => CellarPreselect::from($request),
            'vessels' => $options->vessels(),
            'lots' => $options->lots(),
        ]);
    }

    public function project(ProjectBlendRequest $request, BlendProjector $projector): JsonResponse
    {
        $components = array_values((array) $request->validated('components'));

        return response()->json(['data' => $projector->project($components, $this->volume($request))]);
    }

    public function execute(ExecuteBlendRequest $request, ExecuteBlendAction $action): RedirectResponse
    {
        $lot = $action->execute($request->validated(), $this->userId($request));

        return redirect('/cellar/lots/'.$lot->getKey())->with('success', __('Blend :name created.', ['name' => $lot->name]));
    }

    public function byRatio(ProjectBlendRequest $request, BlendProjector $projector, ExecuteBlendAction $action): RedirectResponse
    {
        $components = array_values((array) $request->validated('components'));
        $volume = $this->volume($request);

        $lot = $action->execute([
            'name' => $request->validated('name'),
            'vintage' => $request->validated('vintage'),
            'wine_type' => $request->validated('wine_type'),
            'destination_vessel_id' => $request->validated('destination_vessel_id'),
            'sources' => $projector->sources($components, $volume),
        ], $this->userId($request));

        return redirect('/cellar/lots/'.$lot->getKey())->with('success', __('Blend :name created.', ['name' => $lot->name]));
    }

    private function volume(ProjectBlendRequest $request): float
    {
        $volume = $request->validated('target_volume');
        if ($volume !== null) {
            return (float) $volume;
        }

        $vessel = Vessel::query()->whereKey((string) $request->validated('destination_vessel_id'))->firstOrFail();

        return max(0.0, (float) $vessel->capacity_liters - (float) $vessel->current_volume);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
