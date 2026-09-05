<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlatformAdmins\PromotePlatformAdminRequest;
use App\Http\Requests\Admin\PlatformAdmins\StorePlatformAdminRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Port of App\Filament\Resources\PlatformAdmins\**: manage the accounts that
 * can sign in to this back office. Create fresh admins, promote an existing
 * user, or revoke access — never a plain edit (matches the Filament resource,
 * which has no edit/view page either).
 */
class PlatformAdminController extends Controller
{
    public function index(): Response
    {
        $admins = User::query()
            ->where('is_platform_admin', true)
            ->orderBy('created_at')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->fullName(),
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
            ])
            ->values();

        // The "Promote existing user" picker's options — non-admins only, same
        // as ListPlatformAdmins' modal Select.
        $candidates = User::query()
            ->where('is_platform_admin', false)
            ->orderBy('email')
            ->get()
            ->map(fn (User $user): array => [
                'value' => $user->getKey(),
                'label' => $user->fullName().' ('.$user->email.')',
            ])
            ->values();

        return Inertia::render('Admin/PlatformAdmins/Index', [
            'admins' => $admins,
            'candidates' => $candidates,
            'currentUserId' => auth()->id(),
        ]);
    }

    /**
     * Creates a brand-new user, then grants the flag explicitly —
     * `is_platform_admin` is deliberately not mass-assignable.
     */
    public function store(StorePlatformAdminRequest $request, SetPlatformAdminAction $action): RedirectResponse
    {
        $data = $request->validated();

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $action->execute($user, true);

        return back()->with('success', __('Platform admin created.'));
    }

    public function promote(PromotePlatformAdminRequest $request, SetPlatformAdminAction $action): RedirectResponse
    {
        $user = User::query()->whereKey($request->validated('user_id'))->firstOrFail();

        $action->execute($user, true);

        return back()->with('success', __('Platform admin access granted.'));
    }

    /**
     * Never revoke yourself or the last remaining platform admin — the same
     * guard rails as the Filament row action's visibility, enforced here
     * server-side rather than only hidden client-side.
     */
    public function revoke(Request $request, User $user, SetPlatformAdminAction $action): RedirectResponse
    {
        if ($user->getKey() === $request->user()?->getKey()) {
            abort(HttpResponse::HTTP_FORBIDDEN, 'You cannot revoke your own platform-admin access.');
        }

        if (User::query()->where('is_platform_admin', true)->count() <= 1) {
            abort(HttpResponse::HTTP_FORBIDDEN, 'At least one platform admin must remain.');
        }

        $action->execute($user, false);

        return back()->with('success', __('Platform admin access revoked.'));
    }
}
