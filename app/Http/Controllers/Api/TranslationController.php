<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Localization\TranslationServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only transport for the global translation overrides the frontend overlays
 * on its bundled catalogue. Editing lives in the back office (Filament).
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
}
