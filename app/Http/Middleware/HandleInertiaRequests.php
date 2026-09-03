<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Authorization\MembershipContext;
use App\Authorization\RoleCapabilities;
use App\DataTransferObjects\TenantMembershipData;
use App\DataTransferObjects\UserData;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use App\Queries\UserShortcutsQuery;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

/**
 * Props shared with every Inertia response.
 *
 * Capabilities are resolved server-side from RoleCapabilities and shipped as a
 * flat list. The React frontend kept its own copy of the role→capability map
 * (frontend/src/lib/auth/capabilities.ts) which had to be hand-synced with the
 * PHP one; sharing the resolved list removes that duplication entirely. As
 * before, this only drives what the UI *shows* — every route still enforces
 * `can:*` server-side, so a visible button is not an authorization decision.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly MembershipContext $membership,
        private readonly UserShortcutsQuery $shortcuts,
    ) {}

    /**
     * Bust the client-side asset cache when a deploy changes the manifest.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $tenant = $this->tenants->current();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user instanceof User ? UserData::fromModel($user)->toArray() : null,
                'roles' => array_map(fn ($role) => $role->value, $this->membership->roles()),
                'capabilities' => $this->capabilities(),
                // Manage Shortcuts' pinned keys (Figma 143:4179) — eager,
                // since the sidebar on every tenant page needs them; the
                // "Recent" list below is the one that waits for the dialog.
                'shortcuts' => fn () => $this->pinnedShortcuts($user),
            ],
            'tenant' => $tenant === null ? null : [
                'id' => $tenant->getKey(),
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            // Only serialised when the tenant switcher actually asks for it.
            'tenants' => fn () => $user instanceof User ? $this->memberships($user) : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'locale' => app()->getLocale(),
            // Manage Shortcuts' "Recent" list — only fetched when the dialog
            // that shows it actually opens (a partial reload asking for it).
            'recentNavVisits' => Inertia::optional(fn () => $user instanceof User && $this->tenants->check()
                ? $this->shortcuts->recent($user)
                : []),
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ]);
    }

    /**
     * The active membership's capabilities, flattened across its roles.
     *
     * ADMIN holds the wildcard, which is passed through as-is; the client's
     * `can()` helper treats '*' as "everything".
     *
     * @return list<string>
     */
    private function capabilities(): array
    {
        $capabilities = [];

        foreach ($this->membership->roles() as $role) {
            foreach (RoleCapabilities::grants($role) as $capability) {
                $capabilities[$capability] = true;
            }
        }

        return array_keys($capabilities);
    }

    /**
     * @return list<string>
     */
    private function pinnedShortcuts(?User $user): array
    {
        if (! $user instanceof User || ! $this->tenants->check()) {
            return [];
        }

        return $this->shortcuts->pinned($user);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function memberships(User $user): array
    {
        return $user->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->with('tenant')
            ->get()
            ->map(fn (Membership $membership) => TenantMembershipData::fromModel($membership)->toArray())
            ->values()
            ->all();
    }
}
