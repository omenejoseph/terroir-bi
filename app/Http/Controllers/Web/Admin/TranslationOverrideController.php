<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TranslationOverrides\StoreTranslationOverrideRequest;
use App\Http\Requests\Admin\TranslationOverrides\UpdateTranslationOverrideRequest;
use App\Models\TranslationOverride;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Port of App\Filament\Resources\TranslationOverrides\**: plain CRUD over
 * global (platform-wide) translation overrides, no view page — same shape as
 * the Filament resource (list + create/edit only).
 */
class TranslationOverrideController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $overrides = TranslationOverride::query()
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('key', 'like', "%{$search}%")
                    ->orWhere('value', 'like', "%{$search}%");
            }))
            ->orderBy('key')
            ->paginate(PerPage::fromRequest($request), ['*'], 'page');

        return Inertia::render('Admin/TranslationOverrides/Index', [
            'overrides' => [
                'data' => $overrides->items(),
                'meta' => [
                    'current_page' => $overrides->currentPage(),
                    'last_page' => $overrides->lastPage(),
                    'per_page' => $overrides->perPage(),
                    'total' => $overrides->total(),
                ],
            ],
            'filters' => ['search' => $search !== '' ? $search : null],
        ]);
    }

    public function store(StoreTranslationOverrideRequest $request): RedirectResponse
    {
        TranslationOverride::query()->create($request->validated());

        return back()->with('success', __('Translation override created.'));
    }

    public function update(UpdateTranslationOverrideRequest $request, TranslationOverride $translationOverride): RedirectResponse
    {
        $translationOverride->update($request->validated());

        return back()->with('success', __('Translation override updated.'));
    }

    public function destroy(TranslationOverride $translationOverride): RedirectResponse
    {
        $translationOverride->delete();

        return back()->with('success', __('Translation override deleted.'));
    }
}
