<?php

declare(strict_types=1);

namespace App\Services\Localization;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Determines the active locale for a request.
 *
 * Precedence: explicit ?lang query / X-Locale header > the signed-in user's
 * personal locale (users.locale, only if they've set one) > a guest's
 * "terroir_locale" cookie (the pre-login equivalent of a personal override,
 * e.g. on the login/accept-invite screens) > the tenant's default locale >
 * the application default. Any requested locale not in
 * config('app.supported_locales') is ignored.
 *
 * A user who hasn't personally picked a language (locale is null) always
 * falls straight through to the tenant's default — the company's language is
 * what everyone sees until they individually override it.
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

        $user = $request->user();
        $personal = $user instanceof User ? $user->locale : null;

        if (is_string($personal) && in_array($personal, $supported, true)) {
            return $personal;
        }

        $cookie = $request->cookie('terroir_locale');

        if (is_string($cookie) && in_array($cookie, $supported, true)) {
            return $cookie;
        }

        // Tenant's default locale (the tenants.default_locale mirror of settings).
        if ($tenant !== null && in_array($tenant->default_locale, $supported, true)) {
            return $tenant->default_locale;
        }

        return (string) config('app.locale');
    }
}
