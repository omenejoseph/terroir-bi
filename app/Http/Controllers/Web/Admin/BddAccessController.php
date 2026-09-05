<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Bdd\GrantBddOperationAction;
use App\Actions\Bdd\RevokeBddOperationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BddAccess\OperationKeyRequest;
use App\Services\Bdd\CurrentOperator;
use App\Services\Bdd\OperationRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Port of App\Filament\Pages\BddAccess: the fail-closed BDD operation
 * allowlist manager. Built-in seeds/probes are always available, shown only
 * for reference; every discoverable action class needs an explicit grant.
 */
class BddAccessController extends Controller
{
    public function index(OperationRegistry $registry): Response
    {
        return Inertia::render('Admin/BddAccess/Index', [
            'builtIns' => $registry->builtInSpecs(),
            'actions' => $registry->discoverActionsWithGrants(),
        ]);
    }

    public function grant(OperationKeyRequest $request, GrantBddOperationAction $action): RedirectResponse
    {
        try {
            $action->execute($request->validated('key'), CurrentOperator::id());
        } catch (Throwable $e) {
            return back()->with('error', __('Could not grant: :message', ['message' => $e->getMessage()]));
        }

        return back()->with('success', __('Access granted.'));
    }

    public function revoke(OperationKeyRequest $request, RevokeBddOperationAction $action): RedirectResponse
    {
        $action->execute($request->validated('key'));

        return back()->with('success', __('Access revoked — scenarios using it will park as "needs access" on their next run.'));
    }
}
