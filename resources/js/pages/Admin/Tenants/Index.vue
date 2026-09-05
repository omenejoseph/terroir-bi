<script setup lang="ts">
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Link as LinkIcon, Mail, Plus, Search } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import OnboardingLinkDialog from '@/components/admin/OnboardingLinkDialog.vue';
import TenantFormPanel from '@/components/admin/TenantFormPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminOption, AdminTenant } from '@/types/admin';
import type { Paginated } from '@/types';
import type { MenuItem } from '@/types/ui';

/** Tenants — port of App\Filament\Resources\Tenants\**. */
const props = defineProps<{
    tenants: Paginated<AdminTenant>;
    filters: { search: string | null };
    statusOptions: AdminOption[];
    planOptions: AdminOption[];
    currencyOptions: AdminOption[];
    localeOptions: AdminOption[];
}>();

const { t } = useTranslations();

const search = ref(props.filters.search ?? '');
const formOpen = ref(false);
const onboardingTenantId = ref<string | null>(null);

let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ search: value || undefined }), 300);
});

function reload(overrides: Record<string, unknown>): void {
    router.get(
        `${ADMIN_BASE}/tenants`,
        {
            search: props.filters.search ?? undefined,
            per_page: props.tenants.meta.per_page,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['tenants', 'filters'] },
    );
}

function goToPage(page: number): void {
    reload({ page });
}

function setPerPage(perPage: number): void {
    reload({ per_page: perPage, page: 1 });
}

function rowActions(tenant: AdminTenant): MenuItem[] {
    if (!tenant.needs_subscription) return [];

    return [
        { key: 'onboarding-link', label: t('Generate subscription link'), icon: LinkIcon },
        { key: 'email-link', label: t('Email subscription link'), icon: Mail },
    ];
}

function onRowAction(key: string, tenant: AdminTenant): void {
    if (key === 'onboarding-link') {
        onboardingTenantId.value = tenant.id;

        return;
    }

    if (key === 'email-link') emailLink(tenant);
}

function emailLink(tenant: AdminTenant): void {
    if (!confirm(t('Email a Stripe subscription link to :name?', { name: tenant.name }))) return;

    router.post(`${ADMIN_BASE}/tenants/${tenant.id}/email-billing-link`, {}, { preserveScroll: true });
}

const ACCESS_VARIANT: Record<string, 'success' | 'warning' | 'destructive'> = {
    full: 'success',
    read_only: 'warning',
    blocked: 'destructive',
};
</script>

<template>
    <AdminLayout :title="t('Tenants')">
        <div class="space-y-5">
            <PageHeader :title="t('Tenants')">
                <template #actions>
                    <Button size="sm" @click="formOpen = true">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        {{ t('New tenant') }}
                    </Button>
                </template>
            </PageHeader>

            <div class="relative w-full max-w-[280px]">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                    :stroke-width="1.5"
                />
                <input
                    v-model="search"
                    type="search"
                    :placeholder="t('Filter by name…')"
                    :aria-label="t('Filter tenants')"
                    class="h-8 w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
            </div>

            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[52rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Name') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Plan') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Status') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Access') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Stripe') }}</th>
                                <th scope="col" class="w-16 px-4 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in tenants.data"
                                :key="row.id"
                                class="border-b border-border transition-colors last:border-b-0 hover:bg-muted/40"
                            >
                                <td class="px-4 py-3">
                                    <Link :href="`${ADMIN_BASE}/tenants/${row.id}`" class="font-medium text-foreground hover:underline">
                                        {{ row.name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge v-if="row.plan" variant="outline">{{ row.plan.name }}</Badge>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-3"><Badge>{{ row.status }}</Badge></td>
                                <td class="px-4 py-3">
                                    <Badge :variant="ACCESS_VARIANT[row.access] ?? 'neutral'">{{ row.access }}</Badge>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ row.subscription?.stripe_status ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end">
                                        <DropdownMenu
                                            v-if="rowActions(row).length > 0"
                                            :items="rowActions(row)"
                                            :label="t('Actions for :name', { name: row.name })"
                                            @select="onRowAction($event, row)"
                                        />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="tenants.data.length === 0">
                                <td colspan="6" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ t('No tenants yet.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-border px-4 py-3">
                    <Pagination :meta="tenants.meta" @update:page="goToPage" @update:per-page="setPerPage" />
                </div>
            </div>
        </div>

        <TenantFormPanel
            :open="formOpen"
            :plan-options="planOptions"
            :currency-options="currencyOptions"
            :locale-options="localeOptions"
            @close="formOpen = false"
        />

        <OnboardingLinkDialog
            :open="onboardingTenantId !== null"
            :tenant-id="onboardingTenantId"
            @close="onboardingTenantId = null"
        />
    </AdminLayout>
</template>
