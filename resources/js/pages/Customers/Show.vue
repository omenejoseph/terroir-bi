<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ChevronRight, Link2, PencilLine, Plus, Search, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CustomerAttentionBand from '@/components/customers/CustomerAttentionBand.vue';
import CustomerFormPanel from '@/components/customers/CustomerFormPanel.vue';
import CustomerPriceDialog from '@/components/customers/CustomerPriceDialog.vue';
import OrderLinkDialog from '@/components/customers/OrderLinkDialog.vue';
import OrderRhythm from '@/components/customers/OrderRhythm.vue';
import PipelineCard from '@/components/orders/PipelineCard.vue';
import BarChart from '@/components/ui/BarChart.vue';
import DateRangePicker from '@/components/ui/DateRangePicker.vue';
import Button from '@/components/ui/Button.vue';
import Pagination from '@/components/ui/Pagination.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import StackedBar from '@/components/ui/StackedBar.vue';
import StatCard from '@/components/ui/StatCard.vue';
import StatusChips from '@/components/ui/StatusChips.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber } from '@/lib/money';
import type {
    AttentionCard,
    Customer,
    CustomerInsights,
    CustomerOrderAnalytics,
    CustomerPriceRow,
    CustomerPricing,
    CustomerProducts,
    CustomerRhythm,
} from '@/types/customers';
import type { MoneyValue } from '@/types/inventory';
import type { Order, OrderPipeline, OrderStatusCounts, OrderStatusKey, ProductOption } from '@/types/orders';
import type { Paginated, SharedProps } from '@/types';
import type { DateRange, TabItem } from '@/types/ui';

/**
 * One customer (Figma 231:9336), with the four tabs the design gives them.
 *
 * Only the Overview's material loads with the page; Pricing, Order History and
 * Komisija are `Inertia::optional` props fetched when their tab is opened, so
 * looking at a customer does not pay for a full catalogue price resolution and
 * a consignment rollup nobody asked for.
 *
 * `231:9592` shows an earlier iteration of this page behind the Edit drawer —
 * "Realized sales", "YOY Growth", "Annual projection" cards. `231:9336` is the
 * later design and is what this page follows; the earlier frame contributes
 * only its drawer.
 */
const props = defineProps<{
    customer: Customer;
    tab: string;
    rhythm: CustomerRhythm;
    attention: AttentionCard[];
    /** Null when the viewer may not see financials. */
    insights: CustomerInsights | null;
    orderAnalytics: CustomerOrderAnalytics | null;
    products: CustomerProducts;
    productRange: { preset: string; from: string | null; to: string | null };
    pricing?: CustomerPricing;
    /** The full sellable catalogue for "Add price" — undefined until that dialog opens and asks for it. */
    pricingCatalog?: ProductOption[];
    orderHistory?: Paginated<Order>;
    /** Null when the viewer may not see financials — the card is money-denominated. */
    orderPipeline?: OrderPipeline | null;
    orderStatusCounts?: OrderStatusCounts;
    /** '3m' | '6m' | 'ytd' | 'lifetime' — the Order History tab's own window. */
    orderHistoryRange: string;
    orderHistoryFilters: { search: string | null; status: string | null };
    /** Null when the viewer may not see financials. */
    orderHistoryTotal?: MoneyValue | null;
    consignment?: Record<string, unknown>;
    /** Undefined until the Order link dialog opens and asks for it. */
    orderToken?: string | null;
}>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { can } = useAuth();
const { t } = useTranslations();

const editOpen = ref(false);
const confirmingDelete = ref(false);
const orderLinkOpen = ref(false);

const money = (m: MoneyValue | null | undefined): string =>
    m ? formatMoney(m.minor, m.currency, locale.value) : '—';

const subtitle = computed(() => {
    const rebate = Number.parseFloat(props.customer.effective_rebate_percent);
    const parts = [props.customer.contact_name];

    if (Number.isFinite(rebate) && rebate > 0) {
        parts.push(t(':rebate% rebate', { rebate: Number(rebate.toFixed(2)) }));
    }

    return parts.filter(Boolean).join(' · ');
});

const TABS = computed<TabItem[]>(() => [
    { value: 'overview', label: t('Overview') },
    { value: 'pricing', label: t('Pricing (:count)', { count: props.pricing?.override_count ?? 0 }) },
    { value: 'orders', label: t('Order History (:count)', { count: props.orderHistory?.meta.total ?? 0 }) },
    { value: 'consignment', label: t('Consignment') },
]);

/** Switching tabs asks the server for only that tab's data. */
function selectTab(value: string): void {
    const only: Record<string, string[]> = {
        overview: [],
        pricing: ['pricing'],
        orders: ['orderHistory', 'orderPipeline', 'orderStatusCounts'],
        consignment: ['consignment'],
    };

    router.get(
        `/customers/${props.customer.id}`,
        { tab: value },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['tab', ...(only[value] ?? [])],
        },
    );
}

/**
 * Reloading the Order History tab's table, pipeline and chips together — a
 * search, a status chip, a period change or a page turn can each move all
 * three (the pipeline and chip counts are scoped to the same filtered set the
 * table shows), so all three are always refetched together rather than
 * risking the card and the table disagreeing about what they describe.
 */
function reloadOrderHistory(overrides: Record<string, unknown>): void {
    router.get(
        `/customers/${props.customer.id}`,
        {
            tab: 'orders',
            order_search: props.orderHistoryFilters.search ?? undefined,
            order_status: props.orderHistoryFilters.status ?? undefined,
            order_period: props.orderHistoryRange,
            // Carried forward so moving between pages does not silently reset
            // the page size back to the server default.
            per_page: props.orderHistory?.meta.per_page,
            ...overrides,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['tab', 'orderHistory', 'orderPipeline', 'orderStatusCounts', 'orderHistoryRange', 'orderHistoryFilters', 'orderHistoryTotal'],
        },
    );
}

function goToOrderHistoryPage(page: number): void {
    reloadOrderHistory({ page });
}

function setOrderHistoryPerPage(perPage: number): void {
    reloadOrderHistory({ per_page: perPage, page: 1 });
}

/** Debounced server-side search, same as Orders/Index's toolbar. */
const orderSearch = ref(props.orderHistoryFilters.search ?? '');
let orderSearchTimer: ReturnType<typeof setTimeout> | undefined;

watch(orderSearch, (value) => {
    clearTimeout(orderSearchTimer);
    orderSearchTimer = setTimeout(() => reloadOrderHistory({ order_search: value || undefined, page: 1 }), 300);
});

function selectOrderStatus(key: string | null): void {
    reloadOrderHistory({ order_status: key ?? undefined, page: 1 });
}

const ORDER_HISTORY_RANGE_TABS = computed<TabItem[]>(() => [
    { value: '3m', label: t('Last 3 months') },
    { value: '6m', label: t('Last 6 months') },
    { value: 'ytd', label: t('Year to date') },
    { value: 'lifetime', label: t('Lifetime') },
]);

function selectOrderHistoryRange(preset: string): void {
    reloadOrderHistory({ order_period: preset, page: 1 });
}

/*
  The tab counts live in the labels, so Pricing and Order History are fetched
  once on arrival even when their tab is closed — two cheap counts against a
  tab strip that would otherwise lie about being empty. A direct link straight
  into the Order History tab also needs its pipeline and status chips, since
  selectTab() — the only other place that asks for them — never runs for a
  tab the server already opened on.
*/
watch(
    () => props.customer.id,
    () => {
        const only: string[] = [];

        if (props.pricing === undefined) only.push('pricing');
        if (props.orderHistory === undefined) only.push('orderHistory');

        if (props.tab === 'orders') {
            if (props.orderPipeline === undefined) only.push('orderPipeline');
            if (props.orderStatusCounts === undefined) only.push('orderStatusCounts');
            if (props.orderHistoryTotal === undefined) only.push('orderHistoryTotal');
        }

        if (only.length > 0) router.reload({ only });
    },
    { immediate: true },
);

function act(action: AttentionCard['action']): void {
    if (action === 'contact') {
        // `only` keeps this a partial reload — a plain post would be a full
        // visit, and a full visit never resolves Optional props, silently
        // resetting Pricing/Order History/Komisija/the order link back to
        // unloaded if any had already been fetched this visit.
        router.post(
            `/customers/${props.customer.id}/contacted`,
            { contacted: true },
            { preserveScroll: true, only: ['attention'] },
        );

        return;
    }

    if (action === 'pricing') selectTab('pricing');
}

function destroy(): void {
    router.delete(`/customers/${props.customer.id}`);
}

/* ---- Overview figures ------------------------------------------------ */

/**
 * The design's "Where the money goes" splits revenue into gross profit and
 * COGS. Per-customer COGS is not computed anywhere — it would need cost
 * snapshots summed across a customer's lines, which no query does — so this
 * card splits revenue along an axis the data DOES track: direct sales against
 * goods sold on consignment. Same shape, a claim the numbers support.
 */
const moneySplit = computed(() => {
    const insights = props.insights;
    if (insights === null) return null;

    const revenue = insights.total_spend.minor;
    if (revenue <= 0) return null;

    const consignment = insights.consignment_revenue.minor;

    return {
        revenue,
        currency: insights.total_spend.currency,
        segments: [
            { label: t('Direct sales'), value: Math.max(0, revenue - consignment) },
            { label: t('Consignment'), value: consignment },
        ].filter((s) => s.value > 0),
    };
});

const revenueTrend = computed(() =>
    (props.orderAnalytics?.monthly_revenue ?? []).map((point) => ({
        label: new Date(`${point.month}-01`).toLocaleDateString(locale.value, {
            month: 'short',
            year: '2-digit',
        }),
        values: [point.revenue.minor / 100],
    })),
);

/**
 * "Next 3 months" (Figma 231:9336). The forecast compares each month to the
 * same month a year earlier, so it stays the design's own empty state until a
 * full year of history exists — never a projection built on nothing to
 * compare against.
 */
const forecast = computed(() => {
    const analytics = props.orderAnalytics;
    if (analytics === null || analytics.first_order_date === null) return null;

    const first = new Date(analytics.first_order_date);
    const oneYearOn = new Date(first);
    oneYearOn.setFullYear(oneYearOn.getFullYear() + 1);

    if (oneYearOn.getTime() > Date.now()) {
        return {
            ready: false as const,
            availableFrom: oneYearOn.toLocaleDateString(locale.value, { month: 'short', year: 'numeric' }),
        };
    }

    return {
        ready: true as const,
        months: analytics.expected_next_3m.map((point) => ({
            label: new Date(`${point.month}-01`).toLocaleDateString(locale.value, {
                month: 'short',
                year: '2-digit',
            }),
            values: [point.expected.minor / 100],
        })),
    };
});

/**
 * "Price ladder" (Figma 231:9336): revenue per bottle by product subcategory,
 * ranked highest first. Built entirely from CustomerProductsQuery's rows,
 * which already carry revenue and units per product — no new backend data.
 *
 * The design's four buckets (White / Rosé / Red / "Red Reserve") are example
 * content, not a real taxonomy: `InventoryItem.subcategory` is free text, and
 * this tenant's schema has no "reserve" tier. Buckets are whatever
 * subcategories this customer has actually bought, which is what the data can
 * honestly support.
 */
const priceLadder = computed(() => {
    const buckets = new Map<string, { units: number; revenueMinor: number; currency: string }>();

    for (const row of props.products.rows) {
        const key = row.subcategory ?? row.group ?? t('Other');
        const bucket = buckets.get(key) ?? { units: 0, revenueMinor: 0, currency: row.revenue.currency };
        bucket.units += row.units;
        bucket.revenueMinor += row.revenue.minor;
        buckets.set(key, bucket);
    }

    const rows = [...buckets.entries()]
        .map(([key, bucket]) => ({
            key,
            label: subcategoryLabel(key),
            units: bucket.units,
            pricePerBottle: bucket.units > 0 ? bucket.revenueMinor / bucket.units : 0,
            currency: bucket.currency,
        }))
        .filter((row) => row.units > 0)
        .sort((a, b) => b.pricePerBottle - a.pricePerBottle);

    const max = rows.reduce((m, row) => Math.max(m, row.pricePerBottle), 0);
    const totalUnits = props.products.total_units;

    return {
        rows: rows.map((row) => ({
            ...row,
            barPct: max > 0 ? (row.pricePerBottle / max) * 100 : 0,
            sharePct: totalUnits > 0 ? Math.round((row.units / totalUnits) * 100) : 0,
        })),
        /** "{top} earns {ratio}× {bottom}" — only claimed when there are two buckets to compare. */
        ratio:
            rows.length >= 2 && rows[rows.length - 1]!.pricePerBottle > 0
                ? {
                      top: rows[0]!.label,
                      bottom: rows[rows.length - 1]!.label,
                      multiple: rows[0]!.pricePerBottle / rows[rows.length - 1]!.pricePerBottle,
                  }
                : null,
    };
});

/** "RED_RESERVE" -> "Red reserve"; whatever free text is on file, titled. */
function subcategoryLabel(value: string): string {
    return value
        .toLowerCase()
        .split(/[\s_-]+/)
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

/**
 * "Concentration" (Figma 231:9336): the customer's top products by volume,
 * with how much of their bottles the top 3 account for. CustomerProductsQuery
 * already ranks `rows` by units descending — this just takes the head of it.
 */
const concentration = computed(() => {
    const rows = props.products.rows;
    const top = rows.slice(0, 6);
    const max = top.reduce((m, row) => Math.max(m, row.units), 0);
    const totalUnits = props.products.total_units;
    const top3Units = rows.slice(0, 3).reduce((sum, row) => sum + row.units, 0);

    return {
        rows: top.map((row) => ({ ...row, barPct: max > 0 ? (row.units / max) * 100 : 0 })),
        top3SharePct: totalUnits > 0 ? Math.round((top3Units / totalUnits) * 100) : 0,
    };
});

/*
  "Products bought" has its own window (Figma 231:9336). It reloads only that
  card's data, so changing the range does not re-run the rhythm strip and the
  revenue trend, which do not depend on it.
*/
const PRODUCT_RANGE_TABS = computed<TabItem[]>(() => [
    { value: 'lifetime', label: t('Lifetime') },
    { value: 'year', label: t('This year') },
    { value: 'month', label: t('This month') },
]);

const productCustomRange = computed<DateRange>(() => ({
    from: props.productRange.preset === 'custom' ? props.productRange.from : null,
    to: props.productRange.preset === 'custom' ? props.productRange.to : null,
}));

function reloadProducts(params: Record<string, string | undefined>): void {
    router.get(
        `/customers/${props.customer.id}`,
        { tab: props.tab, ...params },
        { preserveState: true, preserveScroll: true, replace: true, only: ['products', 'productRange'] },
    );
}

function selectProductRange(preset: string): void {
    reloadProducts({ products_range: preset, products_from: undefined, products_to: undefined });
}

function selectProductCustomRange(range: DateRange): void {
    reloadProducts({
        products_range: range.from === null ? 'lifetime' : 'custom',
        products_from: range.from ?? undefined,
        products_to: range.to ?? undefined,
    });
}

/**
 * Products bought, grouped the way the design groups them: by product group,
 * then by subcategory within it (231:9336 shows "Wine" over "White" / "Rosé" /
 * "Red" as two nesting levels, not one flat list under "Wine").
 */
const productGroups = computed(() => {
    const byGroup = new Map<string, CustomerProducts['rows']>();

    for (const row of props.products.rows) {
        const key = row.group ?? t('Ungrouped');
        byGroup.set(key, [...(byGroup.get(key) ?? []), row]);
    }

    return [...byGroup.entries()].map(([label, rows]) => {
        const bySubcategory = new Map<string, CustomerProducts['rows']>();

        for (const row of rows) {
            const key = row.subcategory ?? '';
            bySubcategory.set(key, [...(bySubcategory.get(key) ?? []), row]);
        }

        return {
            label,
            units: rows.reduce((sum, r) => sum + r.units, 0),
            productCount: rows.length,
            subcategories: [...bySubcategory.entries()].map(([key, subRows]) => ({
                key,
                label: key === '' ? null : subcategoryLabel(key),
                rows: subRows,
            })),
        };
    });
});

function shortDate(iso: string | null): string {
    if (iso === null) return '—';

    return new Date(iso).toLocaleDateString(locale.value, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

const ORDER_STATUS_LABEL = computed<Record<string, string>>(() => ({
    RECEIVED: t('Received'),
    IN_PROCESS: t('In Process'),
    READY_TO_SHIP: t('Ready to Ship'),
    SHIPPED: t('Shipped'),
}));

/** Prefers the live chip-row labels; falls back before they have loaded. */
function orderStatusLabel(status: string): string {
    return props.orderStatusCounts?.statuses.find((s) => s.key === status)?.label ?? ORDER_STATUS_LABEL.value[status] ?? status;
}

/** Swatch tone per status, matching Orders/Index's workflow ordering. */
const STATUS_TONE: Record<string, string> = {
    RECEIVED: 'border border-foreground',
    IN_PROCESS: 'bg-foreground',
    READY_TO_SHIP: 'bg-foreground',
    SHIPPED: 'bg-muted-foreground/40',
};

/**
 * "Flag" (Figma 361:2157): a workflow hint next to the status badge — what an
 * order at this stage needs next. Static per status; no new backend data
 * describes anything richer, and the design doesn't ask for anything richer
 * (Received and Shipped rows carry no flag at all).
 */
const ORDER_FLAG = computed<Partial<Record<OrderStatusKey, string>>>(() => ({
    IN_PROCESS: t('Awaiting fulfilment'),
    READY_TO_SHIP: t('Ready — schedule delivery'),
}));

function orderFlag(order: Order): string | null {
    return ORDER_FLAG.value[order.status] ?? null;
}

/** The design shows three lines per row, then "+ n more" — same as Orders/Index. */
const PREVIEW_LINES = 3;

function previewLines(order: Order): Order['items'] {
    return order.items.slice(0, PREVIEW_LINES);
}

function remainingLines(order: Order): number {
    return Math.max(0, order.items.length - PREVIEW_LINES);
}

function lineSummary(order: Order): string {
    const bottles = order.items.reduce((sum, item) => sum + item.quantity, 0);

    return t(':lines lines · :units units', { lines: order.items.length, units: formatNumber(bottles, locale.value) });
}

const orderHistoryShowing = computed(() => {
    if (!props.orderHistory) return '';

    const { current_page: current, per_page: per, total } = props.orderHistory.meta;
    const first = total === 0 ? 0 : (current - 1) * per + 1;

    return t('Showing :from–:to of :total orders', {
        from: first,
        to: Math.min(current * per, total),
        total: formatNumber(total, locale.value),
    });
});

/* ---- Pricing tab: add / edit / remove a customer's own override ------ */

const priceDialogOpen = ref(false);
const editingPrice = ref<CustomerPriceRow | null>(null);

function openAddPrice(): void {
    editingPrice.value = null;
    priceDialogOpen.value = true;
}

function openEditPrice(row: CustomerPriceRow): void {
    editingPrice.value = row;
    priceDialogOpen.value = true;
}

function removePrice(row: CustomerPriceRow): void {
    if (!confirm(t('Remove the price set for :name? They will pay the tier/list price instead.', { name: row.name }))) return;

    router.delete(`/customers/${props.customer.id}/prices/${row.inventory_item_id}`, {
        preserveScroll: true,
        only: ['pricing'],
    });
}
</script>

<template>
    <AppLayout :title="customer.company_name">
        <div class="space-y-5">
            <Link
                href="/customers"
                class="inline-flex items-center gap-2 text-xs text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft class="size-3.5" :stroke-width="1.5" />
                {{ t('Customers') }}
            </Link>

            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="truncate text-xl font-semibold text-foreground">{{ customer.company_name }}</h2>
                    <p v-if="subtitle" class="mt-1 text-xs text-muted-foreground">{{ subtitle }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <Button v-if="can('customers.manage')" variant="outline" size="sm" @click="editOpen = true">
                        <PencilLine class="size-3.5" :stroke-width="1.5" />
                        {{ t('Edit') }}
                    </Button>
                    <Button
                        v-if="can('customers.delete')"
                        variant="outline"
                        size="sm"
                        class="border-destructive/40 text-destructive hover:bg-destructive/10"
                        @click="confirmingDelete ? destroy() : (confirmingDelete = true)"
                    >
                        <Trash2 class="size-3.5" :stroke-width="1.5" />
                        {{ confirmingDelete ? t('Confirm delete') : t('Delete') }}
                    </Button>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <Tabs :items="TABS" :current="tab" @select="selectTab" />
                <Button v-if="can('customers.tokens')" variant="outline" size="sm" @click="orderLinkOpen = true">
                    <Link2 class="size-3.5" :stroke-width="1.5" />
                    {{ t('Generate Order Link') }}
                </Button>
            </div>

            <!-- ===================== Overview ===================== -->
            <template v-if="tab === 'overview'">
                <CustomerAttentionBand
                    :cards="attention"
                    :customer-id="customer.id"
                    :can-manage="can('customers.manage')"
                    @act="act"
                />

                <section v-if="insights" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        :label="t('Lifetime revenue')"
                        :value="money(insights.total_spend)"
                        :hint="
                            t(':count orders · avg :avg', {
                                count: formatNumber(insights.order_count, locale),
                                avg: money(insights.avg_order_value),
                            })
                        "
                    />
                    <StatCard
                        :label="t('Bottles sold')"
                        :value="formatNumber(products.total_units, locale)"
                        :hint="t('across :count products', { count: formatNumber(products.product_count, locale) })"
                    />
                    <StatCard
                        :label="t('Consignment revenue')"
                        :value="money(insights.consignment_revenue)"
                        :hint="t('goods sold on komisija')"
                    />
                    <StatCard
                        :label="t('Days since last order')"
                        :value="rhythm.days_since_last === null ? '—' : String(rhythm.days_since_last)"
                        :hint="
                            rhythm.median_gap_days === null
                                ? t('no rhythm established yet')
                                : t('typical gap is :days days', { days: rhythm.median_gap_days })
                        "
                        :alert="rhythm.overdue"
                    />
                </section>

                <p v-else class="border border-border bg-card p-4 text-xs text-muted-foreground">
                    {{ t('Revenue figures are hidden — they need the financials capability.') }}
                </p>

                <OrderRhythm :rhythm="rhythm" />

                <!-- Revenue trend · Next 3 months (Figma 231:9336's top row) -->
                <div class="grid gap-4 xl:grid-cols-3">
                    <div v-if="orderAnalytics" class="border border-border bg-card xl:col-span-2">
                        <div class="flex items-center justify-between gap-3 border-b border-border px-6 py-4">
                            <SectionHeader
                                :title="t('Revenue trend · 12 months')"
                                :description="
                                    orderAnalytics.last_order_date
                                        ? t('Last order :date', { date: shortDate(orderAnalytics.last_order_date) })
                                        : t('No orders recorded yet')
                                "
                            />
                            <!-- @todo Change range. The design draws this control but
                                 doesn't specify what alternate window it opens; the
                                 chart's own 12-month window matches the header. -->
                            <button type="button" class="shrink-0 text-xs text-muted-foreground hover:text-foreground">
                                {{ t('Change range') }}
                            </button>
                        </div>
                        <div class="p-6">
                            <BarChart :points="revenueTrend" :height="220" />
                        </div>
                    </div>

                    <div v-if="orderAnalytics" class="flex flex-col border border-border bg-card">
                        <div class="border-b border-border px-6 py-4">
                            <SectionHeader :title="t('Next 3 months')" />
                        </div>

                        <div v-if="forecast?.ready" class="flex-1 p-6">
                            <BarChart :points="forecast.months" :height="160" />
                        </div>

                        <div v-else class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
                            <span class="size-6 bg-muted" aria-hidden="true" />
                            <p class="text-sm font-semibold">{{ t('No forecast yet') }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        "Forecasting compares each month to the same month last year, and this customer doesn't have a full year of orders yet.",
                                    )
                                }}
                            </p>
                            <span
                                v-if="forecast"
                                class="mt-1 inline-flex items-center border border-dashed border-border px-2.5 py-1 text-xs text-muted-foreground"
                            >
                                {{ t('Available from :date', { date: forecast.availableFrom }) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Where the money goes · Price ladder · Concentration (Figma 231:9336's second row) -->
                <div class="grid gap-4 xl:grid-cols-3">
                    <div v-if="moneySplit" class="border border-border bg-card">
                        <div class="border-b border-border px-6 py-4">
                            <SectionHeader
                                :title="t('Where the money comes from')"
                                :description="t('Direct sales against goods sold on komisija')"
                            />
                        </div>
                        <div class="flex flex-col gap-4 p-6">
                            <p class="text-3xl font-semibold tabular-nums">
                                {{ money({ minor: moneySplit.revenue, currency: moneySplit.currency, formatted: '' }) }}
                            </p>
                            <StackedBar
                                :segments="
                                    moneySplit.segments.map((s) => ({
                                        label: s.label,
                                        value: s.value,
                                        caption: money({
                                            minor: s.value,
                                            currency: moneySplit!.currency,
                                            formatted: '',
                                        }),
                                    }))
                                "
                            />
                        </div>
                    </div>

                    <div v-if="priceLadder.rows.length > 0" class="border border-border bg-card">
                        <div class="flex items-start justify-between gap-3 border-b border-border px-6 py-4">
                            <SectionHeader
                                :title="t('Price ladder')"
                                :description="
                                    priceLadder.ratio
                                        ? t('Revenue per bottle · :top earns :multiple× :bottom', {
                                              top: priceLadder.ratio.top,
                                              multiple: priceLadder.ratio.multiple.toFixed(1),
                                              bottom: priceLadder.ratio.bottom,
                                          })
                                        : t('Revenue per bottle')
                                "
                            />
                            <!-- @todo Suggest upsell. No suggestion engine exists yet
                                 to back this — the ladder itself is real. -->
                            <button type="button" class="shrink-0 text-xs text-muted-foreground hover:text-foreground">
                                {{ t('Suggest upsell') }}
                            </button>
                        </div>
                        <ul class="space-y-4 p-6">
                            <li v-for="row in priceLadder.rows" :key="row.key" class="space-y-1.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="text-sm font-medium">{{ row.label }}</span>
                                    <span class="text-xs text-muted-foreground tabular-nums">
                                        {{ formatNumber(row.units, locale) }} btl · {{ row.sharePct }}%
                                    </span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <ProgressBar class="flex-1" :value="row.barPct" :label="row.label" />
                                    <span class="w-16 shrink-0 text-right text-sm font-semibold tabular-nums">
                                        {{ money({ minor: Math.round(row.pricePerBottle), currency: row.currency, formatted: '' }) }}
                                    </span>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div v-if="concentration.rows.length > 0" class="border border-border bg-card">
                        <div class="border-b border-border px-6 py-4">
                            <SectionHeader :title="t('Concentration')" />
                        </div>
                        <ul class="space-y-4 p-6">
                            <li
                                v-for="row in concentration.rows"
                                :key="row.inventory_item_id"
                                class="space-y-1.5"
                            >
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="min-w-0 truncate text-sm font-medium">{{ row.name }}</span>
                                    <span class="shrink-0 text-sm tabular-nums">
                                        {{ formatNumber(row.units, locale) }} btl
                                    </span>
                                </div>
                                <ProgressBar :value="row.barPct" :label="row.name" />
                            </li>
                        </ul>
                        <p class="border-t border-border px-6 py-3 text-xs text-muted-foreground">
                            {{
                                t('Top 3 = :pct% of bottles · only :count SKUs bought', {
                                    pct: concentration.top3SharePct,
                                    count: formatNumber(products.product_count, locale),
                                })
                            }}
                        </p>
                    </div>
                </div>

                <div class="border border-border bg-card">
                    <div class="flex flex-col gap-3 border-b border-border px-6 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <SectionHeader
                                :title="t('Products bought')"
                                :description="
                                    t(':units bottles · :count products', {
                                        units: formatNumber(products.total_units, locale),
                                        count: formatNumber(products.product_count, locale),
                                    })
                                "
                            />
                            <!-- @todo Suggest order. No suggestion engine exists yet. -->
                            <button type="button" class="shrink-0 text-xs text-muted-foreground hover:text-foreground">
                                {{ t('Suggest order') }}
                            </button>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <Tabs
                                    :items="PRODUCT_RANGE_TABS"
                                    :current="productRange.preset === 'custom' ? '' : productRange.preset"
                                    @select="selectProductRange"
                                />
                                <DateRangePicker
                                    :model-value="productCustomRange"
                                    :label="t('Custom')"
                                    @update:model-value="selectProductCustomRange"
                                />
                            </div>
                            <span class="text-xs text-muted-foreground">{{ t('Grouped by category') }}</span>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[52rem] text-xs">
                            <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-6 py-2.5 font-medium">{{ t('Product') }}</th>
                                    <th scope="col" class="px-6 py-2.5 text-right font-medium">{{ t('Bottles') }}</th>
                                    <th scope="col" class="px-6 py-2.5 font-medium">{{ t('Share') }}</th>
                                    <th scope="col" class="px-6 py-2.5 font-medium">{{ t('Last ordered') }}</th>
                                    <th scope="col" class="px-6 py-2.5 font-medium">{{ t('Signal') }}</th>
                                </tr>
                            </thead>

                            <tbody>
                                <template v-for="group in productGroups" :key="group.label">
                                    <tr class="bg-muted/20">
                                        <th
                                            colspan="4"
                                            scope="colgroup"
                                            class="px-6 py-2 text-left text-xs font-semibold"
                                        >
                                            · {{ group.label }}
                                        </th>
                                        <td class="px-6 py-2 text-right text-muted-foreground tabular-nums">
                                            {{
                                                t(':units bottles · :count products', {
                                                    units: formatNumber(group.units, locale),
                                                    count: formatNumber(group.productCount, locale),
                                                })
                                            }}
                                        </td>
                                    </tr>

                                    <template v-for="subcategory in group.subcategories" :key="subcategory.key">
                                        <tr v-if="subcategory.label" class="border-b border-border">
                                            <th
                                                colspan="5"
                                                scope="colgroup"
                                                class="px-6 py-1.5 text-left text-2xs font-medium tracking-wide text-muted-foreground uppercase"
                                            >
                                                {{ subcategory.label }}
                                            </th>
                                        </tr>

                                        <tr
                                            v-for="row in subcategory.rows"
                                            :key="row.inventory_item_id"
                                            class="border-b border-border last:border-b-0"
                                        >
                                            <td class="px-6 py-3">
                                                <Link
                                                    :href="`/inventory/${row.inventory_item_id}`"
                                                    class="font-medium hover:underline"
                                                >
                                                    {{ row.name }}
                                                </Link>
                                                <span class="mt-0.5 block text-muted-foreground">
                                                    {{ [row.vintage, row.unit_size].filter(Boolean).join(' · ') || '—' }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-3 text-right font-semibold tabular-nums">
                                                {{ formatNumber(row.units, locale) }}
                                            </td>
                                            <td class="px-6 py-3">
                                                <span class="block h-2 w-32 bg-muted" aria-hidden="true">
                                                    <span
                                                        class="block h-full bg-foreground"
                                                        :style="{ width: `${row.share * 100}%` }"
                                                    />
                                                </span>
                                                <span class="mt-1 block text-muted-foreground tabular-nums">
                                                    {{ Math.round(row.share * 100) }} %
                                                </span>
                                            </td>
                                            <td class="px-6 py-3 text-muted-foreground">
                                                {{ shortDate(row.last_ordered) }}
                                            </td>
                                            <td class="px-6 py-3 text-muted-foreground">{{ row.signal ?? '—' }}</td>
                                        </tr>
                                    </template>
                                </template>

                                <tr v-if="products.rows.length === 0">
                                    <td colspan="5" class="px-6 py-12 text-center text-muted-foreground">
                                        <!-- A filtered empty is not the same as an empty
                                             customer, and saying so sends people to fix
                                             the wrong thing. -->
                                        {{
                                            productRange.preset === 'lifetime'
                                                ? t('This customer has not ordered anything yet.')
                                                : t('Nothing bought in this range.')
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <!-- ===================== Pricing ===================== -->
            <template v-else-if="tab === 'pricing'">
                <div class="border border-border bg-card">
                    <div class="flex items-start justify-between gap-3 border-b border-border px-6 py-4">
                        <SectionHeader
                            :title="t('Prices for this customer')"
                            :description="
                                customer.pricing_tier
                                    ? t('Tier :tier · :rebate% rebate applies to everything else', {
                                          tier: customer.pricing_tier.name,
                                          rebate: customer.effective_rebate_percent,
                                      })
                                    : t('No tier · :rebate% rebate applies to everything else', {
                                          rebate: customer.effective_rebate_percent,
                                      })
                            "
                        />
                        <Button v-if="can('pricing.manage')" size="sm" @click="openAddPrice">
                            <Plus class="size-3.5" :stroke-width="1.5" />
                            {{ t('Add price') }}
                        </Button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[44rem] text-xs">
                            <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-6 py-2.5 font-medium">{{ t('Product') }}</th>
                                    <th scope="col" class="px-6 py-2.5 text-right font-medium">{{ t('List price') }}</th>
                                    <th scope="col" class="px-6 py-2.5 text-right font-medium">{{ t('They pay') }}</th>
                                    <th v-if="can('pricing.manage')" scope="col" class="px-6 py-2.5 font-medium">
                                        <span class="sr-only">{{ t('Actions') }}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in pricing?.rows ?? []"
                                    :key="row.inventory_item_id"
                                    class="border-b border-border last:border-b-0"
                                >
                                    <td class="px-6 py-3">
                                        <span class="font-medium">{{ row.name }}</span>
                                        <span class="mt-0.5 block text-muted-foreground">
                                            {{ [row.sku, row.vintage].filter(Boolean).join(' · ') }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-right text-muted-foreground tabular-nums">
                                        {{ money(row.list_price) }}
                                    </td>
                                    <td class="px-6 py-3 text-right font-semibold tabular-nums">
                                        {{ money(row.price) }}
                                    </td>
                                    <td v-if="can('pricing.manage')" class="px-6 py-3">
                                        <div class="flex items-center justify-end gap-1">
                                            <button
                                                type="button"
                                                class="p-1.5 text-muted-foreground transition-colors hover:text-foreground"
                                                :aria-label="t('Edit price for :name', { name: row.name })"
                                                @click="openEditPrice(row)"
                                            >
                                                <PencilLine class="size-3.5" :stroke-width="1.5" />
                                            </button>
                                            <button
                                                type="button"
                                                class="p-1.5 text-muted-foreground transition-colors hover:text-destructive"
                                                :aria-label="t('Remove price for :name', { name: row.name })"
                                                @click="removePrice(row)"
                                            >
                                                <Trash2 class="size-3.5" :stroke-width="1.5" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="(pricing?.rows ?? []).length === 0">
                                    <td :colspan="can('pricing.manage') ? 4 : 3" class="px-6 py-12 text-center text-muted-foreground">
                                        {{
                                            customer.pricing_tier
                                                ? t('No negotiated prices yet — this customer pays the :tier tier price on everything.', {
                                                      tier: customer.pricing_tier.name,
                                                  })
                                                : t('No negotiated prices yet — this customer pays list price on everything.')
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <!-- ================== Order History ================== -->
            <template v-else-if="tab === 'orders'">
                <PipelineCard
                    v-if="orderPipeline"
                    :pipeline="orderPipeline"
                    :current="orderHistoryFilters.status"
                    :filterable="orderStatusCounts?.statuses.map((s) => s.key) ?? []"
                    @select="selectOrderStatus"
                />

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Tabs :items="ORDER_HISTORY_RANGE_TABS" :current="orderHistoryRange" @select="selectOrderHistoryRange" />
                </div>

                <div class="overflow-hidden border border-border bg-card">
                    <!-- Toolbar -->
                    <div class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                        <div class="relative w-full max-w-[280px]">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                                :stroke-width="1.5"
                            />
                            <input
                                v-model="orderSearch"
                                type="search"
                                :placeholder="t('Search order no., SKU…')"
                                :aria-label="t(`Search this customer's orders`)"
                                class="h-[30px] w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            />
                        </div>
                    </div>

                    <div v-if="orderStatusCounts" class="border-b border-border px-4 py-3">
                        <StatusChips
                            :chips="orderStatusCounts.statuses"
                            :current="orderHistoryFilters.status"
                            :total="orderStatusCounts.total"
                            @select="selectOrderStatus"
                        />
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[52rem] text-xs">
                            <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Order') }}</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Items') }}</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-medium tracking-[0.08em] uppercase">{{ t('Total') }}</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Status') }}</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Flag') }}</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-medium tracking-[0.08em] uppercase">{{ t('Date') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="order in orderHistory?.data ?? []"
                                    :key="order.id"
                                    class="cursor-pointer border-b border-border align-top transition-colors last:border-b-0 hover:bg-muted/40"
                                    @click="router.visit(`/orders?order=${order.id}`)"
                                >
                                    <td class="px-4 py-4">
                                        <span class="flex items-center gap-1.5">
                                            <ChevronRight class="size-3 shrink-0 text-muted-foreground" :stroke-width="2" />
                                            <span class="font-semibold text-foreground">#{{ order.order_number }}</span>
                                        </span>
                                    </td>

                                    <td class="px-4 py-4">
                                        <!-- Cases read "N# x Name" — the # marks the unit as
                                             cases rather than bottles. -->
                                        <span v-for="line in previewLines(order)" :key="line.id" class="flex items-baseline gap-2">
                                            <span class="w-10 shrink-0 text-right font-semibold tabular-nums">
                                                {{ formatNumber(line.quantity, locale) }}{{ line.unit_type === 'cases' ? '#' : '×' }}
                                            </span>
                                            <span class="text-foreground">
                                                <template v-if="line.unit_type === 'cases'">x </template>{{ line.name }}
                                            </span>
                                            <span v-if="line.unit_size" class="text-muted-foreground">{{ line.unit_size }}</span>
                                        </span>
                                        <span v-if="remainingLines(order)" class="mt-1 block pl-12 text-muted-foreground">
                                            {{ t('+ :count more', { count: remainingLines(order) }) }}
                                        </span>
                                        <span v-else-if="order.items.length" class="mt-1 block pl-12 text-muted-foreground">
                                            {{ lineSummary(order) }}
                                        </span>
                                    </td>

                                    <td class="px-4 py-4 text-right font-semibold tabular-nums">
                                        {{ money(order.total_amount) }}
                                    </td>

                                    <td class="px-4 py-4">
                                        <span
                                            class="inline-flex items-center gap-2 border border-border px-2 py-1 whitespace-nowrap"
                                        >
                                            <span class="size-2 shrink-0" :class="STATUS_TONE[order.status]" aria-hidden="true" />
                                            {{ orderStatusLabel(order.status) }}
                                        </span>
                                    </td>

                                    <td class="px-4 py-4 text-muted-foreground">{{ orderFlag(order) ?? '—' }}</td>

                                    <td class="px-4 py-4 text-right text-muted-foreground">{{ shortDate(order.created_at) }}</td>
                                </tr>
                                <tr v-if="(orderHistory?.data ?? []).length === 0">
                                    <td colspan="6" class="px-4 py-12 text-center text-muted-foreground">
                                        {{ t('No orders in this window.') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                        <p class="text-xs text-muted-foreground">
                            {{ orderHistoryShowing }}
                            <template v-if="orderHistoryTotal"> · {{ t('Total :amount', { amount: money(orderHistoryTotal) }) }}</template>
                        </p>

                        <Pagination
                            v-if="orderHistory && orderHistory.meta.total > 0"
                            :meta="orderHistory.meta"
                            @update:page="goToOrderHistoryPage"
                            @update:per-page="setOrderHistoryPerPage"
                        />
                    </div>
                </div>
            </template>

            <!-- ==================== Komisija ==================== -->
            <template v-else>
                <div class="border border-border bg-card p-6">
                    <SectionHeader
                        :title="t('Consignment')"
                        :description="t('Goods placed with this customer that are still ours until sold')"
                    />

                    <pre
                        v-if="consignment && Object.keys(consignment).length > 0"
                        class="mt-4 overflow-x-auto text-xs text-muted-foreground"
                        >{{ consignment }}</pre
                    >
                    <p v-else class="mt-4 text-xs text-muted-foreground">
                        {{ t('Nothing on consignment with this customer.') }}
                    </p>

                    <!-- @todo Place / record sale / record return. The service
                         and endpoints exist (CustomerConsignmentService); the
                         design does not specify this tab's layout, so it
                         reports the rollup and stops short of inventing one. -->
                </div>
            </template>
        </div>

        <CustomerFormPanel
            v-if="can('customers.manage')"
            :open="editOpen"
            :customer="customer"
            @close="editOpen = false"
        />

        <OrderLinkDialog
            v-if="can('customers.tokens')"
            :open="orderLinkOpen"
            :customer-id="customer.id"
            :token="orderToken"
            @close="orderLinkOpen = false"
        />

        <CustomerPriceDialog
            v-if="can('pricing.manage')"
            :open="priceDialogOpen"
            :customer-id="customer.id"
            :catalog="pricingCatalog"
            :editing="editingPrice"
            @close="priceDialogOpen = false"
        />
    </AppLayout>
</template>
