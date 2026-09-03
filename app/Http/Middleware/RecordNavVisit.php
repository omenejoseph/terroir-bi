<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Shortcuts\RecordNavVisitAction;
use App\Models\User;
use App\Support\NavCatalog;
use App\Tenancy\Contracts\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feeds Manage Shortcuts' "Recent" list (Figma `143:4179`): on a GET to one of
 * NavCatalog::ROUTES's pages, upserts a visit timestamp for the current
 * member. Runs in the `tenant.web` group, after ResolveTenant, so both the
 * user and the tenant are available.
 *
 * Only real GETs to a catalog route record a visit — a form POST redirected
 * back through the same path, or a path no nav item points at, does not.
 */
class RecordNavVisit
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('get') && $this->tenant->check()) {
            $key = NavCatalog::keyForPath($request->path());
            $user = $request->user();

            if ($key !== null && $user instanceof User) {
                app(RecordNavVisitAction::class)->execute($user, $key);
            }
        }

        return $response;
    }
}
