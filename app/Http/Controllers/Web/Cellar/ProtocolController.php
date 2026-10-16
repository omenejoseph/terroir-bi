<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cellar;

use App\Actions\Cellar\CreateFermentationTemplateAction;
use App\Actions\Cellar\DeleteFermentationTemplateAction;
use App\Actions\Cellar\UpdateFermentationTemplateAction;
use App\DataTransferObjects\FermentationTemplateData;
use App\Enums\WineLotStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\StoreFermentationTemplateRequest;
use App\Http\Requests\Cellar\UpdateFermentationTemplateRequest;
use App\Models\FermentationTemplate;
use App\Models\WineLot;
use App\Queries\CellarPickerOptions;
use App\Queries\FermentationMonitorQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cellar › Protocols: fermentation protocol templates (stages, actions and
 * structured additive doses) and the fermentation monitor's alerts. The
 * prototype kept the editor under Harvest; it sits with the cellar here.
 */
class ProtocolController extends Controller
{
    public function index(FermentationMonitorQuery $monitor, CellarPickerOptions $options): Response
    {
        $inUse = WineLot::query()
            ->whereNotNull('fermentation_template_id')
            ->whereIn('status', [WineLotStatus::Fermenting->value, WineLotStatus::Aging->value])
            ->selectRaw('fermentation_template_id, count(*) as lots')
            ->groupBy('fermentation_template_id')
            ->pluck('lots', 'fermentation_template_id');

        return Inertia::render('Cellar/Protocols', [
            'templates' => array_values(FermentationTemplate::query()->with('additives')->orderBy('name')->get()
                ->map(fn (FermentationTemplate $t): array => [
                    ...FermentationTemplateData::fromModel($t)->toArray(),
                    'lots_in_use' => (int) ($inUse[$t->getKey()] ?? 0),
                ])->all()),
            'alerts' => $monitor->get(),
            'products' => fn (): array => $options->products(),
        ]);
    }

    public function store(StoreFermentationTemplateRequest $request, CreateFermentationTemplateAction $action): RedirectResponse
    {
        $action->execute($request->validated());

        return back()->with('success', __('Protocol saved.'));
    }

    public function update(UpdateFermentationTemplateRequest $request, FermentationTemplate $fermentationTemplate, UpdateFermentationTemplateAction $action): RedirectResponse
    {
        $action->execute($fermentationTemplate, $request->validated());

        return back()->with('success', __('Protocol saved.'));
    }

    public function destroy(FermentationTemplate $fermentationTemplate, DeleteFermentationTemplateAction $action): RedirectResponse
    {
        $action->execute($fermentationTemplate);

        return back()->with('success', __('Protocol deleted.'));
    }
}
