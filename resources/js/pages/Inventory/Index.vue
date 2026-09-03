<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { ChevronDown, PencilLine, Upload, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import BulkEditTable from '@/components/inventory/BulkEditTable.vue';
import ItemViewPanel from '@/components/inventory/ItemViewPanel.vue';
import NewItemPanel from '@/components/inventory/NewItemPanel.vue';
import AttentionBand from '@/components/ui/AttentionBand.vue';
import Button from '@/components/ui/Button.vue';
import GripHandle from '@/components/ui/GripHandle.vue';
import LevelBar from '@/components/ui/LevelBar.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { cn } from '@/lib/cn';
import { formatMoney, formatQuantity } from '@/lib/money';
import type { InventoryFilters, InventoryItem } from '@/types/inventory';
import type { Paginated, SharedProps } from '@/types';
import type { StockMovement } from '@/types/stock';
import type { AttentionItem, TabItem } from '@/types/ui';

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
    /** Item id -> days of stock left, null when nothing exited in the window. */
    cover: Record<string, number | null>;
    /** Only present while the Item — View drawer has asked for them. */
    itemMovements?: StockMovement[];
}>();

const page = usePage<SharedProps>();
const { can } = useAuth();
const { t } = useTranslations();

const search = ref(props.filters.search ?? '');

/* The design opens New Item as a drawer over the list, not as its own page. */
const newItemOpen = ref(false);

/*
  Bulk edit is a MODE of this page rather than a route (Figma 270:9646): the
  header, tabs and filters stay put and only the table becomes editable.
*/
const bulkEditing = ref(false);

/* Clicking a row opens the design's Item — View drawer rather than navigating. */
const viewing = ref<InventoryItem | null>(null);
const bulkTable = ref<InstanceType<typeof BulkEditTable> | null>(null);

/* Debounced server-side search; `preserveState` keeps focus and the typed value
   through the partial reload, `replace` stops one history entry per keystroke. */
let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ search: value || undefined }), 300);
});

function reload(overrides: Record<string, unknown>): void {
    router.get(
        '/inventory',
        {
            search: props.filters.search ?? undefined,
            category: props.filters.category ?? undefined,
            // Carried forward so changing a filter does not silently reset the
            // page size back to the server default.
            per_page: props.items.meta.per_page,
            ...overrides,
        },
        { preserveState: true, replace: true, only: ['items', 'filters'] },
    );
}

function setPerPage(perPage: number): void {
    // Changing the page size while sitting on a later page would silently
    // show nothing if the list is now shorter — reset to page 1 with it.
    reload({ per_page: perPage, page: 1 });
}

const MODULE_TABS: TabItem[] = [
    { label: t('Inventory'), href: '/inventory' },
    { label: t('Analytics'), href: '/inventory-analytics' },
    { label: t('Inventory Spend'), href: '/inventory-spend' },
    { label: t('Inventory Check'), href: '/inventory-check' },
];

/** Mirrors the InventoryCategory enum; the design shows exactly these three. */
const CATEGORY_TABS: TabItem[] = [
    { value: 'FINISHED', label: t('Finished') },
    { value: 'SEMI_FINISHED', label: t('Semi-Finished') },
    { value: 'RAW_MATERIAL', label: t('Raw Materials') },
];

/** The design shows no "All" chip; pressing the active category clears it. */
function selectCategory(value: string): void {
    reload({ category: props.filters.category === value ? undefined : value });
}

/** The attention chips double as filters. */
const attentionFilter = ref<string | null>(null);

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
            onHand: rows.reduce((sum, r) => sum + (Number.parseFloat(r.current_stock) || 0), 0),
            value: rows.reduce(
                (sum, r) =>
                    sum + (r.default_price ? r.default_price.minor * (Number.parseFloat(r.current_stock) || 0) : 0),
                0,
            ),
            currency: rows.find((r) => r.default_price)?.default_price?.currency ?? null,
        };
    });
});

/** Attention chips filter the visible rows client-side. */
const visibleRows = computed(() => {
    const key = attentionFilter.value;

    if (key === null) return props.items.data;

    return props.items.data.filter((item) => {
        const stock = Number.parseFloat(item.current_stock) || 0;

        if (key === 'no_min_stock') return item.min_stock === null;
        if (key === 'no_cost_per_unit') return item.cost_per_unit === null;
        if (key === 'zero_stock') return stock <= 0;

        return true;
    });
});

/** The card header above the table, as the design words it. */
const listSummary = computed(() => {
    const total = visibleRows.value.reduce((sum, r) => sum + (Number.parseFloat(r.current_stock) || 0), 0);

    return t(':count products · :onHand on hand', { count: visibleRows.value.length, onHand: qty(String(total)) });
});

const qty = (v: string | null) => formatQuantity(v, page.props.locale);

/**
 * "Cover" (Figma 389:1592): days of stock left at the trailing 30-day exit
 * rate, in the design's own prose rather than a raw day count — "No exits
 * recorded" when nothing moved in the window, "About :count months" once it
 * runs past a couple of months, otherwise ":count days".
 */
function cover(item: InventoryItem): string {
    const days = props.cover[item.id] ?? null;

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
    <AppLayout :title="t('Inventory')">
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
                        <Button v-if="can('inventory.manage')" variant="outline" size="sm">
                            <Upload class="size-4" :stroke-width="1.5" />
                            {{ t('Bulk Import') }}
                        </Button>
                        <Button v-if="can('inventory.manage')" size="sm" @click="newItemOpen = true">{{ t('New Item') }}</Button>
                    </template>
                </template>
            </PageHeader>

            <Tabs :items="MODULE_TABS" :current="t('Inventory')" />

            <AttentionBand :items="attention" :active="attentionFilter" @select="attentionFilter = $event" />

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
                    :current="filters.category ?? ''"
                    variant="filter"
                    @select="selectCategory"
                />

                <!-- The count lives in the table's own header, not here. -->
                <Button
                    v-if="can('inventory.manage') && !bulkEditing"
                    variant="outline"
                    size="sm"
                    class="ml-auto"
                    @click="bulkEditing = true"
                >
                    <PencilLine class="size-4" :stroke-width="1.5" />
                    {{ t('Bulk edit') }}
                </Button>
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

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[64rem] text-sm">
                        <thead class="border-b border-border text-left text-3xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="w-8 py-2.5 pl-3"><span class="sr-only">{{ t('Reorder') }}</span></th>
                                <th scope="col" class="px-3 py-2.5 font-medium">{{ t('Name') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">{{ t('Size') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">{{ t('SKU') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">{{ t('Vintage') }}</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">{{ t('On hand') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">{{ t('Cover') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">{{ t('Level') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">{{ t('Flags') }}</th>
                            </tr>
                        </thead>

                        <tbody v-for="group in groups" :key="group.label" class="divide-y divide-border">
                            <!-- Group band: name, count and the page subtotal -->
                            <tr class="bg-muted/40">
                                <td class="py-2 pl-3"><GripHandle /></td>
                                <th colspan="4" scope="colgroup" class="px-3 py-2 text-left">
                                    <span class="inline-flex items-center gap-1.5">
                                        <ChevronDown class="size-3 text-muted-foreground" :stroke-width="2" />
                                        <span class="text-xs font-semibold text-foreground">{{ group.label }}</span>
                                        <span class="text-xs text-muted-foreground">({{ group.count }})</span>
                                    </span>
                                </th>
                                <td colspan="4" class="px-3 py-2 text-right text-xs text-muted-foreground">
                                    {{ qty(String(group.onHand)) }} {{ t('on hand') }}<template v-if="group.currency">
                                        · {{ formatMoney(group.value, group.currency, page.props.locale) }}</template
                                    >
                                </td>
                            </tr>

                            <template v-for="band in group.bands" :key="`${group.label}-${band.sub}`">
                                <!-- Subcategory band -->
                                <tr v-if="band.sub" class="bg-muted/20">
                                    <td class="py-1.5 pl-3"><GripHandle /></td>
                                    <th
                                        colspan="8"
                                        scope="colgroup"
                                        class="px-3 py-1.5 text-left text-3xs font-medium tracking-[0.08em] text-muted-foreground uppercase"
                                    >
                                        {{ band.sub }}
                                    </th>
                                </tr>

                                <tr
                                    v-for="item in band.rows"
                                    :key="item.id"
                                    class="cursor-pointer hover:bg-accent/50"
                                    @click="viewing = item"
                                >
                                    <td class="py-3 pl-3"><GripHandle /></td>
                                    <td class="px-3 py-3">
                                        <div class="flex items-center gap-3">
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
                                            <span class="font-medium">{{ item.name }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-muted-foreground">{{ item.unit_size ?? '—' }}</td>
                                    <td class="px-3 py-3 text-muted-foreground">{{ item.sku }}</td>
                                    <td class="px-3 py-3 text-muted-foreground">{{ item.vintage ?? '—' }}</td>
                                    <td class="px-3 py-3 text-right font-semibold tabular-nums">
                                        {{ qty(item.current_stock) }}
                                    </td>
                                    <td class="px-3 py-3 text-muted-foreground">{{ cover(item) }}</td>
                                    <td class="px-3 py-3">
                                        <LevelBar :value="item.current_stock" :min="item.min_stock">
                                            <template v-if="item.min_stock">
                                                {{ t(':stock of :min min', { stock: qty(item.current_stock), min: qty(item.min_stock) }) }}
                                            </template>
                                            <template v-else>{{ t('No min stock set') }}</template>
                                        </LevelBar>
                                    </td>
                                    <td class="px-3 py-3">
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
                            </template>
                        </tbody>

                        <tbody v-if="!groups.length">
                            <tr>
                                <td colspan="9" class="px-4 py-10 text-center text-muted-foreground">
                                    {{ t('No items match these filters.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="!bulkEditing && items.meta.total > 0" class="flex items-center justify-end">
                <Pagination
                    :meta="items.meta"
                    @update:page="(pageNumber) => reload({ page: pageNumber })"
                    @update:per-page="setPerPage"
                />
            </div>
        </div>

        <NewItemPanel :open="newItemOpen" @close="newItemOpen = false" />
        <ItemViewPanel :item="viewing" :movements="itemMovements ?? []" @close="viewing = null" />
    </AppLayout>
</template>
