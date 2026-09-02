<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { CalendarDays, ChevronRight, Columns3, Download, Plus, Search } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CreateOrderPanel from '@/components/orders/CreateOrderPanel.vue';
import OrderViewPanel from '@/components/orders/OrderViewPanel.vue';
import PipelineCard from '@/components/orders/PipelineCard.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusChips from '@/components/ui/StatusChips.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { formatMoney, formatNumber } from '@/lib/money';
import type { Paginated, SharedProps } from '@/types';
import type { Order, OrderFilters, OrderPipeline, OrderStatusCounts } from '@/types/orders';
import type { TabItem } from '@/types/ui';

/**
 * Orders list, following Figma 455:1577: page header with the two primary
 * actions, the period tab strip, the order-to-cash pipeline card, then a card
 * holding the toolbar, the status chips and the table.
 *
 * Rows carry their first lines inline rather than a summary count — the design
 * treats "what is in this order" as the primary column — and clicking one opens
 * the Order — View drawer (376:1592) over the list rather than navigating, so
 * the reader keeps their filters, scroll position and page.
 */
const props = defineProps<{
    orders: Paginated<Order>;
    filters: OrderFilters;
    statusCounts: OrderStatusCounts;
    /** Null when the viewer may not see financials — the card is money-denominated. */
    pipeline: OrderPipeline | null;
    /** Only present while the Order — View drawer has asked for it. */
    order?: Order | null;
}>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { can } = useAuth();

const search = ref(props.filters.search ?? '');
const createOpen = ref(false);

/*
  The open order comes from the server via a partial reload keyed on ?order=…,
  so a drawer link can be shared and a refresh reopens it. `openId` is what the
  page believes is open; `props.order` is what the server has sent back for it.
*/
const openId = ref<string | null>(null);

/** Debounced server-side search; `preserveState` keeps focus and the typed value. */
let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ search: value || undefined }), 300);
});

function reload(overrides: Record<string, unknown>): void {
    router.get(
        '/orders',
        {
            search: props.filters.search ?? undefined,
            status: props.filters.status ?? undefined,
            period: props.filters.period ?? undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['orders', 'filters', 'statusCounts', 'pipeline'] },
    );
}

/** Figma 455:1577's period strip. `custom` needs a date picker — see below. */
const PERIOD_TABS: TabItem[] = [
    { value: 'today', label: 'Today' },
    { value: 'yesterday', label: 'Yesterday' },
    { value: 'week', label: 'This Week' },
    { value: 'this_month', label: 'This Month' },
    { value: 'qtd', label: 'This Quarter' },
    { value: 'ytd', label: 'Year to Date' },
];

/** The pipeline's fulfilment stages filter the table; the money stages do not. */
const FILTERABLE_STAGES = props.statusCounts.statuses.map((s) => s.key);

function selectStatus(key: string | null): void {
    reload({ status: key ?? undefined });
}

/**
 * Opening a row asks the server for that order's full detail through a partial
 * reload, so the list itself never carries lines, timelines and comment threads
 * for rows nobody opened.
 */
function open(order: Order): void {
    openId.value = order.id;
    // reload() preserves state and scroll by default, which is what a
    // drawer opening over the list needs.
    router.reload({ data: { order: order.id }, only: ['order'], replace: true });
}

function close(): void {
    openId.value = null;
    router.reload({ data: { order: undefined }, only: ['order'], replace: true });
}

/** The design shows three lines per row, then "+ n more". */
const PREVIEW_LINES = 3;

function preview(order: Order): Order['items'] {
    return order.items.slice(0, PREVIEW_LINES);
}

function remaining(order: Order): number {
    return Math.max(0, order.items.length - PREVIEW_LINES);
}

/** "4 lines · 88 bottles", as under a long row in the design. */
function lineSummary(order: Order): string {
    const bottles = order.items.reduce((sum, item) => sum + item.quantity, 0);

    return `${order.items.length} lines · ${formatNumber(bottles, locale.value)} units`;
}

function orderDate(order: Order): string {
    if (order.created_at === null) return '—';

    return new Date(order.created_at).toLocaleDateString(locale.value, {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
    });
}

const statusLabel = (key: string): string =>
    props.statusCounts.statuses.find((s) => s.key === key)?.label ?? key;

/** Swatch tone per status, matching the chip row's workflow ordering. */
const STATUS_TONE: Record<string, string> = {
    RECEIVED: 'border border-foreground',
    IN_PROCESS: 'bg-foreground',
    READY_TO_SHIP: 'bg-foreground',
    SHIPPED: 'bg-muted-foreground/40',
};

const showing = computed(() => {
    const { current_page: current, per_page: per, total } = props.orders.meta;
    const first = total === 0 ? 0 : (current - 1) * per + 1;

    return `Showing ${first}–${Math.min(current * per, total)} of ${formatNumber(total, locale.value)} orders`;
});

function goToPage(pageNumber: number): void {
    reload({ page: pageNumber });
}

const pages = computed(() => {
    const last = props.orders.meta.last_page;

    return Array.from({ length: Math.min(last, 5) }, (_, i) => i + 1);
});
</script>

<template>
    <AppLayout title="Orders">
        <div class="space-y-5">
            <PageHeader title="Orders">
                <template #actions>
                    <Button v-if="can('orders.manage')" size="sm" @click="createOpen = true">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        New order
                    </Button>
                    <!-- @todo Export. No order export endpoint exists yet; the
                         button is here because the design's header has it. -->
                    <Button variant="outline" size="sm">
                        <Download class="size-3.5" :stroke-width="1.5" />
                        Export
                    </Button>
                </template>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-2">
                <Tabs
                    :items="PERIOD_TABS"
                    :current="filters.period ?? 'ytd'"
                    @select="reload({ period: $event })"
                />
                <!-- @todo Custom range. Needs the date-range picker that Phase 1
                     deferred; until then the presets above are the whole control. -->
                <button
                    type="button"
                    class="inline-flex h-6 items-center gap-1.5 px-3 text-xs text-muted-foreground hover:text-foreground"
                >
                    <CalendarDays class="size-3.5" :stroke-width="1.5" />
                    Custom
                </button>
            </div>

            <PipelineCard
                v-if="pipeline"
                :pipeline="pipeline"
                :current="filters.status"
                :filterable="FILTERABLE_STAGES"
                @select="selectStatus"
            />

            <div class="overflow-hidden border border-border bg-card">
                <!-- Toolbar -->
                <div class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                    <div class="relative w-full max-w-[280px]">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                            :stroke-width="1.5"
                        />
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search order no., customer, SKU…"
                            aria-label="Search orders"
                            class="h-[30px] w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                    </div>

                    <!-- @todo Channel / Date range / Rep filters. None has a
                         backing field yet: channel would come from the customer
                         type, rep from an order owner the schema has no column
                         for. Rendered so the toolbar matches 455:1577. -->
                    <button
                        v-for="label in ['Channel', 'Date range', 'Rep']"
                        :key="label"
                        type="button"
                        class="inline-flex h-[30px] shrink-0 items-center gap-1 border border-border bg-card px-2.5 text-xs text-foreground hover:border-foreground/40"
                    >
                        {{ label }}
                        <span aria-hidden="true" class="text-muted-foreground">▾</span>
                    </button>

                    <div class="ml-auto flex items-center gap-2">
                        <!-- @todo Bulk actions. Multi-select on orders is not
                             built; the design offers status changes in bulk. -->
                        <button
                            type="button"
                            class="inline-flex h-[30px] items-center gap-1 border border-border bg-card px-2.5 text-xs hover:border-foreground/40"
                        >
                            Bulk actions
                            <span aria-hidden="true" class="text-muted-foreground">▾</span>
                        </button>
                        <!-- @todo Column chooser. -->
                        <button
                            type="button"
                            class="inline-flex h-[30px] items-center gap-1.5 border border-border bg-card px-2.5 text-xs hover:border-foreground/40"
                        >
                            <Columns3 class="size-3.5" :stroke-width="1.5" />
                            Columns
                        </button>
                    </div>
                </div>

                <div class="border-b border-border px-4 py-3">
                    <StatusChips
                        :chips="statusCounts.statuses"
                        :current="filters.status"
                        :total="statusCounts.total"
                        @select="selectStatus"
                    />
                </div>

                <!-- The table scrolls inside its own container so the page never does. -->
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[60rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">Order</th>
                                <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">Items</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium tracking-[0.08em] uppercase">
                                    Total
                                </th>
                                <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">Status</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium tracking-[0.08em] uppercase">
                                    Date
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="row in orders.data"
                                :key="row.id"
                                class="cursor-pointer border-b border-border align-top transition-colors last:border-b-0 hover:bg-muted/40"
                                @click="open(row)"
                            >
                                <td class="px-4 py-4">
                                    <span class="flex items-center gap-1.5">
                                        <ChevronRight class="size-3 shrink-0 text-muted-foreground" :stroke-width="2" />
                                        <span class="font-semibold text-foreground">#{{ row.order_number }}</span>
                                    </span>
                                    <span class="mt-0.5 block pl-[18px] text-muted-foreground">
                                        {{ row.customer?.company_name ?? '—' }}
                                    </span>
                                </td>

                                <td class="px-4 py-4">
                                    <span v-for="line in preview(row)" :key="line.id" class="flex items-baseline gap-2">
                                        <span class="w-10 shrink-0 text-right font-semibold tabular-nums">
                                            {{ formatNumber(line.quantity, locale) }}×
                                        </span>
                                        <span class="text-foreground">{{ line.name }}</span>
                                        <span v-if="line.unit_size" class="text-muted-foreground">
                                            {{ line.unit_size }}
                                        </span>
                                    </span>
                                    <span v-if="remaining(row)" class="mt-1 block pl-12 text-muted-foreground">
                                        + {{ remaining(row) }} more
                                    </span>
                                    <span v-else-if="row.items.length" class="mt-1 block pl-12 text-muted-foreground">
                                        {{ lineSummary(row) }}
                                    </span>
                                </td>

                                <td class="px-4 py-4 text-right">
                                    <span class="block text-sm font-semibold tabular-nums">
                                        {{ formatMoney(row.total_amount.minor, row.total_amount.currency, locale) }}
                                    </span>
                                    <span class="text-muted-foreground">excl. VAT</span>
                                </td>

                                <td class="px-4 py-4">
                                    <span
                                        class="inline-flex items-center gap-2 border border-border px-2 py-1 whitespace-nowrap"
                                    >
                                        <span
                                            class="size-2 shrink-0"
                                            :class="STATUS_TONE[row.status]"
                                            aria-hidden="true"
                                        />
                                        {{ statusLabel(row.status) }}
                                    </span>
                                </td>

                                <td class="px-4 py-4 text-right">
                                    <span class="block tabular-nums">{{ orderDate(row) }}</span>
                                    <span class="text-muted-foreground">{{ row.created_by?.name ?? '—' }}</span>
                                </td>
                            </tr>

                            <tr v-if="orders.data.length === 0">
                                <td colspan="5" class="px-4 py-12 text-center text-muted-foreground">
                                    No orders in this period.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                    <p class="text-xs text-muted-foreground">{{ showing }}</p>

                    <div v-if="orders.meta.last_page > 1" class="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="orders.meta.current_page === 1"
                            @click="goToPage(orders.meta.current_page - 1)"
                        >
                            Previous
                        </Button>
                        <Button
                            v-for="n in pages"
                            :key="n"
                            :variant="n === orders.meta.current_page ? 'primary' : 'outline'"
                            size="sm"
                            @click="goToPage(n)"
                        >
                            {{ n }}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="orders.meta.current_page === orders.meta.last_page"
                            @click="goToPage(orders.meta.current_page + 1)"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <OrderViewPanel :open="openId !== null" :order="order ?? null" @close="close" />

        <CreateOrderPanel v-if="can('orders.manage')" :open="createOpen" @close="createOpen = false" />
    </AppLayout>
</template>
