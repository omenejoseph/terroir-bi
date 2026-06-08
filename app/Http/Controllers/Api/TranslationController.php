<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Localization\DeleteTranslationOverrideAction;
use App\Actions\Localization\UpsertTranslationOverrideAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Localization\UpsertTranslationRequest;
use App\Http\Resources\TranslationOverrideResource;
use App\Services\Localization\TranslationServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin transport layer: validate, delegate to a Service/Action, return a
 * Resource. All business logic lives in the service/action so the exact same
 * calls can back a Livewire or Inertia frontend.
 */
class TranslationController extends Controller
{
    public function index(Request $request, TranslationServiceInterface $translations): JsonResponse
    {
        $locale = $request->query('locale');

        return response()->json([
            'data' => $translations->overrides(is_string($locale) ? $locale : null),
        ]);
    }

    public function upsert(
        UpsertTranslationRequest $request,
        UpsertTranslationOverrideAction $action,
    ): TranslationOverrideResource {
        $data = $action->execute(
            $request->string('locale')->value(),
            $request->string('key')->value(),
            $request->string('value')->value(),
        );

        return new TranslationOverrideResource($data);
    }

    public function destroy(Request $request, DeleteTranslationOverrideAction $action): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string'],
            'key' => ['required', 'string'],
        ]);

        $action->execute($validated['locale'], $validated['key']);

        return response()->json(status: 204);
    }
}
