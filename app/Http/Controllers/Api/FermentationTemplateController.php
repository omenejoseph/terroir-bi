<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Cellar\CreateFermentationTemplateAction;
use App\Actions\Cellar\DeleteFermentationTemplateAction;
use App\Actions\Cellar\UpdateFermentationTemplateAction;
use App\DataTransferObjects\FermentationTemplateData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\StoreFermentationTemplateRequest;
use App\Http\Requests\Cellar\UpdateFermentationTemplateRequest;
use App\Models\FermentationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FermentationTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = FermentationTemplate::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $templates->map(fn (FermentationTemplate $t) => FermentationTemplateData::fromModel($t)->toArray())->all(),
        ]);
    }

    public function store(StoreFermentationTemplateRequest $request, CreateFermentationTemplateAction $action): JsonResponse
    {
        $template = $action->execute($request->validated());

        return response()->json(['data' => FermentationTemplateData::fromModel($template)->toArray()], 201);
    }

    public function update(UpdateFermentationTemplateRequest $request, FermentationTemplate $fermentationTemplate, UpdateFermentationTemplateAction $action): JsonResponse
    {
        $template = $action->execute($fermentationTemplate, $request->validated());

        return response()->json(['data' => FermentationTemplateData::fromModel($template)->toArray()]);
    }

    public function destroy(FermentationTemplate $fermentationTemplate, DeleteFermentationTemplateAction $action): JsonResponse
    {
        $action->execute($fermentationTemplate);

        return response()->json(status: 204);
    }
}
