<script setup lang="ts">
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, PackageCheck, TrendingDown, TrendingUp } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import KeyRatiosGrid from '@/components/dashboard/KeyRatiosGrid.vue';
import LowStockCard from '@/components/dashboard/LowStockCard.vue';
import NetCashFlowCard from '@/components/dashboard/NetCashFlowCard.vue';
import ReorderPipelineCard from '@/components/dashboard/ReorderPipelineCard.vue';
import UpcomingTasksCard from '@/components/dashboard/UpcomingTasksCard.vue';
import AreaChart from '@/components/ui/AreaChart.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import CardHeader from '@/components/ui/CardHeader.vue';
import CardTitle from '@/components/ui/CardTitle.vue';
import DateRangePicker from '@/components/ui/DateRangePicker.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { formatMoney, formatNumber } from '@/lib/money';
import type { DashboardFilters, DashboardSummary } from '@/types/dashboard';
import type { SharedProps } from '@/types';
import type { DateRange, TabItem } from '@/types/ui';

/**
 * Dashboard, following the TERROIR design (Figma 208:5577): title row with the
 * two primary actions, the period tab strip, an alert band, the revenue and
 * breakdown cards, a row of four operational cards, then the ratio grid and
 * low stock.
 *
 * Three things the design draws still have nothing to compute them: an annual
 * revenue target, per-channel targets, and a cash-on-hand figure for runway.
 * Those stay `@todo` rather than filled with invented numbers — see
 * docs/design/README.md for the full accounting of what's missing vs. what
 * was simply never wired up.
 */
const props = defineProps<{ summary: DashboardSummary; filters: DashboardFilters }>();

const page = usePage<SharedProps>();

/** The design's period strip (Figma 208:5577); values are tokens the server accepts. */
const PERIOD_TABS: TabItem[] = [
    { value: 'today', label: 'Today' },
    { value: 'yesterday', label: 'Yesterday' },
    { value: 'week', label: 'This Week' },
    { value: 'mtd', label: 'This Month' },
    { value: 'qtd', label: 'This Quarter' },
    { value: 'ytd', label: 'Year to Date' },
];

function reload(overrides: Record<string, unknown>): void {
    router.get(
        '/dashboard',
        {
            period: props.filters.period ?? undefined,
            from: props.filters.from ?? undefined,
            to: props.filters.to ?? undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function selectPeriod(period: string): void {
    reload({ period, from: undefined, to: undefined });
}

/*
  The period strip and the custom range are one control with two faces, as on
  Orders: picking a preset clears the range, and applying a range clears the
  preset, so the window is never described by both at once.
*/
const customRange = computed<DateRange>(() => ({ from: props.filters.from, to: props.filters.to }));

function selectRange(range: DateRange): void {
    reload({ period: undefined, from: range.from ?? undefined, to: range.to ?? undefined });
}

const money = (minor: number) => formatMoney(minor, props.summary.currency, page.props.locale);
const count = (value: number) => formatNumber(value, page.props.locale);

/** Percentage change vs the comparison window, when one exists. */
function delta(current: number, previous: number | null): number | null {
    if (previous === null || previous === 0) return null;

    return ((current - previous) / previous) * 100;
}

const revenue = computed(() => props.summary.revenue_summary.ytd ?? { current: 0, previous: null });
const revenueDelta = computed(() => delta(revenue.value.current, revenue.value.previous));

/** Channel rows, largest first; the design lists them descending by value. */
const channels = computed(() =>
    Object.entries(props.summary.revenue_by_channel ?? {})
        .filter(([key]) => key !== 'total')
        .map(([key, point]) => ({
            key,
            label: key.charAt(0).toUpperCase() + key.slice(1),
            current: point.current,
            delta: delta(point.current, point.previous),
        }))
        .filter((row) => row.current > 0)
        .sort((a, b) => b.current - a.current),
);

const channelTotal = computed(() => channels.value.reduce((sum, row) => sum + row.current, 0));

/**
 * The alert band (Figma 208:5577): "N orders ready to ship" and "N tasks
 * overdue" mirror the design exactly. The design's third condition —
 * inventory turnover against a target — has no target stored anywhere (see
 * docs/design/README.md), so it stays absent rather than alerting off a
 * threshold nobody set.
 */
const alerts = computed(() => {
    const out: { icon: typeof AlertTriangle; text: string }[] = [];
    const s = props.summary.stats;

    if (s.ready_to_ship > 0) {
        out.push({ icon: PackageCheck, text: `${count(s.ready_to_ship)} orders ready to ship` });
    }
    if (s.tasks_overdue > 0) {
        out.push({ icon: AlertTriangle, text: `${count(s.tasks_overdue)} task${s.tasks_overdue === 1 ? '' : 's'} overdue` });
    }

    return out;
});
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="space-y-6">
            <!-- Title row + primary actions -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-foreground">Dashboard</h2>
                <div class="flex items-center gap-2">
                    <!-- @todo Opens the Create Order drawer (335:4233) once Orders is ported. -->
                    <Button variant="outline" size="sm">New order</Button>
                    <!-- @todo Opens the Customer — Create drawer (316:80) once Customers is ported. -->
                    <Button size="sm">New customer</Button>
                </div>
            </div>

            <!-- The Dashboard's period strip spans the content column (208:5577). -->
            <div class="flex flex-wrap items-center gap-2">
                <Tabs
                    :items="PERIOD_TABS"
                    :current="filters.from === null ? (filters.period ?? summary.range) : ''"
                    variant="segmented"
                    @select="selectPeriod"
                />
                <DateRangePicker :model-value="customRange" label="Custom" @update:model-value="selectRange" />
            </div>

            <!-- Alert band -->
            <div v-if="alerts.length" class="flex flex-wrap gap-2">
                <span
                    v-for="alert in alerts"
                    :key="alert.text"
                    class="inline-flex items-center gap-2 border border-border bg-card px-3 py-2 text-xs text-foreground"
                >
                    <component :is="alert.icon" class="size-4 shrink-0 text-muted-foreground" :stroke-width="1.5" />
                    {{ alert.text }}
                </span>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Revenue -->
                <Card>
                    <CardHeader>
                        <div class="flex items-baseline justify-between gap-3">
                            <CardTitle>Revenue</CardTitle>
                            <p class="text-xs text-muted-foreground">Year to Date</p>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex items-baseline gap-3">
                            <span class="text-3xl font-semibold tabular-nums">{{ money(revenue.current) }}</span>
                            <Badge
                                v-if="revenueDelta !== null"
                                :variant="revenueDelta >= 0 ? 'success' : 'destructive'"
                                class="gap-1"
                            >
                                <component
                                    :is="revenueDelta >= 0 ? TrendingUp : TrendingDown"
                                    class="size-3"
                                    :stroke-width="1.5"
                                />
                                {{ Math.abs(revenueDelta).toFixed(1) }}%
                            </Badge>
                        </div>
                        <AreaChart :points="summary.revenue_trend" />
                    </CardContent>
                </Card>

                <!-- Revenue breakdown by channel -->
                <Card>
                    <CardHeader>
                        <CardTitle>Revenue breakdown</CardTitle>
                        <p class="text-xs text-muted-foreground">
                            By channel · {{ money(channelTotal) }} attributed
                        </p>
                    </CardHeader>
                    <CardContent>
                        <ul v-if="channels.length" class="space-y-4">
                            <li v-for="row in channels" :key="row.key" class="space-y-1.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="text-sm font-medium">{{ row.label }}</span>
                                    <span class="text-sm tabular-nums">{{ money(row.current) }}</span>
                                </div>
                                <ProgressBar
                                    :value="channelTotal > 0 ? (row.current / channelTotal) * 100 : 0"
                                    :label="row.label"
                                />
                            </li>
                        </ul>
                        <p v-else class="py-6 text-sm text-muted-foreground">No revenue in this period.</p>
                    </CardContent>
                </Card>
            </div>

            <!--
              Upcoming tasks · Runway · Net cash flow · Reorder pipeline (Figma
              208:5577's four-card row). "Revenue vs. target" sits stacked above
              Reorder pipeline rather than in its own row: it folds in "Target
              by channel" too, since neither can show a real number yet (no
              annual or per-channel target is stored anywhere — same shape of
              gap as Runway below), and a full row for two blocked placeholders
              would outweigh what they actually say. `items-start` keeps the
              other three cards at their own height instead of stretching to
              match this taller column.
            -->
            <div class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-4">
                <UpcomingTasksCard :tasks="summary.upcoming_tasks" />

                <!--
                  @todo Runway (Figma 208:5808): months of cash left, plus a
                  feed of what's overdue to collect and what's due to pay. No
                  cash-on-hand figure is stored anywhere, so months-of-runway
                  cannot be computed — see docs/design/README.md.
                -->
                <div class="border border-border bg-card p-4">
                    <h3 class="text-sm font-semibold">Runway</h3>
                    <p class="mt-4 text-xs text-muted-foreground">
                        No cash balance is on file, so runway can't be calculated yet.
                    </p>
                </div>

                <NetCashFlowCard :flow="summary.net_cash_flow" :currency="summary.currency" />

                <div class="flex flex-col gap-4">
                    <!--
                      @todo Revenue vs. target (Figma 208:5577 / 286:781), folding
                      in "Target by channel". No annual or per-channel target is
                      stored anywhere — needs a tenant setting or a targets table
                      before either can show a real number.
                    -->
                    <div class="border border-border bg-card p-4">
                        <h3 class="text-sm font-semibold">Revenue vs. target</h3>
                        <p class="mt-4 text-xs text-muted-foreground">
                            No annual or per-channel target is set, so progress can't be calculated yet.
                        </p>
                    </div>
                    <ReorderPipelineCard :pipeline="summary.reorder_pipeline" :currency="summary.currency" />
                </div>
            </div>

            <!-- Key ratios · Low stock (Figma 208:6303 / 286:1024) -->
            <div class="grid gap-4 md:grid-cols-2">
                <KeyRatiosGrid :ratios="summary.key_ratios" :currency="summary.currency" />
                <LowStockCard :items="summary.stock_watch" />
            </div>
        </div>
    </AppLayout>
</template>
