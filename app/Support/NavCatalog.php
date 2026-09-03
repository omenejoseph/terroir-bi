<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The backend's mirror of resources/js/lib/navigation.ts's `NAV_CATEGORIES`.
 * Display metadata (label, icon, capability) stays frontend-only — this side
 * only needs to know which keys exist, so a pin request can be validated, and
 * which of them correspond to a real route, so visits can be recorded.
 *
 * Keep ALL_KEYS in sync with navigation.ts by hand: nothing generates one from
 * the other, and a key missing here just means that item can never be pinned
 * or resolved as a recent visit, not a broken build.
 */
final class NavCatalog
{
    /**
     * nav_key => first path segment, for the routes RecordNavVisit tracks.
     * Only nav items with a real `href` in navigation.ts appear here.
     *
     * @var array<string, string>
     */
    public const ROUTES = [
        'dashboard' => 'dashboard',
        'orders' => 'orders',
        'customers' => 'customers',
        'work-orders' => 'work-orders',
        'inventory' => 'inventory',
    ];

    /**
     * Every key in navigation.ts's NAV_CATEGORIES, real route or not — a
     * member can pin a "designed but not implemented" item just as they can
     * see it in the sidebar, it just won't have anywhere to link yet.
     *
     * @var list<string>
     */
    public const ALL_KEYS = [
        'dashboard', 'cash-system',
        'orders', 'customers', 'work-orders', 'wine-club', 'agencies', 'pipeline',
        'accommodation', 'kitchen', 'hospitality',
        'cellar', 'harvest', 'production-plan',
        'inventory', 'purchase-orders', 'suppliers',
        'costs', 'inflow', 'cash-flow',
        'employees', 'schedules', 'my-team', 'surveys', 'my-hours',
        'settings', 'whatsapp-bot',
    ];

    /** The nav_key whose route owns this request's first path segment, if any. */
    public static function keyForPath(string $path): ?string
    {
        $segment = explode('/', $path, 2)[0];
        $key = array_search($segment, self::ROUTES, true);

        return $key === false ? null : $key;
    }
}
