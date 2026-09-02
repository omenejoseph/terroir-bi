<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { AlertTriangle, PackageCheck, TrendingDown, TrendingUp } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import CardHeader from '@/components/ui/CardHeader.vue';
import CardTitle from '@/components/ui/CardTitle.vue';
import PeriodTabs from '@/components/ui/PeriodTabs.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import StatCard from '@/components/ui/StatCard.vue';
import { formatMoney, formatNumber, formatQuantity } from '@/lib/money';
import type { DashboardFilters, DashboardSummary } from '@/types/dashboard';
import type { SharedProps } from '@/types';

/**
 * Dashboard, following the TERROIR design (Figma 208:5577): title row with the
 * two primary actions, the period tab strip, an alert band, then the revenue
 * and breakdown cards.
 *
 * The design also specifies annual-target, runway, and reorder-pipeline cards.
 * DashboardSummary does not compute those figures yet, so they are deliberately
 * absent rather than filled with invented numbers — see
 * docs/design/README.md for the gap list.
 */
const props = defineProps<{ summary: DashboardSummary; filters: DashboardFilters }>();

const page = usePage<SharedProps>();

const money = (minor: number) => formatMoney(minor, props.summary.currency, page.props.locale);
const count = (value: number) => formatNumber(value, page.props.locale);
const qty = (value: string) => formatQuantity(value, page.props.locale);

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

/** The design's alert band: only surfaces conditions that are actually true. */
const alerts = computed(() => {
    const out: { icon: typeof AlertTriangle; text: string }[] = [];
    const s = props.summary.stats;

    if (s.low_stock > 0) {
        out.push({ icon: PackageCheck, text: `${count(s.low_stock)} items below minimum stock` });
    }
    if (s.tasks_overdue > 0) {
        out.push({ icon: AlertTriangle, text: `${count(s.tasks_overdue)} tasks overdue` });
    }
    if (s.outstanding_ar > 0) {
        out.push({ icon: AlertTriangle, text: `${money(s.outstanding_ar)} outstanding receivables` });
    }

    return out;
});

const stats = computed(() => [
    { label: 'Revenue', value: money(props.summary.stats.revenue) },
    { label: 'Orders', value: count(props.summary.stats.total_orders) },
    { label: 'Customers', value: count(props.summary.stats.customers) },
    { label: 'Outstanding A/R', value: money(props.summary.stats.outstanding_ar) },
]);
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="space-y-6">
            <!-- Title row + primary actions -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-3xl font-semibold tracking-tight text-foreground">Dashboard</h2>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" href="/orders/new">New order</Button>
                    <Button size="sm" href="/customers/new">New customer</Button>
                </div>
            </div>

            <PeriodTabs :current="filters.period ?? summary.range" />

            <!-- Alert band -->
            <div v-if="alerts.length" class="flex flex-wrap gap-2">
                <span
                    v-for="alert in alerts"
                    :key="alert.text"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-13 text-foreground"
                >
                    <component :is="alert.icon" class="size-4 shrink-0 text-muted-foreground" :stroke-width="1.5" />
                    {{ alert.text }}
                </span>
            </div>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard v-for="stat in stats" :key="stat.label" :label="stat.label" :value="stat.value" />
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Revenue -->
                <Card>
                    <CardHeader>
                        <CardTitle>Revenue</CardTitle>
                        <p class="text-13 text-muted-foreground">Year to date</p>
                    </CardHeader>
                    <CardContent>
                        <div class="flex items-baseline gap-3">
                            <span class="text-3xl font-semibold tabular-nums">{{ money(revenue.current) }}</span>
                            <span
                                v-if="revenueDelta !== null"
                                class="inline-flex items-center gap-1 text-sm"
                                :class="revenueDelta >= 0 ? 'text-success' : 'text-destructive'"
                            >
                                <component
                                    :is="revenueDelta >= 0 ? TrendingUp : TrendingDown"
                                    class="size-4"
                                    :stroke-width="1.5"
                                />
                                {{ Math.abs(revenueDelta).toFixed(1) }}%
                            </span>
                        </div>
                        <p class="mt-1 text-13 text-muted-foreground">vs. the same period last year</p>
                    </CardContent>
                </Card>

                <!-- Revenue breakdown by channel -->
                <Card>
                    <CardHeader>
                        <CardTitle>Revenue breakdown</CardTitle>
                        <p class="text-13 text-muted-foreground">
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

                <!-- Upcoming / recent orders -->
                <Card>
                    <CardHeader>
                        <CardTitle>Recent orders</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul v-if="summary.recent_orders.length" class="divide-y divide-border">
                            <li
                                v-for="order in summary.recent_orders"
                                :key="order.id"
                                class="flex items-center justify-between gap-4 py-3"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">{{ order.order_number }}</p>
                                    <p class="truncate text-13 text-muted-foreground">
                                        {{ order.customer || '—' }} · {{ order.date }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <Badge variant="outline">{{ order.status }}</Badge>
                                    <span class="text-sm tabular-nums">{{ money(order.total) }}</span>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="py-6 text-sm text-muted-foreground">No orders in this period.</p>
                    </CardContent>
                </Card>

                <!-- Stock watch -->
                <Card>
                    <CardHeader>
                        <CardTitle>Stock watch</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul v-if="summary.stock_watch.length" class="divide-y divide-border">
                            <li
                                v-for="item in summary.stock_watch"
                                :key="item.name"
                                class="flex items-center justify-between gap-4 py-3"
                            >
                                <p class="min-w-0 truncate text-sm font-medium">{{ item.name }}</p>
                                <span class="shrink-0 text-sm tabular-nums">
                                    {{ qty(item.stock) }} <span class="text-muted-foreground">/ {{ qty(item.min) }}</span>
                                </span>
                            </li>
                        </ul>
                        <p v-else class="py-6 text-sm text-muted-foreground">Nothing needs attention.</p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
