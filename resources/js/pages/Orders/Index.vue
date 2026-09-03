<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { ChevronRight, Columns3, Download, Plus, Search } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CreateOrderPanel from '@/components/orders/CreateOrderPanel.vue';
import OrderViewPanel from '@/components/orders/OrderViewPanel.vue';
import PipelineCard from '@/components/orders/PipelineCard.vue';
import Button from '@/components/ui/Button.vue';
import DateRangePicker from '@/components/ui/DateRangePicker.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import StatusChips from '@/components/ui/StatusChips.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { usePopover } from '@/composables/usePopover';
import { useTranslations } from '@/composables/useTranslations';
import { CUSTOMER_TYPES, customerTypeLabel } from '@/lib/customers';
import { formatMoney, formatNumber } from '@/lib/money';
import type { Paginated, SharedProps } from '@/types';
import type { Order, OrderFilters, OrderPipeline, OrderStatusCounts } from '@/types/orders';
import type { DateRange, TabItem } from '@/types/ui';

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
const { t } = useTranslations();

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
            channel: props.filters.channel ?? undefined,
            period: props.filters.period ?? undefined,
            from: props.filters.from ?? undefined,
            to: props.filters.to ?? undefined,
            // Carried forward so changing a filter does not silently reset the
            // page size back to the server default.
            per_page: props.orders.meta.per_page,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['orders', 'filters', 'statusCounts', 'pipeline'] },
    );
}

/** The toolbar's Channel filter — filters by the customer's sales channel (App\Enums\CustomerType); Rep has no backing order-owner column, so it stays a dead stub. */
const channelFilterAnchor = ref<HTMLElement | null>(null);
const { open: channelFilterOpen, close: closeChannelFilter, toggle: toggleChannelFilter } = usePopover(channelFilterAnchor);

function selectChannel(value: string | null): void {
    closeChannelFilter();
    reload({ channel: value ?? undefined });
}

/** Figma 455:1577's period strip. `custom` needs a date picker — see below. */
const PERIOD_TABS = computed<TabItem[]>(() => [
    { value: 'today', label: t('Today') },
    { value: 'yesterday', label: t('Yesterday') },
    { value: 'week', label: t('This Week') },
    { value: 'this_month', label: t('This Month') },
    { value: 'qtd', label: t('This Quarter') },
    { value: 'ytd', label: t('Year to Date') },
]);

/*
  The period strip and the custom range are one control with two faces: picking
  a preset clears the range, and applying a range clears the preset, so the
  window is never described by both at once.
*/
const customRange = computed<DateRange>(() => ({ from: props.filters.from, to: props.filters.to }));

function selectPeriod(value: string): void {
    reload({ period: value, from: undefined, to: undefined });
}

function selectRange(range: DateRange): void {
    reload({
        period: undefined,
        from: range.from ?? undefined,
        to: range.to ?? undefined,
    });
}

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

    return t(':count lines · :units units', { count: order.items.length, units: formatNumber(bottles, locale.value) });
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

    return t('Showing :from–:to of :total orders', {
        from: first,
        to: Math.min(current * per, total),
        total: formatNumber(total, locale.value),
    });
});

function goToPage(pageNumber: number): void {
    reload({ page: pageNumber });
}

function setPerPage(perPage: number): void {
    // Changing the page size while sitting on a later page would silently
    // show nothing if the list is now shorter — reset to page 1 with it.
    reload({ per_page: perPage, page: 1 });
}
</script>

<template>
    <AppLayout :title="t('Orders')">
        <div class="space-y-5">
            <PageHeader :title="t('Orders')">
                <template #actions>
                    <Button v-if="can('orders.manage')" size="sm" @click="createOpen = true">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        {{ t('New order') }}
                    </Button>
                    <!-- @todo Export. No order export endpoint exists yet; the
                         button is here because the design's header has it. -->
                    <Button variant="outline" size="sm">
                        <Download class="size-3.5" :stroke-width="1.5" />
                        {{ t('Export') }}
                    </Button>
                </template>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-2">
                <Tabs
                    :items="PERIOD_TABS"
                    :current="filters.from === null ? (filters.period ?? 'ytd') : ''"
                    @select="selectPeriod"
                />
                <DateRangePicker
                    :model-value="customRange"
                    :label="t('Custom')"
                    @update:model-value="selectRange"
                />
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
                            :placeholder="t('Search order no., customer, SKU…')"
                            :aria-label="t('Search orders')"
                            class="h-[30px] w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                    </div>

                    <!-- The design's third toolbar filter is the same date
                         range as the strip above, so it drives the same state
                         rather than being a second, competing window. -->
                    <DateRangePicker
                        :model-value="customRange"
                        :label="t('Date range')"
                        @update:model-value="selectRange"
                    />

                    <!-- Channel filters by the customer's sales channel — orders
                         have no channel of their own (docs/design/README.md). -->
                    <div ref="channelFilterAnchor" class="relative">
                        <button
                            type="button"
                            class="inline-flex h-[30px] shrink-0 items-center gap-1 border px-2.5 text-xs transition-colors"
                            :class="
                                filters.channel === null
                                    ? 'border-border bg-card text-foreground hover:border-foreground/40'
                                    : 'border-primary bg-primary text-primary-foreground'
                            "
                            @click="toggleChannelFilter"
                        >
                            {{ t('Channel') }}
                            <template v-if="filters.channel">· {{ customerTypeLabel(filters.channel) }}</template>
                            <span aria-hidden="true" class="text-muted-foreground">▾</span>
                        </button>
                        <div
                            v-if="channelFilterOpen"
                            class="absolute top-9 left-0 z-20 min-w-40 border border-border bg-card p-1 shadow-lg"
                        >
                            <button
                                type="button"
                                class="block w-full px-2 py-1.5 text-left text-xs hover:bg-muted"
                                @click="selectChannel(null)"
                            >
                                {{ t('Any channel') }}
                            </button>
                            <button
                                v-for="type in CUSTOMER_TYPES"
                                :key="type.value"
                                type="button"
                                class="block w-full px-2 py-1.5 text-left text-xs hover:bg-muted"
                                @click="selectChannel(type.value)"
                            >
                                {{ type.label }}
                            </button>
                        </div>
                    </div>

                    <!-- @todo Rep filter. There is no order-owner column to
                         filter by yet. Rendered so the toolbar matches 455:1577. -->
                    <button
                        type="button"
                        class="inline-flex h-[30px] shrink-0 items-center gap-1 border border-border bg-card px-2.5 text-xs text-foreground hover:border-foreground/40"
                    >
                        {{ t('Rep') }}
                        <span aria-hidden="true" class="text-muted-foreground">▾</span>
                    </button>

                    <div class="ml-auto flex items-center gap-2">
                        <!-- @todo Bulk actions. Multi-select on orders is not
                             built; the design offers status changes in bulk. -->
                        <button
                            type="button"
                            class="inline-flex h-[30px] items-center gap-1 border border-border bg-card px-2.5 text-xs hover:border-foreground/40"
                        >
                            {{ t('Bulk actions') }}
                            <span aria-hidden="true" class="text-muted-foreground">▾</span>
                        </button>
                        <!-- @todo Column chooser. -->
                        <button
                            type="button"
                            class="inline-flex h-[30px] items-center gap-1.5 border border-border bg-card px-2.5 text-xs hover:border-foreground/40"
                        >
                            <Columns3 class="size-3.5" :stroke-width="1.5" />
                            {{ t('Columns') }}
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
                                <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Order') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Items') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium tracking-[0.08em] uppercase">
                                    {{ t('Total') }}
                                </th>
                                <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Status') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium tracking-[0.08em] uppercase">
                                    {{ t('Date') }}
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
                                    <!--
                                      Cases read "N# x Name" — the # marks the
                                      unit as cases rather than bottles, which
                                      this row otherwise has no way to show.
                                    -->
                                    <span v-for="line in preview(row)" :key="line.id" class="flex items-baseline gap-2">
                                        <span class="w-10 shrink-0 text-right font-semibold tabular-nums">
                                            {{ formatNumber(line.quantity, locale) }}{{ line.unit_type === 'cases' ? '#' : '×' }}
                                        </span>
                                        <span class="text-foreground">
                                            <template v-if="line.unit_type === 'cases'">x </template>{{ line.name }}
                                        </span>
                                        <span v-if="line.unit_size" class="text-muted-foreground">
                                            {{ line.unit_size }}
                                        </span>
                                    </span>
                                    <span v-if="remaining(row)" class="mt-1 block pl-12 text-muted-foreground">
                                        {{ t('+ :count more', { count: remaining(row) }) }}
                                    </span>
                                    <span v-else-if="row.items.length" class="mt-1 block pl-12 text-muted-foreground">
                                        {{ lineSummary(row) }}
                                    </span>
                                </td>

                                <td class="px-4 py-4 text-right">
                                    <span class="block text-sm font-semibold tabular-nums">
                                        {{ formatMoney(row.total_amount.minor, row.total_amount.currency) }}
                                    </span>
                                    <span class="text-muted-foreground">{{ t('excl. VAT') }}</span>
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
                                    {{ t('No orders in this period.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                    <p class="text-xs text-muted-foreground">{{ showing }}</p>

                    <Pagination :meta="orders.meta" @update:page="goToPage" @update:per-page="setPerPage" />
                </div>
            </div>
        </div>

        <OrderViewPanel :open="openId !== null" :order="order ?? null" @close="close" />

        <CreateOrderPanel v-if="can('orders.manage')" :open="createOpen" @close="createOpen = false" />
    </AppLayout>
</template>
