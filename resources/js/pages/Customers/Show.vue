<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Link2, PencilLine, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CustomerAttentionBand from '@/components/customers/CustomerAttentionBand.vue';
import CustomerFormPanel from '@/components/customers/CustomerFormPanel.vue';
import OrderRhythm from '@/components/customers/OrderRhythm.vue';
import BarChart from '@/components/ui/BarChart.vue';
import DateRangePicker from '@/components/ui/DateRangePicker.vue';
import Button from '@/components/ui/Button.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import StackedBar from '@/components/ui/StackedBar.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { PRICE_SOURCE_LABELS } from '@/lib/customers';
import { formatMoney, formatNumber } from '@/lib/money';
import type {
    AttentionCard,
    Customer,
    CustomerInsights,
    CustomerOrderAnalytics,
    CustomerPricing,
    CustomerProducts,
    CustomerRhythm,
} from '@/types/customers';
import type { MoneyValue } from '@/types/inventory';
import type { Order } from '@/types/orders';
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
    orderHistory?: Paginated<Order>;
    consignment?: Record<string, unknown>;
}>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { can } = useAuth();

const editOpen = ref(false);
const confirmingDelete = ref(false);

const money = (m: MoneyValue | null | undefined): string =>
    m ? formatMoney(m.minor, m.currency, locale.value) : '—';

const subtitle = computed(() => {
    const rebate = Number.parseFloat(props.customer.effective_rebate_percent);
    const parts = [props.customer.contact_name];

    if (Number.isFinite(rebate) && rebate > 0) parts.push(`${Number(rebate.toFixed(2))}% rebate`);

    return parts.filter(Boolean).join(' · ');
});

const TABS = computed<TabItem[]>(() => [
    { value: 'overview', label: 'Overview' },
    { value: 'pricing', label: `Pricing (${props.pricing?.override_count ?? 0})` },
    { value: 'orders', label: `Order History (${props.orderHistory?.meta.total ?? 0})` },
    { value: 'consignment', label: 'Komisija' },
]);

/** Switching tabs asks the server for only that tab's data. */
function selectTab(value: string): void {
    const only: Record<string, string[]> = {
        overview: [],
        pricing: ['pricing'],
        orders: ['orderHistory'],
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

/*
  The tab counts live in the labels, so Pricing and Order History are fetched
  once on arrival even when their tab is closed — two cheap counts against a
  tab strip that would otherwise lie about being empty.
*/
watch(
    () => props.customer.id,
    () => {
        if (props.pricing === undefined || props.orderHistory === undefined) {
            router.reload({ only: ['pricing', 'orderHistory'] });
        }
    },
    { immediate: true },
);

function act(action: AttentionCard['action']): void {
    if (action === 'contact') {
        router.post(`/customers/${props.customer.id}/contacted`, { contacted: true }, { preserveScroll: true });

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
            { label: 'Direct sales', value: Math.max(0, revenue - consignment) },
            { label: 'Consignment', value: consignment },
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

/*
  "Products bought" has its own window (Figma 231:9336). It reloads only that
  card's data, so changing the range does not re-run the rhythm strip and the
  revenue trend, which do not depend on it.
*/
const PRODUCT_RANGE_TABS: TabItem[] = [
    { value: 'lifetime', label: 'Lifetime' },
    { value: 'year', label: 'This year' },
    { value: 'month', label: 'This month' },
];

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

/** Products bought, grouped the way the design groups them: by product group. */
const productGroups = computed(() => {
    const byGroup = new Map<string, CustomerProducts['rows']>();

    for (const row of props.products.rows) {
        const key = row.group ?? 'Ungrouped';
        byGroup.set(key, [...(byGroup.get(key) ?? []), row]);
    }

    return [...byGroup.entries()].map(([label, rows]) => ({
        label,
        rows,
        units: rows.reduce((sum, r) => sum + r.units, 0),
    }));
});

function shortDate(iso: string | null): string {
    if (iso === null) return '—';

    return new Date(iso).toLocaleDateString(locale.value, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

const orderStatusLabel: Record<string, string> = {
    RECEIVED: 'Received',
    IN_PROCESS: 'In Process',
    READY_TO_SHIP: 'Ready to Ship',
    SHIPPED: 'Shipped',
};
</script>

<template>
    <AppLayout :title="customer.company_name">
        <div class="space-y-5">
            <Link
                href="/customers"
                class="inline-flex items-center gap-2 text-xs text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft class="size-3.5" :stroke-width="1.5" />
                Customers
            </Link>

            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="truncate text-xl font-semibold text-foreground">{{ customer.company_name }}</h2>
                    <p v-if="subtitle" class="mt-1 text-xs text-muted-foreground">{{ subtitle }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <Button v-if="can('customers.manage')" variant="outline" size="sm" @click="editOpen = true">
                        <PencilLine class="size-3.5" :stroke-width="1.5" />
                        Edit
                    </Button>
                    <Button
                        v-if="can('customers.delete')"
                        variant="outline"
                        size="sm"
                        class="border-destructive/40 text-destructive hover:bg-destructive/10"
                        @click="confirmingDelete ? destroy() : (confirmingDelete = true)"
                    >
                        <Trash2 class="size-3.5" :stroke-width="1.5" />
                        {{ confirmingDelete ? 'Confirm delete' : 'Delete' }}
                    </Button>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <Tabs :items="TABS" :current="tab" @select="selectTab" />
                <!-- @todo Generate Order Link. The token endpoints exist on the
                     API behind customers.tokens; this needs its own confirm +
                     copy surface, which the design does not specify. -->
                <Button variant="outline" size="sm">
                    <Link2 class="size-3.5" :stroke-width="1.5" />
                    Generate Order Link
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
                        label="Lifetime revenue"
                        :value="money(insights.total_spend)"
                        :hint="`${formatNumber(insights.order_count, locale)} orders · avg ${money(insights.avg_order_value)}`"
                    />
                    <StatCard
                        label="Bottles sold"
                        :value="formatNumber(products.total_units, locale)"
                        :hint="`across ${formatNumber(products.product_count, locale)} products`"
                    />
                    <StatCard
                        label="Consignment revenue"
                        :value="money(insights.consignment_revenue)"
                        hint="goods sold on komisija"
                    />
                    <StatCard
                        label="Days since last order"
                        :value="rhythm.days_since_last === null ? '—' : String(rhythm.days_since_last)"
                        :hint="
                            rhythm.median_gap_days === null
                                ? 'no rhythm established yet'
                                : `typical gap is ${rhythm.median_gap_days} days`
                        "
                        :alert="rhythm.overdue"
                    />
                </section>

                <p v-else class="border border-border bg-card p-4 text-xs text-muted-foreground">
                    Revenue figures are hidden — they need the financials capability.
                </p>

                <OrderRhythm :rhythm="rhythm" />

                <div class="grid gap-4 xl:grid-cols-3">
                    <div v-if="orderAnalytics" class="border border-border bg-card xl:col-span-2">
                        <div class="border-b border-border px-6 py-4">
                            <SectionHeader
                                title="Revenue trend · 12 months"
                                :description="
                                    orderAnalytics.last_order_date
                                        ? `Last order ${shortDate(orderAnalytics.last_order_date)}`
                                        : 'No orders recorded yet'
                                "
                            />
                        </div>
                        <div class="p-6">
                            <BarChart :points="revenueTrend" :height="220" />
                        </div>
                    </div>

                    <div v-if="moneySplit" class="border border-border bg-card">
                        <div class="border-b border-border px-6 py-4">
                            <SectionHeader
                                title="Where the money comes from"
                                description="Direct sales against goods sold on komisija"
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
                </div>

                <div class="border border-border bg-card">
                    <div class="flex flex-col gap-3 border-b border-border px-6 py-4">
                        <SectionHeader
                            title="Products bought"
                            :description="`${formatNumber(products.total_units, locale)} bottles · ${formatNumber(products.product_count, locale)} products`"
                        />
                        <div class="flex flex-wrap items-center gap-2">
                            <Tabs
                                :items="PRODUCT_RANGE_TABS"
                                :current="productRange.preset === 'custom' ? '' : productRange.preset"
                                @select="selectProductRange"
                            />
                            <DateRangePicker
                                :model-value="productCustomRange"
                                label="Custom"
                                @update:model-value="selectProductCustomRange"
                            />
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[52rem] text-xs">
                            <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-6 py-2.5 font-medium">Product</th>
                                    <th scope="col" class="px-6 py-2.5 text-right font-medium">Bottles</th>
                                    <th scope="col" class="px-6 py-2.5 font-medium">Share</th>
                                    <th scope="col" class="px-6 py-2.5 font-medium">Last ordered</th>
                                    <th scope="col" class="px-6 py-2.5 font-medium">Signal</th>
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
                                            {{ group.label }}
                                        </th>
                                        <td class="px-6 py-2 text-right text-muted-foreground tabular-nums">
                                            {{ formatNumber(group.units, locale) }} bottles
                                        </td>
                                    </tr>

                                    <tr
                                        v-for="row in group.rows"
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

                                <tr v-if="products.rows.length === 0">
                                    <td colspan="5" class="px-6 py-12 text-center text-muted-foreground">
                                        <!-- A filtered empty is not the same as an empty
                                             customer, and saying so sends people to fix
                                             the wrong thing. -->
                                        {{
                                            productRange.preset === 'lifetime'
                                                ? 'This customer has not ordered anything yet.'
                                                : 'Nothing bought in this range.'
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
                    <div class="border-b border-border px-6 py-4">
                        <SectionHeader
                            title="Prices for this customer"
                            :description="
                                customer.pricing_tier
                                    ? `Tier ${customer.pricing_tier.name} · ${customer.effective_rebate_percent}% rebate`
                                    : `No tier · ${customer.effective_rebate_percent}% rebate`
                            "
                        />
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[44rem] text-xs">
                            <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-6 py-2.5 font-medium">Product</th>
                                    <th scope="col" class="px-6 py-2.5 text-right font-medium">List price</th>
                                    <th scope="col" class="px-6 py-2.5 text-right font-medium">They pay</th>
                                    <th scope="col" class="px-6 py-2.5 font-medium">Decided by</th>
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
                                        <span class="mt-0.5 block text-muted-foreground">{{ row.sku }}</span>
                                    </td>
                                    <td class="px-6 py-3 text-right text-muted-foreground tabular-nums">
                                        {{ money(row.list_price) }}
                                    </td>
                                    <td class="px-6 py-3 text-right font-semibold tabular-nums">
                                        {{ money(row.price) }}
                                    </td>
                                    <td class="px-6 py-3 text-muted-foreground">
                                        {{ PRICE_SOURCE_LABELS[row.source] ?? row.source }}
                                    </td>
                                </tr>
                                <tr v-if="(pricing?.rows ?? []).length === 0">
                                    <td colspan="4" class="px-6 py-12 text-center text-muted-foreground">
                                        No sellable products to price yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- @todo Editing customer prices. The API has upsert/delete
                     endpoints for per-product overrides; the design does not
                     specify the editing surface, so this tab reads only. -->
            </template>

            <!-- ================== Order History ================== -->
            <template v-else-if="tab === 'orders'">
                <div class="border border-border bg-card">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[44rem] text-xs">
                            <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-6 py-2.5 font-medium">Order</th>
                                    <th scope="col" class="px-6 py-2.5 font-medium">Items</th>
                                    <th scope="col" class="px-6 py-2.5 text-right font-medium">Total</th>
                                    <th scope="col" class="px-6 py-2.5 font-medium">Status</th>
                                    <th scope="col" class="px-6 py-2.5 text-right font-medium">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="order in orderHistory?.data ?? []"
                                    :key="order.id"
                                    class="border-b border-border last:border-b-0"
                                >
                                    <td class="px-6 py-3">
                                        <Link
                                            :href="`/orders?order=${order.id}`"
                                            class="font-semibold hover:underline"
                                        >
                                            #{{ order.order_number }}
                                        </Link>
                                    </td>
                                    <td class="px-6 py-3 text-muted-foreground">
                                        {{ order.items.length }} lines ·
                                        {{ formatNumber(order.items.reduce((s, i) => s + i.quantity, 0), locale) }}
                                        units
                                    </td>
                                    <td class="px-6 py-3 text-right font-semibold tabular-nums">
                                        {{ money(order.total_amount) }}
                                    </td>
                                    <td class="px-6 py-3">
                                        {{ orderStatusLabel[order.status] ?? order.status }}
                                    </td>
                                    <td class="px-6 py-3 text-right text-muted-foreground">
                                        {{ shortDate(order.created_at) }}
                                    </td>
                                </tr>
                                <tr v-if="(orderHistory?.data ?? []).length === 0">
                                    <td colspan="5" class="px-6 py-12 text-center text-muted-foreground">
                                        No orders yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <!-- ==================== Komisija ==================== -->
            <template v-else>
                <div class="border border-border bg-card p-6">
                    <SectionHeader
                        title="Komisija"
                        description="Goods placed with this customer that are still ours until sold"
                    />

                    <pre
                        v-if="consignment && Object.keys(consignment).length > 0"
                        class="mt-4 overflow-x-auto text-xs text-muted-foreground"
                        >{{ consignment }}</pre
                    >
                    <p v-else class="mt-4 text-xs text-muted-foreground">
                        Nothing on consignment with this customer.
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
    </AppLayout>
</template>
