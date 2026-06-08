<?php

declare(strict_types=1);

namespace App\Services\Localization;

use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Determines the active locale for a request.
 *
 * Precedence: explicit ?lang query / X-Locale header > the tenant's default
 * locale > the application default. Any requested locale not in
 * config('app.supported_locales') is ignored.
 *
 * (Accept-Language content negotiation can be layered on later; it is omitted
 * here to keep resolution explicit and predictable.)
 */
class LocaleResolver
{
    public function resolve(Request $request, ?Tenant $tenant = null): string
    {
        $supported = (array) config('app.supported_locales', []);

        $requested = $request->query('lang') ?? $request->header('X-Locale');

        if (is_string($requested) && in_array($requested, $supported, true)) {
            return $requested;
        }

        // Tenant's default locale (the tenants.default_locale mirror of settings).
        if ($tenant !== null && in_array($tenant->default_locale, $supported, true)) {
            return $tenant->default_locale;
        }

        return (string) config('app.locale');
    }
}
