<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';

import AdminLayout from '@/layouts/AdminLayout.vue';
import Sparkline from '@/components/admin/Sparkline.vue';
import TenantsByPlanChart from '@/components/admin/TenantsByPlanChart.vue';
import AreaChart from '@/components/ui/AreaChart.vue';
import Badge from '@/components/ui/Badge.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import CardHeader from '@/components/ui/CardHeader.vue';
import CardTitle from '@/components/ui/CardTitle.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import StatCard from '@/components/ui/StatCard.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminActiveTenant, AdminAttentionTenant, AdminDashboardStats, ChartPoint } from '@/types/admin';
import type { Paginated } from '@/types';

/**
 * Platform dashboard — port of App\Filament\Pages\Dashboard and its 5
 * widgets: PlatformStatsOverview, TenantSignupsChart, TenantsByPlanChart,
 * TenantsNeedingAttentionTable, MostActiveTenantsTable.
 */
const props = defineProps<{
    stats: AdminDashboardStats;
    signups: ChartPoint[];
    tenantsByPlan: ChartPoint[];
    needingAttention: AdminAttentionTenant[];
    mostActive: Paginated<AdminActiveTenant>;
}>();

const { t } = useTranslations();

function goToActivePage(page: number): void {
    router.get(
        `${ADMIN_BASE}`,
        { active_page: page },
        { preserveState: true, preserveScroll: true, replace: true, only: ['mostActive'] },
    );
}

function setActivePerPage(perPage: number): void {
    router.get(
        `${ADMIN_BASE}`,
        { active_page: 1, per_page: perPage },
        { preserveState: true, preserveScroll: true, replace: true, only: ['mostActive'] },
    );
}

const PROBLEM_STATUSES = ['past_due', 'unpaid', 'incomplete', 'incomplete_expired'];
</script>

<template>
    <AdminLayout :title="t('Admin dashboard')">
        <div class="space-y-6">
            <PageHeader :title="t('Dashboard')" />

            <!-- PlatformStatsOverview -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
                <StatCard
                    :label="t('Tenants')"
                    :value="String(stats.tenants.total)"
                    :hint="t(':new new this month · :active active', { new: stats.tenants.new_this_month, active: stats.tenants.active })"
                />
                <StatCard
                    :label="t('Trials')"
                    :value="String(stats.tenants.trial)"
                    :hint="t(':count ending within 14 days', { count: stats.trials_ending_soon })"
                    :alert="stats.trials_ending_soon > 0"
                />
                <StatCard
                    :label="t('Est. MRR')"
                    :value="`${stats.mrr.major} ${stats.mrr.currency}`"
                    :hint="t(':count paying tenants', { count: stats.mrr.paying_tenants })"
                />
                <StatCard :label="t('Active users')" :value="String(stats.active_users)" :hint="t('Across all tenants')" />
                <div class="rounded-lg border border-border bg-card p-5">
                    <p class="text-xs font-medium text-muted-foreground">{{ t('Orders (30d)') }}</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-foreground">{{ stats.order_activity.total }}</p>
                    <Sparkline :values="stats.order_activity.per_day" class="mt-2" />
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ t('Tenant signups') }}</CardTitle>
                        <p class="text-xs text-muted-foreground">{{ t('New tenants per month, last 12 months.') }}</p>
                    </CardHeader>
                    <CardContent>
                        <AreaChart :points="signups" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{{ t('Tenants by plan') }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <TenantsByPlanChart :points="tenantsByPlan" />
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Needs attention') }}</CardTitle>
                    <p class="text-xs text-muted-foreground">{{ t('Failing payments and trials ending within 14 days.') }}</p>
                </CardHeader>
                <CardContent class="pt-0">
                    <div v-if="needingAttention.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                        <p class="font-medium text-foreground">{{ t('All clear') }}</p>
                        <p class="mt-1">{{ t('No failing payments or expiring trials right now.') }}</p>
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="border-b border-border text-left text-muted-foreground">
                                <tr>
                                    <th class="py-2 font-medium">{{ t('Name') }}</th>
                                    <th class="py-2 font-medium">{{ t('Plan') }}</th>
                                    <th class="py-2 font-medium">{{ t('Stripe status') }}</th>
                                    <th class="py-2 font-medium">{{ t('Trial ends') }}</th>
                                    <th class="py-2 font-medium">{{ t('Period ends') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="tenant in needingAttention" :key="tenant.id" class="border-b border-border last:border-b-0">
                                    <td class="py-2.5">
                                        <Link :href="`${ADMIN_BASE}/tenants/${tenant.id}`" class="font-medium text-foreground hover:underline">
                                            {{ tenant.name }}
                                        </Link>
                                    </td>
                                    <td class="py-2.5">
                                        <Badge v-if="tenant.plan_name" variant="outline">{{ tenant.plan_name }}</Badge>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="py-2.5">
                                        <Badge v-if="tenant.stripe_status" :variant="PROBLEM_STATUSES.includes(tenant.stripe_status) ? 'destructive' : 'warning'">
                                            {{ tenant.stripe_status }}
                                        </Badge>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="py-2.5 text-muted-foreground">
                                        {{ tenant.trial_ends_at ? new Date(tenant.trial_ends_at).toLocaleDateString() : '—' }}
                                    </td>
                                    <td class="py-2.5 text-muted-foreground">
                                        {{ tenant.current_period_end ? new Date(tenant.current_period_end).toLocaleDateString() : '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Most active tenants') }}</CardTitle>
                    <p class="text-xs text-muted-foreground">{{ t('Ranked by orders placed in the last 30 days.') }}</p>
                </CardHeader>
                <CardContent class="pt-0">
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="border-b border-border text-left text-muted-foreground">
                                <tr>
                                    <th class="py-2 font-medium">{{ t('Name') }}</th>
                                    <th class="py-2 font-medium">{{ t('Plan') }}</th>
                                    <th class="py-2 text-right font-medium">{{ t('Orders (30d)') }}</th>
                                    <th class="py-2 text-right font-medium">{{ t('Seats') }}</th>
                                    <th class="py-2 font-medium">{{ t('Last order') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="tenant in mostActive.data" :key="tenant.id" class="border-b border-border last:border-b-0">
                                    <td class="py-2.5">
                                        <Link :href="`${ADMIN_BASE}/tenants/${tenant.id}`" class="font-medium text-foreground hover:underline">
                                            {{ tenant.name }}
                                        </Link>
                                    </td>
                                    <td class="py-2.5">
                                        <Badge v-if="tenant.plan_name" variant="outline">{{ tenant.plan_name }}</Badge>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="py-2.5 text-right">
                                        <Badge :variant="tenant.orders_recent_count > 0 ? 'solid' : 'neutral'">
                                            {{ tenant.orders_recent_count }}
                                        </Badge>
                                    </td>
                                    <td class="py-2.5 text-right tabular-nums">{{ tenant.members_count }}</td>
                                    <td class="py-2.5 text-muted-foreground">
                                        {{ tenant.last_order_at ? new Date(tenant.last_order_at).toLocaleDateString() : t('never') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-3 pt-3">
                        <Pagination :meta="mostActive.meta" @update:page="goToActivePage" @update:per-page="setActivePerPage" />
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
