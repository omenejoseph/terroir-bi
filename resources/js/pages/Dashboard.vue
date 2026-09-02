<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import CardHeader from '@/components/ui/CardHeader.vue';
import CardTitle from '@/components/ui/CardTitle.vue';
import StatCard from '@/components/ui/StatCard.vue';
import { formatMoney, formatNumber } from '@/lib/money';
import type { DashboardFilters, DashboardSummary } from '@/types/dashboard';
import type { SharedProps } from '@/types';

const props = defineProps<{ summary: DashboardSummary; filters: DashboardFilters }>();

const page = usePage<SharedProps>();

const money = (minor: number) => formatMoney(minor, props.summary.currency, page.props.locale);
const count = (value: number) => formatNumber(value, page.props.locale);

const stats = computed(() => [
    { label: 'Revenue', value: money(props.summary.stats.revenue) },
    { label: 'Orders', value: count(props.summary.stats.total_orders) },
    { label: 'Customers', value: count(props.summary.stats.customers) },
    { label: 'Outstanding A/R', value: money(props.summary.stats.outstanding_ar) },
    { label: 'Low stock', value: count(props.summary.stats.low_stock) },
    { label: 'Overdue tasks', value: count(props.summary.stats.tasks_overdue) },
]);
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                <StatCard v-for="stat in stats" :key="stat.label" :label="stat.label" :value="stat.value" />
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
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
                                    <p class="truncate text-xs text-muted-foreground">
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
                                    {{ item.stock }} <span class="text-muted-foreground">/ {{ item.min }}</span>
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
