<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
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
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Welcome');
    }
}
