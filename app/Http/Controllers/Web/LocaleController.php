<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\UpdateLocaleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/**
 * A personal language override, distinct from the tenant's default_locale —
 * see LocaleResolver's docblock for the full precedence chain. Runs with no
 * auth requirement (unlike TenantSwitchController) since LanguageSwitcher.vue
 * is also mounted on the guest login screen.
 */
class LocaleController extends Controller
{
    public function update(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = $request->string('locale')->value();
        $user = $request->user();

        if ($user instanceof User) {
            $user->update(['locale' => $locale]);
        }

        // Always queued too, even when authenticated: covers a user who picks a
        // language before logging in, and is the only mechanism at all for a
        // guest (login/accept-invite) — there's no user row to persist to yet.
        Cookie::queue('terroir_locale', $locale, 60 * 24 * 365);

        return back();
    }
}
