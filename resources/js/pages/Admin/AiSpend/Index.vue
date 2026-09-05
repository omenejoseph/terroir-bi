<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Cloud } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Select from '@/components/ui/Select.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import { csrfHeader } from '@/lib/csrf';
import type { AdminAiSpendTenantRow, AdminAiSpendTotals, AdminOption } from '@/types/admin';
import type { Paginated } from '@/types';
import type { TabItem } from '@/types/ui';

/**
 * AI Spend — port of App\Filament\Pages\AiSpend. Local usage totals render
 * immediately; USD cost is fetched from Cloudflare only on demand and merged
 * into local state (not a page prop — same "Load Cloudflare cost" posture
 * Filament's component-state `$cloudflare` had).
 */
const props = defineProps<{
    filters: { period: string; from: string | null; to: string | null; tenant_id: string | null };
    periodOptions: AdminOption[];
    tenantOptions: AdminOption[];
    gatewayConfigured: boolean;
    totals: AdminAiSpendTotals;
    perTenant: Paginated<AdminAiSpendTenantRow>;
}>();

const { t } = useTranslations();

const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const cloudflareCostUsd = ref<number | null>(props.totals.cost_usd ?? null);
const cloudflareByTenant = ref<Record<string, number | null>>({});
const loadingCloudflare = ref(false);

const PERIOD_TABS = computed<TabItem[]>(() =>
    props.periodOptions.map((o) => ({ label: o.label, value: o.value })),
);

function reload(overrides: Record<string, unknown>): void {
    cloudflareCostUsd.value = null;
    cloudflareByTenant.value = {};

    router.get(
        `${ADMIN_BASE}/ai-spend`,
        {
            period: props.filters.period,
            from: props.filters.from ?? undefined,
            to: props.filters.to ?? undefined,
            tenant_id: props.filters.tenant_id ?? undefined,
            per_page: props.perTenant.meta.per_page,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['totals', 'perTenant', 'filters'] },
    );
}

function selectPeriod(period: string): void {
    reload({ period, page: 1 });
}

function applyCustomRange(): void {
    reload({ period: 'custom', from: from.value || undefined, to: to.value || undefined, page: 1 });
}

function setTenant(tenantId: string): void {
    reload({ tenant_id: tenantId || undefined, page: 1 });
}

function goToPage(page: number): void {
    reload({ page });
}

function setPerPage(perPage: number): void {
    reload({ per_page: perPage, page: 1 });
}

async function loadCloudflareCost(): Promise<void> {
    loadingCloudflare.value = true;

    try {
        const params = new URLSearchParams({
            period: props.filters.period,
            ...(props.filters.from ? { from: props.filters.from } : {}),
            ...(props.filters.to ? { to: props.filters.to } : {}),
            ...(props.filters.tenant_id ? { tenant_id: props.filters.tenant_id } : {}),
        });

        const response = await fetch(`${ADMIN_BASE}/ai-spend/cloudflare-cost?${params.toString()}`, {
            headers: { Accept: 'application/json', ...csrfHeader() },
            credentials: 'same-origin',
        });
        const body = await response.json();

        if (!response.ok) return;

        cloudflareCostUsd.value = typeof body.global?.cost_usd === 'number' ? body.global.cost_usd : null;

        const byTenant: Record<string, number | null> = {};
        for (const [tenantId, row] of Object.entries<{ cost_usd?: number }>(body.by_tenant ?? {})) {
            byTenant[tenantId] = typeof row.cost_usd === 'number' ? row.cost_usd : null;
        }
        cloudflareByTenant.value = byTenant;
    } finally {
        loadingCloudflare.value = false;
    }
}

function tenantCost(tenantId: string | null): string {
    if (tenantId === null) return '—';

    const cost = cloudflareByTenant.value[tenantId];

    return cost === undefined || cost === null ? '—' : `$${cost.toFixed(2)}`;
}
</script>

<template>
    <AdminLayout :title="t('AI Spend')">
        <div class="space-y-5">
            <PageHeader :title="t('AI Spend')">
                <template #actions>
                    <Button
                        v-if="gatewayConfigured"
                        variant="outline"
                        size="sm"
                        :disabled="loadingCloudflare"
                        @click="loadCloudflareCost"
                    >
                        <Cloud class="size-3.5" :stroke-width="1.5" />
                        {{ loadingCloudflare ? t('Loading…') : t('Load Cloudflare cost') }}
                    </Button>
                </template>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-3">
                <Tabs :items="PERIOD_TABS" :current="filters.period" @select="selectPeriod" />
                <Select
                    :model-value="filters.tenant_id"
                    :placeholder="t('All tenants')"
                    :options="tenantOptions"
                    class="w-48"
                    @update:model-value="setTenant"
                />
            </div>

            <div v-if="filters.period === 'custom'" class="flex flex-wrap items-end gap-2">
                <label class="flex flex-col gap-1 text-xs text-muted-foreground">
                    {{ t('From') }}
                    <input v-model="from" type="date" class="h-9 border border-input bg-card px-3 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs text-muted-foreground">
                    {{ t('To') }}
                    <input v-model="to" type="date" class="h-9 border border-input bg-card px-3 text-sm" />
                </label>
                <Button size="sm" @click="applyCustomRange">{{ t('Apply') }}</Button>
            </div>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">{{ t('Requests') }}</p>
                    <p class="mt-1 text-xl font-semibold text-foreground tabular-nums">{{ totals.requests }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">{{ t('Prompt tokens') }}</p>
                    <p class="mt-1 text-xl font-semibold text-foreground tabular-nums">{{ totals.prompt_tokens }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">{{ t('Completion tokens') }}</p>
                    <p class="mt-1 text-xl font-semibold text-foreground tabular-nums">{{ totals.completion_tokens }}</p>
                </Card>
                <Card class="p-4">
                    <p class="text-xs text-muted-foreground">{{ t('Cost (USD)') }}</p>
                    <p class="mt-1 text-xl font-semibold text-foreground tabular-nums">
                        {{ cloudflareCostUsd === null ? '—' : `$${cloudflareCostUsd.toFixed(2)}` }}
                    </p>
                    <Badge v-if="!gatewayConfigured" variant="neutral" class="mt-1">{{ t('Cloudflare not configured') }}</Badge>
                </Card>
            </div>

            <Card class="overflow-hidden p-0">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2.5 font-medium">{{ t('Tenant') }}</th>
                                <th class="px-4 py-2.5 text-right font-medium">{{ t('Requests') }}</th>
                                <th class="px-4 py-2.5 text-right font-medium">{{ t('Prompt tokens') }}</th>
                                <th class="px-4 py-2.5 text-right font-medium">{{ t('Completion tokens') }}</th>
                                <th class="px-4 py-2.5 text-right font-medium">{{ t('Cost (USD)') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in perTenant.data" :key="String(row.tenant_id)" class="border-b border-border last:border-b-0">
                                <td class="px-4 py-2.5 text-foreground">{{ row.tenant }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ row.requests }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ row.prompt_tokens }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ row.completion_tokens }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ tenantCost(row.tenant_id) }}</td>
                            </tr>

                            <tr v-if="perTenant.data.length === 0">
                                <td colspan="5" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ t('No usage in this window.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-border px-4 py-3">
                    <Pagination :meta="perTenant.meta" @update:page="goToPage" @update:per-page="setPerPage" />
                </div>
            </Card>
        </div>
    </AdminLayout>
</template>
