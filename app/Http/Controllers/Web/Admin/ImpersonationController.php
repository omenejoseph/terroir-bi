<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Auth\StartImpersonationAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Starts a "log in as" session — must be launched from here (platform.admin,
 * is_platform_admin required); stopping it lives under Web\Auth instead, since
 * by the time someone stops, the session's user is the impersonation TARGET,
 * not a platform admin. See StopImpersonationController's docblock.
 */
class ImpersonationController extends Controller
{
    public function store(Request $request, User $user, StartImpersonationAction $action): RedirectResponse
    {
        $admin = $request->user();
        abort_unless($admin instanceof User, 401);

        $action->execute($admin, $user);

        return redirect('/dashboard');
    }
}
