<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\UserAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public marketing home page at `/`.
 *
 * Unauthenticated and outside `tenant.web` on purpose, same reasoning as
 * `PublicOrderController`: this is the one page in the app a signed-out
 * visitor is meant to reach. A signed-in visitor is sent straight to their
 * dashboard instead of being shown the pitch for a product they already use.
 */
class WelcomeController extends Controller
{
    public function __invoke(Request $request, UserAuthenticator $authenticator): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            return redirect($authenticator->homePath($user));
        }

        return Inertia::render('Welcome');
    }
}
