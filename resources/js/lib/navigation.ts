import {
    Boxes,
    Building2,
    CalendarDays,
    ChartLine,
    ClipboardList,
    Clock,
    Coins,
    CookingPot,
    CreditCard,
    FileStack,
    Grape,
    LayoutGrid,
    type LucideIcon,
    MessageCircle,
    Package,
    Receipt,
    Settings,
    ShoppingCart,
    Sprout,
    Store,
    Truck,
    UserRound,
    Users,
    Warehouse,
    Wine,
} from 'lucide-vue-next';

/**
 * Sidebar navigation, mirroring the TERROIR Figma design (node 547:1568,
 * "ExpandedNav"). The design groups items under collapsible category headings
 * rather than presenting one flat list; the order and labels below are taken
 * from that node verbatim.
 *
 * `href: null` marks an item the design specifies but the Inertia app does not
 * serve yet — it renders disabled rather than as a dead link, so the sidebar
 * matches the design while making the remaining work visible. Give it a path as
 * each module is ported.
 *
 * `capability` mirrors the `can:*` middleware on the matching route. The server
 * is the authority; hiding an item is presentation only.
 *
 * Icons: the Figma assets could not be exported (the asset host is blocked by
 * this environment's egress policy), so these are the matching Lucide glyphs —
 * the same icon set the outgoing React app used. See docs/design/README.md.
 */

export interface NavItem {
    label: string;
    /** null = designed but not yet implemented; renders disabled. */
    href: string | null;
    icon: LucideIcon;
    capability?: string;
}

export interface NavCategory {
    label: string;
    items: NavItem[];
}

export const NAV_CATEGORIES: NavCategory[] = [
    {
        label: 'Overview',
        items: [
            { label: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
            { label: 'Cash System', href: null, icon: Coins, capability: 'finance.view' },
        ],
    },
    {
        label: 'Sales',
        items: [
            { label: 'Orders', href: '/orders', icon: ShoppingCart, capability: 'orders.view' },
            { label: 'Customers', href: '/customers', icon: Users, capability: 'customers.view' },
            { label: 'Work Orders', href: null, icon: ClipboardList, capability: 'production.view' },
            { label: 'Wine Club', href: null, icon: Wine, capability: 'customers.view' },
            { label: 'Agencies', href: null, icon: Building2, capability: 'customers.view' },
            { label: 'Pipeline', href: null, icon: ChartLine, capability: 'customers.view' },
        ],
    },
    {
        label: 'Hospitality',
        items: [
            { label: 'Accommodation', href: null, icon: Store },
            { label: 'Kitchen', href: null, icon: CookingPot },
            { label: 'Hospitality', href: null, icon: Wine },
        ],
    },
    {
        label: 'Production',
        items: [
            { label: 'Cellar', href: null, icon: Warehouse, capability: 'cellar.view' },
            { label: 'Harvest', href: null, icon: Grape, capability: 'vineyards.view' },
            { label: 'Production Plan', href: null, icon: Sprout, capability: 'production.view' },
        ],
    },
    {
        label: 'Supply',
        items: [
            { label: 'Inventory', href: '/inventory', icon: Package, capability: 'inventory.view' },
            { label: 'Purchase Orders', href: null, icon: FileStack, capability: 'suppliers.view' },
            { label: 'Suppliers', href: null, icon: Truck, capability: 'suppliers.view' },
        ],
    },
    {
        label: 'Finance',
        items: [
            { label: 'Costs', href: null, icon: Receipt, capability: 'finance.view' },
            { label: 'Inflow', href: null, icon: CreditCard, capability: 'finance.view' },
            { label: 'Cash Flow', href: null, icon: ChartLine, capability: 'financials.view' },
        ],
    },
    {
        label: 'Team',
        items: [
            { label: 'Employees', href: null, icon: Users, capability: 'members.view' },
            { label: 'Schedules', href: null, icon: CalendarDays },
            { label: 'My Team', href: null, icon: UserRound },
            { label: 'Surveys', href: null, icon: ClipboardList },
            { label: 'My Hours', href: null, icon: Clock },
        ],
    },
    {
        label: 'System',
        items: [
            { label: 'Settings', href: null, icon: Settings, capability: 'settings.manage' },
            { label: 'WhatsApp Bot', href: null, icon: MessageCircle, capability: 'settings.manage' },
        ],
    },
];

/**
 * The design's "Shortcuts" section — a user-pinned list sitting above the
 * categories. Pinning is not implemented yet, so this renders the design's
 * default set.
 */
export const SHORTCUTS: NavItem[] = [
    { label: 'Cash System', href: null, icon: Coins },
    { label: 'Orders', href: '/orders', icon: ShoppingCart },
    { label: 'Harvest', href: null, icon: Grape },
    { label: 'Inventory', href: '/inventory', icon: Boxes, capability: 'inventory.view' },
];

/** Drop items the member has no capability for; keep categories that still have items. */
export function navigationFor(can: (capability: string) => boolean): NavCategory[] {
    return NAV_CATEGORIES.map((category) => ({
        ...category,
        items: category.items.filter((item) => item.capability === undefined || can(item.capability)),
    })).filter((category) => category.items.length > 0);
}

export function shortcutsFor(can: (capability: string) => boolean): NavItem[] {
    return SHORTCUTS.filter((item) => item.capability === undefined || can(item.capability));
}
