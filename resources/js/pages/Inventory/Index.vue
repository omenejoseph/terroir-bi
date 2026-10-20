<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, Columns3, ListTree, PencilLine, Search, Upload, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import BulkEditTable from '@/components/inventory/BulkEditTable.vue';
import BulkImportDialog from '@/components/inventory/BulkImportDialog.vue';
import ItemFormPanel from '@/components/inventory/ItemFormPanel.vue';
import ItemViewPanel from '@/components/inventory/ItemViewPanel.vue';
import AttentionBand from '@/components/ui/AttentionBand.vue';
import Button from '@/components/ui/Button.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import Combobox from '@/components/ui/Combobox.vue';
import GripHandle from '@/components/ui/GripHandle.vue';
import LevelBar from '@/components/ui/LevelBar.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { csrfHeader } from '@/lib/csrf';
import { rememberListUrl } from '@/lib/listReturn';
import { useTranslations } from '@/composables/useTranslations';
import { usePopover } from '@/composables/usePopover';
import { useVisitPending } from '@/composables/useVisitPending';
import { cn } from '@/lib/cn';
import { formatMoney, formatQuantity } from '@/lib/money';
import type {
    InventoryFilters,
    InventoryItem,
    ItemAuditTrailEntry,
    ItemAvailability,
    ItemCustomerAttributionRow,
    ItemStockRank,
    ProductCategory,
} from '@/types/inventory';
import type { Paginated, SharedProps } from '@/types';
import type { StockMovement } from '@/types/stock';
import type { AttentionItem, ComboboxOption, TabItem } from '@/types/ui';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * Inventory list, following Figma 389:1592: page header with the two primary
 * actions, module tabs, the "Needs attention" band, a search field, category
 * tabs, then the table grouped by product group.
 *
 * The design's "Free to sell" column is omitted: it has no backing data (order
 * lines do not reserve stock), and an empty column would read as a bug. This
 * is documented in docs/design/README.md. "Cover" (days of stock left at the
 * trailing 30-day exit rate — the same figure and window Product Detail's own
 * Cover uses) IS backed, by `cover`.
 */
const props = defineProps<{
    items: Paginated<InventoryItem>;
    filters: InventoryFilters;
    attention: AttentionItem[];
    /** Across every item the filters match, not only this page. */
    totals: { on_hand: string };
    /** Item id -> days of stock left, null when nothing exited in the window. */
    cover: Record<string, number | null>;
    /** Only present while the Item — View drawer has asked for them. */
    itemMovements?: StockMovement[];
    /** Only present while the Item — View drawer has asked for it. */
    itemAuditTrail?: ItemAuditTrailEntry[];
    /** Only present while the Item — View drawer has asked for it. Null when there's nothing to rank the item against. */
    itemStockRank?: ItemStockRank | null;
    /** Only present while the Item — View drawer has asked for it. */
    itemCustomerAttribution?: ItemCustomerAttributionRow[];
    /** Only present while the Item — View drawer has asked for it. */
    itemAvailability?: ItemAvailability | null;
    /** The category tree, for the filter. Absent on partial reloads that don't ask for it. */
    productCategories?: ProductCategory[];
}>();

const page = usePage<SharedProps>();
const { can } = useAuth();
const { t } = useTranslations();

const search = ref(props.filters.search ?? '');

// Remember this exact list (search, filters, page) so the item page's back link returns here.
rememberListUrl('inventory');
watch(() => props.items, () => rememberListUrl('inventory'));

/* The design opens New Item as a drawer over the list, not as its own page.
   The same drawer edits an existing one — see ItemFormPanel. */
const itemFormOpen = ref(false);
const editingItem = ref<InventoryItem | null>(null);
const bulkImportOpen = ref(false);

function openNewItem(): void {
    editingItem.value = null;
    itemFormOpen.value = true;
}

function openEdit(item: InventoryItem): void {
    viewingId.value = null;
    editingItem.value = item;
    itemFormOpen.value = true;
}

/*
  Bulk edit is a MODE of this page rather than a route (Figma 270:9646): the
  header, tabs and filters stay put and only the table becomes editable.
*/
const bulkEditing = ref(false);

/*
  Clicking a row opens the design's Item — View drawer rather than navigating.
  Held as an id rather than the row's own object so the drawer always reflects
  the freshest `items` prop — after an edit or stock adjustment reloads it,
  the same object reference from before would otherwise go stale.
*/
const viewingId = ref<string | null>(null);
const viewing = computed(
    () =>
        localItems.value
            .flatMap((item) => [item, ...(item.previous_vintages ?? [])])
            .find((item) => item.id === viewingId.value) ?? null,
);
const bulkTable = ref<InstanceType<typeof BulkEditTable> | null>(null);

/* Debounced server-side search; `preserveState` keeps focus and the typed value
   through the partial reload, `replace` stops one history entry per keystroke. */
let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ search: value || undefined }), 300);
});

/*
  A category or chip click answers at once: the control shows the new choice immediately and the rows
  it is about to replace dim (after a beat, so a quick answer never flickers) until the response lands.
*/
const pending = useVisitPending();
const chosen = ref<{ category?: string | null; product_category_id?: string | null; attention?: string | null }>({});
const categoryCurrent = computed(() => ('category' in chosen.value ? chosen.value.category ?? '' : (props.filters.category ?? '')));
const attentionCurrent = computed(() => ('attention' in chosen.value ? chosen.value.attention ?? null : props.filters.attention));

function reload(overrides: Record<string, unknown>): void {
    router.get(
        '/inventory',
        {
            search: props.filters.search ?? undefined,
            category: props.filters.category ?? undefined,
            product_category_id: props.filters.product_category_id ?? undefined,
            missing_cost: props.filters.missing_cost ? 1 : undefined,
            attention: props.filters.attention ?? undefined,
            // Carried forward so changing a filter does not silently reset the
            // page size back to the server default.
            per_page: props.items.meta.per_page,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['items', 'filters', 'totals', 'cover', 'attention'] },
    );
}

/** Inventory Analytics' "Add costs" deep-link — dismissible, since nothing else on this page offers a way back out of it. */
function clearMissingCostFilter(): void {
    reload({ missing_cost: undefined });
}

const MODULE_TABS: TabItem[] = [
    { label: t('Inventory'), href: '/inventory' },
    { label: t('Analytics'), href: '/inventory-analytics' },
    { label: t('Inventory Spend'), href: '/inventory-spend' },
    { label: t('Inventory Check'), href: '/inventory-check' },
    { label: t('Categories'), href: '/inventory/categories' },
];

/** Mirrors the InventoryCategory enum; the design shows exactly these three. */
const CATEGORY_TABS: TabItem[] = [
    { value: 'FINISHED', label: t('Finished') },
    { value: 'SEMI_FINISHED', label: t('Semi-Finished') },
    { value: 'RAW_MATERIAL', label: t('Raw Materials') },
];

/** The design shows no "All" chip; pressing the active category clears it. */
function selectCategory(value: string): void {
    chosen.value = { ...chosen.value, category: props.filters.category === value ? null : value };
    const next = props.filters.category === value ? undefined : value;

    // A category belongs to one type, so switching type drops a category filter that no longer fits.
    const keep = (props.productCategories ?? []).some(
        (c) =>
            (next === undefined || c.type === next) &&
            (c.id === props.filters.product_category_id || (c.children ?? []).some((sub) => sub.id === props.filters.product_category_id)),
    );
    reload({ category: next, product_category_id: keep ? props.filters.product_category_id ?? undefined : undefined });
}

/** "Wine" and "Wine · Red" in one picker; narrowed to the chosen type. A top-level pick covers its subcategories. */
const categoryFilterOptions = computed<ComboboxOption[]>(() => [
    { value: '', label: t('All categories') },
    ...(props.productCategories ?? [])
        .filter((c) => !props.filters.category || c.type === props.filters.category)
        .flatMap((c) => [
            { value: c.id, label: c.name, description: String(c.items_count) },
            ...(c.children ?? []).map((s) => ({ value: s.id, label: `${c.name} · ${s.name}`, description: String(s.items_count) })),
        ]),
]);
const productCategoryCurrent = computed(() => ('product_category_id' in chosen.value ? chosen.value.product_category_id ?? '' : (props.filters.product_category_id ?? '')));

function selectProductCategory(value: string | null): void {
    chosen.value = { ...chosen.value, product_category_id: value || null };
    reload({ product_category_id: value || undefined, page: 1 });
}

/* ---- column chooser ------------------------------------------------------- */

type ColumnKey = 'size' | 'sku' | 'vintage' | 'on_hand' | 'cover' | 'level' | 'flags';

/** The name always shows; the rest can be hidden. Same order as the table. */
const ALL_COLUMNS: ColumnKey[] = ['size', 'sku', 'vintage', 'on_hand', 'cover', 'level', 'flags'];

const COLUMN_OPTIONS = computed<{ key: ColumnKey; label: string }[]>(() => [
    { key: 'size', label: t('Size') },
    { key: 'sku', label: t('SKU') },
    { key: 'vintage', label: t('Vintage') },
    { key: 'on_hand', label: t('On hand') },
    { key: 'cover', label: t('Cover') },
    { key: 'level', label: t('Level') },
    { key: 'flags', label: t('Flags') },
]);

/** Off until someone turns them on; a saved choice always wins. */
const HIDDEN_BY_DEFAULT: ColumnKey[] = ['level', 'flags'];

const COLUMNS_STORAGE_KEY = 'inventory.columns';

function loadColumns(): ColumnKey[] {
    try {
        const stored = JSON.parse(localStorage.getItem(COLUMNS_STORAGE_KEY) ?? 'null');

        if (Array.isArray(stored)) return ALL_COLUMNS.filter((key) => stored.includes(key));
    } catch {
        // Storage can be unavailable (private window); the defaults then apply.
    }

    return ALL_COLUMNS.filter((key) => !HIDDEN_BY_DEFAULT.includes(key));
}

const visibleColumns = ref<ColumnKey[]>(loadColumns());
const col = (key: ColumnKey): boolean => visibleColumns.value.includes(key);

function toggleColumn(key: ColumnKey): void {
    visibleColumns.value = ALL_COLUMNS.filter((k) => (k === key ? !col(k) : col(k)));

    try {
        localStorage.setItem(COLUMNS_STORAGE_KEY, JSON.stringify(visibleColumns.value));
    } catch {
        // Not persisted; the choice still applies for this visit.
    }
}

const columnsAnchor = ref<HTMLElement | null>(null);
const { open: columnsOpen, toggle: toggleColumns } = usePopover(columnsAnchor);

/** Grip + name + the visible columns — what a full-width band must span. */
const columnCount = computed(() => 2 + visibleColumns.value.length);
/** Width of a group band's right-hand figures: over the stock columns when shown, else a single cell. */
const bandRight = computed(() => {
    const stock = (['on_hand', 'cover', 'level', 'flags'] as ColumnKey[]).filter(col).length;

    return Math.min(Math.max(stock, 1), columnCount.value - 2);
});

/* ---- arrange categories: hide the products so only the categories are left to drag ----- */

/** With the products hidden, a long list is just its categories and subcategories — short enough to reorder by dragging. */
const arranging = ref(false);

/* ---- collapse a group band (the chevron) -------------------------------------- */

/** Group labels folded shut. Everything starts open; the choice lasts for the visit. */
const collapsed = ref<Set<string>>(new Set());

function toggleGroup(label: string): void {
    const next = new Set(collapsed.value);

    if (next.has(label)) next.delete(label);
    else next.add(label);

    collapsed.value = next;
}

/* ---- older vintages folded under a product's latest one ------------------------- */

/** Products whose older vintages are showing. Folded by default; the choice lasts for the visit. */
const unfolded = ref<Set<string>>(new Set());

function toggleVintages(id: string): void {
    const next = new Set(unfolded.value);

    if (next.has(id)) next.delete(id);
    else next.add(id);

    unfolded.value = next;
}

/** Stock across a product's whole chain — the latest vintage plus the older ones under it. */
function chainStock(item: InventoryItem): number {
    return [item, ...(item.previous_vintages ?? [])].reduce((sum, i) => sum + (Number.parseFloat(i.current_stock) || 0), 0);
}

function chainValue(item: InventoryItem): number {
    return [item, ...(item.previous_vintages ?? [])].reduce(
        (sum, i) => sum + (i.default_price ? i.default_price.minor * (Number.parseFloat(i.current_stock) || 0) : 0),
        0,
    );
}

/* ---- drag and drop: arrange items, subcategories and groups ----------------- */

type DragKind = 'item' | 'sub' | 'group';
interface DragState {
    kind: DragKind;
    /** Item id, or the subcategory / group label. */
    key: string;
    /** The group a subcategory or item belongs to; items also carry their subcategory. */
    group: string;
    sub: string;
}

const drag = ref<DragState | null>(null);
const dropTarget = ref<string | null>(null);

const groupOf = (item: InventoryItem): string => item.group ?? t('Ungrouped');
const subOf = (item: InventoryItem): string => item.subcategory ?? '';

function startDrag(event: DragEvent, state: DragState): void {
    drag.value = state;
    event.dataTransfer?.setData('text/plain', state.key);
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}

function endDrag(): void {
    drag.value = null;
    dropTarget.value = null;
}

/** Only a like-for-like drop is accepted: same kind, and the same parent band. */
function canDrop(kind: DragKind, group: string, sub: string): boolean {
    const d = drag.value;

    if (d === null || d.kind !== kind) return false;
    if (kind === 'group') return true;
    if (kind === 'sub') return d.group === group;

    return d.group === group && d.sub === sub;
}

/**
 * Dropping a product on a row of another band (or on another subcategory's header) moves it — and all its
 * vintages — into that category. Only within the same type of item: a finished wine does not become a raw
 * material by being dropped on one.
 */
function moveTarget(kind: DragKind, group: string, sub: string): InventoryItem | null {
    const d = drag.value;

    if (d === null || d.kind !== 'item' || (kind !== 'item' && kind !== 'sub')) return null;
    if (d.group === group && d.sub === sub) return null;

    const dragged = localItems.value.find((item) => item.id === d.key);
    const representative = localItems.value.find((item) => groupOf(item) === group && subOf(item) === sub);

    return dragged && representative && representative.category === dragged.category ? representative : null;
}

function onDragOver(event: DragEvent, kind: DragKind, key: string, group: string, sub: string): void {
    // A sub header carries its own label as the band; an item row carries its band's.
    if (moveTarget(kind, group, kind === 'sub' ? key : sub) !== null) {
        event.preventDefault();
        dropTarget.value = `${kind}:${key}`;

        return;
    }

    if (!canDrop(kind, group, sub)) return;

    event.preventDefault();
    dropTarget.value = `${kind}:${key}`;
}

/** Moves `from` to where `to` is: after it when travelling down, before it when travelling up. */
function moved<T>(list: T[], from: number, to: number): T[] {
    const next = [...list];
    const [picked] = next.splice(from, 1);
    next.splice(to, 0, picked!);

    return next;
}

function onDrop(kind: DragKind, key: string, group: string, sub: string): void {
    const d = drag.value;

    const target = moveTarget(kind, group, kind === 'sub' ? key : sub);
    if (d !== null && target !== null) {
        endDrag();

        const category = target.product_category_id;
        localItems.value = localItems.value.map((item) =>
            item.id === d.key
                ? { ...item, group: target.group, subcategory: target.subcategory, product_category_id: category }
                : item,
        );
        post({ ids: [d.key], product_category_id: category }, '/inventory-move');

        return;
    }

    // A drop on the wrong kind of row is left for the band around it to handle.
    if (d === null || d.kind !== kind || (kind !== 'group' && d.group !== group) || (kind === 'item' && d.sub !== sub)) return;

    endDrag();
    if (d.key === key) return;

    const rows = localItems.value;
    const category = rows.find((item) => groupOf(item) === (kind === 'group' ? key : group))?.category ?? '';

    if (kind === 'item') {
        const band = rows.filter((item) => groupOf(item) === group && subOf(item) === sub);
        const ids = moved(band.map((i) => i.id), band.findIndex((i) => i.id === d.key), band.findIndex((i) => i.id === key));
        const byId = new Map(band.map((i) => [i.id, i]));
        let slot = 0;

        localItems.value = rows.map((item) =>
            groupOf(item) === group && subOf(item) === sub ? byId.get(ids[slot++]!)! : item,
        );
        post({ ids });

        return;
    }

    const labels = (list: string[]): string[] =>
        moved(list, list.indexOf(d.key), list.indexOf(key));

    if (kind === 'sub') {
        const inGroup = rows.filter((item) => groupOf(item) === group);
        const order = labels([...new Set(inGroup.map(subOf))]);
        const arranged = order.flatMap((label) => inGroup.filter((item) => subOf(item) === label));
        let slot = 0;

        localItems.value = rows.map((item) => (groupOf(item) === group ? arranged[slot++]! : item));
        post({
            category,
            entries: order.filter((label) => label !== '').map((label) => ({ group, subcategory: label })),
        });

        return;
    }

    const order = labels([...new Set(rows.map(groupOf))]);

    localItems.value = order.flatMap((label) => rows.filter((item) => groupOf(item) === label));
    post({
        category,
        entries: order.map((label) => ({ group: label === t('Ungrouped') ? '' : label })),
    });
}

/**
 * Saved in the background: the list has already moved on screen, so this is a plain request with no
 * Inertia visit — nothing reloads, scroll and focus stay put. If the save fails the list snaps back to
 * what the server has.
 */
async function post(data: Record<string, unknown>, url = '/inventory-order'): Promise<void> {
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
            credentials: 'same-origin',
            body: JSON.stringify(data),
        });

        if (!response.ok) throw new Error(String(response.status));
    } catch {
        localItems.value = props.items.data;
        router.reload({ only: ['items'] });
    }
}


/**
 * The design groups rows by product group with a count and a subtotal. Grouping
 * is applied to the current page rather than the whole result set, so the
 * subtotals describe what is on screen.
 */
/**
 * The design groups twice (Figma 389:1592): a group band carrying a count and
 * the group's subtotal ("Wine (6) … 7.971 bottles · 163.502 €"), then quieter
 * uppercase subcategory bands inside it ("WHITE", "ROSÉ", "RED").
 */
const groups = computed(() => {
    const byGroup = new Map<string, InventoryItem[]>();

    for (const item of visibleRows.value) {
        const key = item.group ?? t('Ungrouped');
        byGroup.set(key, [...(byGroup.get(key) ?? []), item]);
    }

    return [...byGroup.entries()].map(([label, rows]) => {
        const bySub = new Map<string, InventoryItem[]>();
        for (const row of rows) bySub.set(row.subcategory ?? '', [...(bySub.get(row.subcategory ?? '') ?? []), row]);

        return {
            label,
            count: rows.length,
            bands: [...bySub.entries()].map(([sub, subRows]) => ({ sub, rows: subRows })),
            // A product's older vintages hold stock too, so the band's figures count the whole chain.
            onHand: rows.reduce((sum, r) => sum + chainStock(r), 0),
            value: rows.reduce((sum, r) => sum + chainValue(r), 0),
            currency: rows.find((r) => r.default_price)?.default_price?.currency ?? null,
        };
    });
});

/*
  Drag and drop rearranges this local copy straight away and saves it in the background; the server's
  list replaces it on the next response.
*/
const localItems = ref<InventoryItem[]>(props.items.data);

/*
  Infinite scroll: the list opens with the first page and fetches the next as the end comes into view,
  appending to what is already shown. These are background requests (like the drag-and-drop saves), so
  the URL stays on page 1 and an edit's reload simply replaces page 1 — after which the pages that had
  been scrolled through are fetched again, so the list does not collapse under the reader.
*/
const coverById = ref<Record<string, number | null>>({ ...props.cover });
const loadedPages = ref(props.items.meta.current_page);
const loadingMore = ref(false);
const hasMore = computed(() => loadedPages.value < props.items.meta.last_page);
const sentinel = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

/** The same query string `reload()` builds, for one page. */
function pageUrl(pageNumber: number): string {
    const params = new URLSearchParams();
    const f = props.filters;

    if (f.search) params.set('search', f.search);
    if (f.category) params.set('category', f.category);
    if (f.product_category_id) params.set('product_category_id', f.product_category_id);
    if (f.missing_cost) params.set('missing_cost', '1');
    if (f.attention) params.set('attention', f.attention);
    params.set('per_page', String(props.items.meta.per_page));
    params.set('page', String(pageNumber));

    return `/inventory?${params.toString()}`;
}

/** Fetches the next page and appends it. Returns false when there was nothing to add or it failed. */
async function appendNextPage(): Promise<boolean> {
    try {
        const response = await fetch(pageUrl(loadedPages.value + 1), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Inertia': 'true',
                'X-Inertia-Version': page.version ?? '',
                'X-Inertia-Partial-Component': 'Inventory/Index',
                'X-Inertia-Partial-Data': 'items,cover',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) return false;

        const body = (await response.json()) as { props: { items: Paginated<InventoryItem>; cover: Record<string, number | null> } };
        const known = new Set(localItems.value.map((item) => item.id));

        localItems.value = [...localItems.value, ...body.props.items.data.filter((item) => !known.has(item.id))];
        coverById.value = { ...coverById.value, ...body.props.cover };
        loadedPages.value = body.props.items.meta.current_page;

        return true;
    } catch {
        return false;
    }
}

async function loadMore(): Promise<void> {
    if (loadingMore.value || !hasMore.value || bulkEditing.value) return;

    loadingMore.value = true;
    await appendNextPage();
    loadingMore.value = false;

    // Still in view (a tall screen, a short page)? The observer only fires on a change, so nudge it.
    await nextTick();
    if (observer && sentinel.value) {
        observer.unobserve(sentinel.value);
        observer.observe(sentinel.value);
    }
}

let lastListKey = JSON.stringify([props.filters, props.items.meta.per_page]);

watch(
    () => props.items,
    async (items) => {
        const key = JSON.stringify([props.filters, items.meta.per_page]);
        // A new search / filter starts over; anything else (an edit, a stock change) keeps the depth reached.
        const depth = key === lastListKey ? loadedPages.value : 1;
        lastListKey = key;

        localItems.value = items.data;
        coverById.value = { ...props.cover };
        loadedPages.value = items.meta.current_page;

        if (depth > loadedPages.value) {
            loadingMore.value = true;
            while (loadedPages.value < depth && hasMore.value && (await appendNextPage()));
            loadingMore.value = false;
        }
    },
);

onMounted(() => {
    if (typeof IntersectionObserver === 'undefined') return;

    observer = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) void loadMore();
    }, { rootMargin: '400px' });

    watch(sentinel, (el, previous) => {
        if (previous) observer?.unobserve(previous);
        if (el) observer?.observe(el);
    }, { immediate: true });
});

onBeforeUnmount(() => observer?.disconnect());

/** Attention chips filter the visible rows client-side. */
/** The chips filter on the server now, so what is on the page is exactly what the chip counted. */
const visibleRows = computed(() => localItems.value);

/** The card header above the table, as the design words it. */
const listSummary = computed(() => {
    // Everything the filters match, from the server — not just the rows on this page.
    return t(':count products · :onHand on hand', { count: props.items.meta.total, onHand: qty(props.totals.on_hand) });
});

const qty = (v: string | null) => formatQuantity(v, page.props.locale);

/**
 * "Cover" (Figma 389:1592): days of stock left at the trailing 30-day exit
 * rate, in the design's own prose rather than a raw day count — "No exits
 * recorded" when nothing moved in the window, "About :count months" once it
 * runs past a couple of months, otherwise ":count days".
 */
function cover(item: InventoryItem): string {
    const days = coverById.value[item.id] ?? null;

    if (days === null) return t('No exits recorded');
    if (days > 365) return t('Beyond horizon');
    if (days >= 60) return t('About :count months', { count: Math.round(days / 30) });

    return t(':count days', { count: days });
}

/** Derived row badges, matching the design's "Flags" column. */
/**
 * The design renders flags as quiet stacked lines, not pills — the first at
 * full weight and the rest muted beneath it.
 */
function flags(item: InventoryItem): string[] {
    const out: string[] = [];

    if (item.min_stock === null) out.push(t('No min stock'));
    if (item.cost_per_unit === null) out.push(t('No cost per unit'));
    if ((Number.parseFloat(item.current_stock) || 0) <= 0) out.push(t('Zero stock'));

    return out;
}
</script>

<template>
    <Head :title="t('Inventory')" />
        <div class="space-y-5">
            <PageHeader :title="t('Inventory')">
                <template #actions>
                    <template v-if="bulkEditing">
                        <Button variant="outline" size="sm" @click="bulkEditing = false">
                            <X class="size-4" :stroke-width="1.5" />
                            {{ t('Cancel') }}
                        </Button>
                        <Button
                            size="sm"
                            :disabled="!bulkTable?.dirtyCount"
                            @click="bulkTable?.save()"
                        >
                            {{ t('Save Changes') }}<template v-if="bulkTable?.dirtyCount"> ({{ bulkTable.dirtyCount }})</template>
                        </Button>
                    </template>
                    <template v-else>
                        <Button v-if="can('inventory.bulk')" variant="outline" size="sm" @click="bulkImportOpen = true">
                            <Upload class="size-4" :stroke-width="1.5" />
                            {{ t('Bulk Import') }}
                        </Button>
                        <Button v-if="can('inventory.manage')" size="sm" @click="openNewItem">{{ t('New Item') }}</Button>
                    </template>
                </template>
            </PageHeader>

            <Tabs :items="MODULE_TABS" :current="t('Inventory')" />

            <AttentionBand :items="attention" :active="attentionCurrent" @select="(key) => { chosen = { ...chosen, attention: key }; reload({ attention: key ?? undefined, page: 1 }); }" />

            <!-- Search + category tabs -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative max-w-xs flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        :stroke-width="1.5"
                    />
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="t('Search name, SKU or vintage…')"
                        class="h-9 w-full rounded-lg border border-input bg-card pr-3 pl-9 text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                </div>

                <Tabs
                    :items="CATEGORY_TABS"
                    :current="categoryCurrent"
                    variant="filter"
                    @select="selectCategory"
                />

                <div v-if="(productCategories?.length ?? 0) > 0" class="w-56">
                    <Combobox
                        :model-value="productCategoryCurrent"
                        :options="categoryFilterOptions"
                        :placeholder="t('All categories')"
                        @update:model-value="selectProductCategory"
                    />
                </div>

                <button
                    v-if="filters.missing_cost"
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 border border-primary bg-primary px-2.5 text-xs text-primary-foreground"
                    @click="clearMissingCostFilter"
                >
                    {{ t('Missing cost') }}
                    <X class="size-3.5" :stroke-width="1.5" />
                </button>

                <!-- The count lives in the table's own header, not here. -->
                <div class="ml-auto flex items-center gap-2">
                    <Button
                        v-if="can('inventory.manage') && !bulkEditing"
                        :variant="arranging ? 'primary' : 'outline'"
                        size="sm"
                        :aria-pressed="arranging"
                        @click="arranging = !arranging"
                    >
                        <ListTree class="size-4" :stroke-width="1.5" />
                        {{ arranging ? t('Show products') : t('Arrange categories') }}
                    </Button>

                    <div v-if="!bulkEditing && !arranging" ref="columnsAnchor" class="relative">
                        <Button variant="outline" size="sm" :aria-expanded="columnsOpen" aria-haspopup="menu" @click="toggleColumns">
                            <Columns3 class="size-4" :stroke-width="1.5" />
                            {{ t('Columns') }}
                        </Button>
                        <div v-if="columnsOpen" class="absolute top-10 right-0 z-20 min-w-44 space-y-2 border border-border bg-card p-3">
                            <Checkbox
                                v-for="column in COLUMN_OPTIONS"
                                :key="column.key"
                                :model-value="col(column.key)"
                                :label="column.label"
                                @update:model-value="toggleColumn(column.key)"
                            />
                        </div>
                    </div>

                    <Button v-if="can('inventory.bulk') && !bulkEditing" variant="outline" size="sm" @click="bulkEditing = true">
                        <PencilLine class="size-4" :stroke-width="1.5" />
                        {{ t('Bulk edit') }}
                    </Button>
                </div>
            </div>

            <BulkEditTable
                v-if="bulkEditing"
                ref="bulkTable"
                :items="items.data"
                :locale="page.props.locale"
                @cancel="bulkEditing = false"
            />

            <!-- The table scrolls inside its own container so the page never does. -->
            <div v-else class="overflow-hidden rounded-lg border border-border bg-card">
                <div class="border-b border-border px-4 py-3">
                    <h3 class="text-sm font-semibold">
                        {{ filters.category ? CATEGORY_TABS.find((c) => c.value === filters.category)?.label : t('All products') }}
                    </h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ listSummary }}</p>
                </div>

                <p v-if="arranging" class="border-b border-border bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
                    {{ t('Products are hidden. Drag a category or subcategory by its handle to reorder it; the order is kept for everyone.') }}
                </p>

                <div class="overflow-x-auto transition-opacity duration-150" :class="pending ? 'opacity-50 delay-150' : ''" :aria-busy="pending">
                    <table class="w-full min-w-[64rem] text-sm">
                        <thead class="border-b border-border text-left text-3xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="w-8 py-2.5 pl-3"><span class="sr-only">{{ t('Reorder') }}</span></th>
                                <th scope="col" class="px-3 py-2.5 font-medium">{{ t('Name') }}</th>
                                <th v-if="col('size')" scope="col" class="px-3 py-2.5 font-medium">{{ t('Size') }}</th>
                                <th v-if="col('sku')" scope="col" class="px-3 py-2.5 font-medium">{{ t('SKU') }}</th>
                                <th v-if="col('vintage')" scope="col" class="px-3 py-2.5 font-medium">{{ t('Vintage') }}</th>
                                <th v-if="col('on_hand')" scope="col" class="px-3 py-2.5 text-right font-medium">{{ t('On hand') }}</th>
                                <th v-if="col('cover')" scope="col" class="px-3 py-2.5 font-medium">{{ t('Cover') }}</th>
                                <th v-if="col('level')" scope="col" class="px-3 py-2.5 font-medium">{{ t('Level') }}</th>
                                <th v-if="col('flags')" scope="col" class="px-3 py-2.5 font-medium">{{ t('Flags') }}</th>
                            </tr>
                        </thead>

                        <tbody
                            v-for="group in groups"
                            :key="group.label"
                            class="divide-y divide-border"
                            :class="[
                                drag?.kind === 'group' && drag.key === group.label && 'opacity-40',
                                dropTarget === `group:${group.label}` && 'outline outline-2 -outline-offset-2 outline-ring/60',
                            ]"
                            @dragover="onDragOver($event, 'group', group.label, group.label, '')"
                            @drop.prevent="onDrop('group', group.label, group.label, '')"
                        >
                            <!-- Group band: name, count and the page subtotal -->
                            <tr class="bg-muted/40">
                                <td class="py-2 pl-3">
                                    <GripHandle
                                        v-if="can('inventory.manage')"
                                        draggable
                                        :label="t('Drag to reorder')"
                                        @dragstart="startDrag($event, { kind: 'group', key: group.label, group: group.label, sub: '' })"
                                        @dragend="endDrag"
                                    />
                                    <GripHandle v-else />
                                </td>
                                <th :colspan="columnCount - 1 - bandRight" scope="colgroup" class="px-3 py-2 text-left">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 text-left"
                                        :aria-expanded="!collapsed.has(group.label)"
                                        :aria-label="collapsed.has(group.label) ? t('Expand :group', { group: group.label }) : t('Collapse :group', { group: group.label })"
                                        @click="toggleGroup(group.label)"
                                    >
                                        <ChevronDown
                                            class="size-3 text-muted-foreground transition-transform"
                                            :class="collapsed.has(group.label) && '-rotate-90'"
                                            :stroke-width="2"
                                        />
                                        <span class="text-xs font-semibold text-foreground">{{ group.label }}</span>
                                        <span class="text-xs text-muted-foreground">({{ group.count }})</span>
                                    </button>
                                </th>
                                <td v-if="columnCount > 2" :colspan="bandRight" class="px-3 py-2 text-right text-xs text-muted-foreground">
                                    {{ qty(String(group.onHand)) }} {{ t('on hand') }}<template v-if="group.currency">
                                        · {{ formatMoney(group.value, group.currency) }}</template
                                    >
                                </td>
                            </tr>

                            <template v-for="band in collapsed.has(group.label) ? [] : group.bands" :key="`${group.label}-${band.sub}`">
                                <!-- Subcategory band -->
                                <tr
                                    v-if="band.sub"
                                    class="bg-muted/20"
                                    :class="[
                                        drag?.kind === 'sub' && drag.key === band.sub && drag.group === group.label && 'opacity-40',
                                        dropTarget === `sub:${band.sub}` && 'outline outline-2 -outline-offset-2 outline-ring/60',
                                    ]"
                                    @dragover="onDragOver($event, 'sub', band.sub, group.label, '')"
                                    @drop.prevent="onDrop('sub', band.sub, group.label, '')"
                                >
                                    <td class="py-1.5 pl-3">
                                        <GripHandle
                                            v-if="can('inventory.manage')"
                                            draggable
                                            :label="t('Drag to reorder')"
                                            @dragstart.stop="startDrag($event, { kind: 'sub', key: band.sub, group: group.label, sub: '' })"
                                            @dragend="endDrag"
                                        />
                                        <GripHandle v-else />
                                    </td>
                                    <th
                                        :colspan="columnCount - 1"
                                        scope="colgroup"
                                        class="px-3 py-1.5 text-left text-3xs font-medium tracking-[0.08em] text-muted-foreground uppercase"
                                    >
                                        {{ band.sub }}
                                    </th>
                                </tr>

                                <template v-for="item in arranging ? [] : band.rows" :key="item.id">
                                <tr
                                    class="cursor-pointer hover:bg-accent/50"
                                    :class="[
                                        drag?.kind === 'item' && drag.key === item.id && 'opacity-40',
                                        dropTarget === `item:${item.id}` && 'outline outline-2 -outline-offset-2 outline-ring/60',
                                    ]"
                                    @click="viewingId = item.id"
                                    @dragover="onDragOver($event, 'item', item.id, group.label, band.sub)"
                                    @drop.prevent="onDrop('item', item.id, group.label, band.sub)"
                                >
                                    <td class="py-3 pl-3">
                                        <GripHandle
                                            v-if="can('inventory.manage')"
                                            draggable
                                            :label="t('Drag to reorder')"
                                            @dragstart.stop="startDrag($event, { kind: 'item', key: item.id, group: group.label, sub: band.sub })"
                                            @dragend="endDrag"
                                        />
                                        <GripHandle v-else />
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="flex items-center gap-3">
                                            <!-- Older vintages fold under the latest one; the chevron opens them. -->
                                            <button
                                                v-if="item.previous_vintages?.length"
                                                type="button"
                                                class="-ml-1 shrink-0 p-0.5 text-muted-foreground hover:text-foreground"
                                                :aria-expanded="unfolded.has(item.id)"
                                                :aria-label="unfolded.has(item.id) ? t('Hide older vintages') : t('Show older vintages')"
                                                @click.stop="toggleVintages(item.id)"
                                            >
                                                <ChevronRight class="size-3.5 transition-transform" :class="unfolded.has(item.id) && 'rotate-90'" :stroke-width="2" />
                                            </button>
                                            <img
                                                v-if="item.image_url"
                                                :src="item.image_url"
                                                alt=""
                                                class="size-7 shrink-0 border border-border object-cover"
                                            />
                                            <span
                                                v-else
                                                class="size-7 shrink-0 border border-border bg-muted"
                                                aria-hidden="true"
                                            />
                                            <div class="min-w-0">
                                                <span class="font-medium">{{ item.name }}</span>
                                                <p v-if="item.previous_vintages?.length" class="text-xs text-muted-foreground">
                                                    {{ t(':count older vintages · :stock on hand in all', { count: item.previous_vintages.length, stock: qty(String(chainStock(item))) }) }}
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td v-if="col('size')" class="px-3 py-3 text-muted-foreground">{{ item.unit_size ?? '—' }}</td>
                                    <td v-if="col('sku')" class="px-3 py-3 text-muted-foreground">{{ item.sku }}</td>
                                    <td v-if="col('vintage')" class="px-3 py-3 text-muted-foreground">{{ item.vintage ?? '—' }}</td>
                                    <td v-if="col('on_hand')" class="px-3 py-3 text-right font-semibold tabular-nums">
                                        {{ qty(item.current_stock) }}
                                    </td>
                                    <td v-if="col('cover')" class="px-3 py-3 text-muted-foreground">{{ cover(item) }}</td>
                                    <td v-if="col('level')" class="px-3 py-3">
                                        <LevelBar :value="item.current_stock" :min="item.min_stock">
                                            <template v-if="item.min_stock">
                                                {{ t(':stock of :min min', { stock: qty(item.current_stock), min: qty(item.min_stock) }) }}
                                            </template>
                                            <template v-else>{{ t('No min stock set') }}</template>
                                        </LevelBar>
                                    </td>
                                    <td v-if="col('flags')" class="px-3 py-3">
                                        <span
                                            v-for="(flag, i) in flags(item)"
                                            :key="flag"
                                            class="block text-xs"
                                            :class="i === 0 ? 'text-foreground' : 'text-muted-foreground'"
                                        >
                                            {{ flag }}
                                        </span>
                                        <span v-if="!flags(item).length" class="text-muted-foreground">—</span>
                                    </td>
                                </tr>

                                <!-- The older vintages, newest first -->
                                <template v-if="unfolded.has(item.id)">
                                    <tr
                                        v-for="older in item.previous_vintages"
                                        :key="older.id"
                                        class="cursor-pointer bg-muted/20 hover:bg-accent/50"
                                        @click="viewingId = older.id"
                                    >
                                        <td class="py-2.5 pl-3" />
                                        <td class="px-3 py-2.5">
                                            <div class="flex items-center gap-3 pl-6">
                                                <img
                                                    v-if="older.image_url"
                                                    :src="older.image_url"
                                                    alt=""
                                                    class="size-6 shrink-0 border border-border object-cover"
                                                />
                                                <span v-else class="size-6 shrink-0 border border-border bg-muted" aria-hidden="true" />
                                                <span class="text-muted-foreground">{{ older.name }}</span>
                                            </div>
                                        </td>
                                        <td v-if="col('size')" class="px-3 py-2.5 text-muted-foreground">{{ older.unit_size ?? '—' }}</td>
                                        <td v-if="col('sku')" class="px-3 py-2.5 text-muted-foreground">{{ older.sku }}</td>
                                        <td v-if="col('vintage')" class="px-3 py-2.5 text-muted-foreground">{{ older.vintage ?? '—' }}</td>
                                        <td v-if="col('on_hand')" class="px-3 py-2.5 text-right tabular-nums">{{ qty(older.current_stock) }}</td>
                                        <td v-if="col('cover')" class="px-3 py-2.5 text-muted-foreground">{{ cover(older) }}</td>
                                        <td v-if="col('level')" class="px-3 py-2.5">
                                            <LevelBar :value="older.current_stock" :min="older.min_stock">
                                                <template v-if="older.min_stock">
                                                    {{ t(':stock of :min min', { stock: qty(older.current_stock), min: qty(older.min_stock) }) }}
                                                </template>
                                                <template v-else>{{ t('No min stock set') }}</template>
                                            </LevelBar>
                                        </td>
                                        <td v-if="col('flags')" class="px-3 py-2.5">
                                            <span v-for="(flag, i) in flags(older)" :key="flag" class="block text-xs" :class="i === 0 ? 'text-foreground' : 'text-muted-foreground'">
                                                {{ flag }}
                                            </span>
                                            <span v-if="!flags(older).length" class="text-muted-foreground">—</span>
                                        </td>
                                    </tr>
                                </template>
                                </template>
                            </template>
                        </tbody>

                        <tbody v-if="!groups.length">
                            <tr>
                                <td :colspan="columnCount" class="px-4 py-10 text-center text-muted-foreground">
                                    {{ t('No items match these filters.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Scrolling to here loads the next page. -->
            <div v-if="!bulkEditing && items.meta.total > 0" class="flex items-center justify-center gap-2 py-2 text-xs text-muted-foreground">
                <div ref="sentinel" aria-hidden="true" />
                <template v-if="loadingMore">
                    <span class="size-3.5 animate-spin rounded-full border-2 border-muted-foreground/30 border-t-foreground" />
                    {{ t('Loading more…') }}
                </template>
                <template v-else>{{ t('Showing :shown of :total products', { shown: localItems.length, total: items.meta.total }) }}</template>
            </div>
        </div>

        <ItemFormPanel :open="itemFormOpen" :item="editingItem" @close="itemFormOpen = false" />
        <BulkImportDialog :open="bulkImportOpen" @close="bulkImportOpen = false" />
        <ItemViewPanel
            :item="viewing"
            :movements="itemMovements ?? []"
            :audit-trail="itemAuditTrail ?? []"
            :stock-rank="itemStockRank ?? null"
            :customer-attribution="itemCustomerAttribution ?? []"
            :availability="itemAvailability && itemAvailability.item_id === viewing?.id ? itemAvailability : null"
            @close="viewingId = null"
            @edit="openEdit"
        />
</template>
