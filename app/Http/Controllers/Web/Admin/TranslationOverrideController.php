<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TranslationOverrides\StoreTranslationOverrideRequest;
use App\Http\Requests\Admin\TranslationOverrides\UpdateTranslationOverrideRequest;
use App\Models\TranslationOverride;
use App\Services\Localization\TranslationServiceInterface;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Port of App\Filament\Resources\TranslationOverrides\**, grown from a plain
 * CRUD list into a browsable catalog: every bundled UI string (the JSON
 * source-string catalog TranslationService::all() already merges overrides
 * into) is listed so any of them can be overridden without knowing its exact
 * key up front, with the ones already overridden marked inline. A manual
 * "New override" still exists for a key outside that catalog (e.g. a
 * dot-keyed backend-only string) — see TranslationOverrideFormPanel.vue.
 */
class TranslationOverrideController extends Controller
{
    public function index(Request $request, TranslationServiceInterface $translations): Response
    {
        $localeOptions = $this->localeOptions();
        $locale = $this->resolveLocale($request, $localeOptions);
        $search = trim((string) $request->query('search', ''));

        $catalog = $translations->all($locale);
        ksort($catalog);

        $overrideIds = TranslationOverride::query()->where('locale', $locale)->pluck('id', 'key');

        $rows = [];
        foreach ($catalog as $key => $value) {
            if ($search !== '' && stripos($key, $search) === false && stripos($value, $search) === false) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'value' => $value,
                'is_overridden' => $overrideIds->has($key),
                'override_id' => $overrideIds->get($key),
            ];
        }

        $perPage = PerPage::fromRequest($request);
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $request->query('page', 1)), $lastPage);

        return Inertia::render('Admin/TranslationOverrides/Index', [
            'catalog' => [
                'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
                'meta' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ],
            'filters' => ['search' => $search !== '' ? $search : null, 'locale' => $locale],
            'localeOptions' => $localeOptions,
        ]);
    }

    /**
     * updateOrCreate rather than a bare create(): the catalog row an admin
     * clicked "Override" on is a snapshot, and (locale, key) is uniquely
     * constrained — this turns a would-be duplicate-key failure into simply
     * saving the value, exactly as if they'd hit an existing override's Edit.
     */
    public function store(StoreTranslationOverrideRequest $request, TranslationServiceInterface $translations): RedirectResponse
    {
        $data = $request->validated();

        TranslationOverride::query()->updateOrCreate(
            ['locale' => $data['locale'], 'key' => $data['key']],
            ['value' => $data['value']],
        );

        $translations->flush($data['locale']);

        return back()->with('success', __('Translation override saved.'));
    }

    public function update(
        UpdateTranslationOverrideRequest $request,
        TranslationOverride $translationOverride,
        TranslationServiceInterface $translations,
    ): RedirectResponse {
        $translationOverride->update($request->validated());
        $translations->flush($translationOverride->locale);

        return back()->with('success', __('Translation override updated.'));
    }

    public function destroy(TranslationOverride $translationOverride, TranslationServiceInterface $translations): RedirectResponse
    {
        $locale = $translationOverride->locale;
        $translationOverride->delete();
        $translations->flush($locale);

        return back()->with('success', __('Translation override deleted.'));
    }

    /**
     * @param  list<array{value: string, label: string}>  $localeOptions
     */
    private function resolveLocale(Request $request, array $localeOptions): string
    {
        $locale = $request->query('locale');
        $allowed = array_column($localeOptions, 'value');

        if (is_string($locale) && in_array($locale, $allowed, true)) {
            return $locale;
        }

        return (string) ($allowed[0] ?? config('app.locale'));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function localeOptions(): array
    {
        $labels = ['hr' => 'Hrvatski', 'en' => 'English'];

        return array_values(array_map(
            fn ($code): array => ['value' => (string) $code, 'label' => $labels[$code] ?? strtoupper((string) $code)],
            (array) config('app.supported_locales', []),
        ));
    }
}
