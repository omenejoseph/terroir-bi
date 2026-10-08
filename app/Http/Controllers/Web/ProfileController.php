<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Profile\UpdatePasswordAction;
use App\Actions\Profile\UpdateProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\User;
use App\Services\Auth\ImpersonationSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My profile": any signed-in member manages their own name and password. No capability gate,
 * since it only ever touches the signed-in user's own account. While an admin is impersonating a
 * team member the page can be looked at but not changed (see the requests' authorize()).
 */
class ProfileController extends Controller
{
    public function edit(Request $request, ImpersonationSession $impersonation): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return Inertia::render('Profile/Edit', [
            'profile' => [
                'first_name' => $user->first_name,
                'middle_name' => $user->middle_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
            ],
            // Lets the page explain why its forms are switched off.
            'readOnly' => $impersonation->get() !== null,
        ]);
    }

    public function update(UpdateProfileRequest $request, UpdateProfileAction $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var array{first_name: string, middle_name?: ?string, last_name: string} $validated */
        $validated = $request->validated();
        $action->execute($user, $validated);

        return back()->with('success', __('Profile updated.'));
    }

    public function updatePassword(UpdatePasswordRequest $request, UpdatePasswordAction $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $action->execute($user, $request->string('password')->value());

        return back()->with('success', __('Password updated.'));
    }
}
