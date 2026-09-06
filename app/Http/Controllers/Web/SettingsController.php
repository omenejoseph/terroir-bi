<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Settings\UpdateSettingsAction;
use App\DataTransferObjects\OrganizationSettingsData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\Tenant;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organisation settings (nav's "System · Settings", capability
 * `settings.manage`) — the Inertia counterpart of Api\SettingsController. Both
 * read and write through the same OrganizationSettingsData / UpdateSettingsAction,
 * so this page and the JSON API can never disagree about what's stored.
 *
 * The route itself is gated by `can:settings.manage` (see routes/web.php),
 * unlike the API's `show`, which is open to any member — this page is the
 * edit form, not a read surface something else needs.
 */
class SettingsController extends Controller
{
    public function edit(TenantContext $tenant): Response
    {
        $current = $tenant->current();
        abort_unless($current instanceof Tenant, 404);

        return Inertia::render('Settings/Index', [
            'settings' => OrganizationSettingsData::fromTenant($current)->toArray(),
            'localeOptions' => $this->localeOptions(),
            'timezoneOptions' => $this->timezoneOptions(),
        ]);
    }

    public function update(UpdateSettingsRequest $request, UpdateSettingsAction $action, TenantContext $tenant): RedirectResponse
    {
        $current = $tenant->current();
        abort_unless($current instanceof Tenant, 404);

        /** @var array{name: string, default_locale: string, timezone: string, company_oib?: string|null, annual_revenue_target?: int|null, channel_revenue_targets?: array<string, int>|null, cash_on_hand?: int|null, cash_on_hand_as_of?: string|null} $validated */
        $validated = $request->validated();

        $action->execute($current, $validated);

        return back()->with('success', __('Settings updated.'));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function localeOptions(): array
    {
        /** @var list<string> $locales */
        $locales = config('app.supported_locales', []);

        return array_map(
            fn (string $locale): array => ['value' => $locale, 'label' => strtoupper($locale)],
            $locales,
        );
    }

    /**
     * @return list<string>
     */
    private function timezoneOptions(): array
    {
        return timezone_identifiers_list();
    }
}
