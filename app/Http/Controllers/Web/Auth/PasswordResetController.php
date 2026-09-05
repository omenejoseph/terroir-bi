<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The landing page an admin-triggered reset email points to (UserController::
 * sendPasswordReset calls Password::sendResetLink, which — via User's
 * CanResetPassword trait — sends the framework's default ResetPassword
 * notification; its default resetUrl() builds a link to the `password.reset`
 * route name below, so no custom notification/URL override is needed).
 */
class PasswordResetController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)])->save();
            },
        );

        if (! Str::is(Password::PASSWORD_RESET, $status)) {
            throw ValidationException::withMessages([
                'email' => [trans($status)],
            ]);
        }

        return redirect('/login')->with('success', __('Password reset. Sign in with your new password.'));
    }
}
