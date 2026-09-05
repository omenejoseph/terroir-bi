<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Support\PerPage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Port of App\Filament\Resources\Users\**: a read-only directory of every user
 * account and the tenants attached to them. Mutations live elsewhere (Tenants'
 * member management, Platform Admins) — no create/edit/delete here.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->withCount('tenants')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('created_at', 'desc')
            ->paginate(PerPage::fromRequest($request), ['*'], 'page');

        return Inertia::render('Admin/Users/Index', [
            'users' => [
                'data' => array_map(fn (User $user): array => $this->row($user), $users->items()),
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ],
            ],
            'filters' => ['search' => $search !== '' ? $search : null],
        ]);
    }

    public function show(User $user): Response
    {
        $user->loadMissing('memberships.tenant');

        return Inertia::render('Admin/Users/Show', [
            'user' => [
                ...$this->row($user),
                'memberships' => $user->memberships
                    ->map(fn (Membership $membership): array => [
                        'tenant_name' => $membership->tenant?->name,
                        'roles' => $membership->roles->map(fn ($role) => $role->value)->all(),
                        'status' => $membership->status->value,
                    ])
                    ->values(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->fullName(),
            'email' => $user->email,
            'is_platform_admin' => $user->is_platform_admin === true,
            'tenants_count' => $user->tenants_count ?? 0,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
