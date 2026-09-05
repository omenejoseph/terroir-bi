<script setup lang="ts">
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { PencilLine, Plus, Search, Trash2 } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import PlanFormPanel from '@/components/admin/PlanFormPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminOption, AdminPlan } from '@/types/admin';
import type { Paginated } from '@/types';
import type { MenuItem } from '@/types/ui';

/** Plans — port of App\Filament\Resources\Plans\**. */
const props = defineProps<{
    plans: Paginated<AdminPlan>;
    filters: { search: string | null };
    moduleOptions: AdminOption[];
}>();

const { t } = useTranslations();

const search = ref(props.filters.search ?? '');
const formOpen = ref(false);
const editing = ref<AdminPlan | null>(null);

let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ search: value || undefined }), 300);
});

function reload(overrides: Record<string, unknown>): void {
    router.get(
        `${ADMIN_BASE}/plans`,
        {
            search: props.filters.search ?? undefined,
            per_page: props.plans.meta.per_page,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['plans', 'filters'] },
    );
}

function goToPage(page: number): void {
    reload({ page });
}

function setPerPage(perPage: number): void {
    reload({ per_page: perPage, page: 1 });
}

function create(): void {
    editing.value = null;
    formOpen.value = true;
}

function edit(plan: AdminPlan): void {
    editing.value = plan;
    formOpen.value = true;
}

const rowActions: MenuItem[] = [
    { key: 'edit', label: t('Edit'), icon: PencilLine },
    { key: 'delete', label: t('Delete'), icon: Trash2, destructive: true },
];

function onRowAction(key: string, plan: AdminPlan): void {
    if (key === 'edit') {
        edit(plan);

        return;
    }

    if (key === 'delete') destroy(plan);
}

function destroy(plan: AdminPlan): void {
    if (!confirm(t('Delete :name? Tenants on this plan keep their subscription but lose their plan assignment.', { name: plan.name }))) {
        return;
    }

    router.delete(`${ADMIN_BASE}/plans/${plan.id}`, { preserveScroll: true });
}

function price(plan: AdminPlan): string {
    return plan.price_major === null ? t('— free —') : `${plan.price_major} ${plan.currency}`;
}
</script>

<template>
    <AdminLayout :title="t('Plans')">
        <div class="space-y-5">
            <PageHeader :title="t('Plans')">
                <template #actions>
                    <Button size="sm" @click="create">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        {{ t('New plan') }}
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
                    :placeholder="t('Filter by name or slug…')"
                    :aria-label="t('Filter plans')"
                    class="h-8 w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
            </div>

            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[56rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Name') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Modules') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">{{ t('Price') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">{{ t('Trial') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Active') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">{{ t('Tenants') }}</th>
                                <th scope="col" class="w-16 px-4 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in plans.data"
                                :key="row.id"
                                class="border-b border-border transition-colors last:border-b-0 hover:bg-muted/40"
                            >
                                <td class="px-4 py-3">
                                    <Link :href="`${ADMIN_BASE}/plans/${row.id}`" class="font-medium text-foreground hover:underline">
                                        {{ row.name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <Badge v-for="m in row.modules.slice(0, 3)" :key="m">{{ m }}</Badge>
                                        <span v-if="row.modules.length > 3" class="text-muted-foreground">
                                            +{{ row.modules.length - 3 }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ price(row) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ row.trial_days }}</td>
                                <td class="px-4 py-3">
                                    <Badge :variant="row.is_active ? 'success' : 'neutral'">
                                        {{ row.is_active ? t('Active') : t('Inactive') }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ row.tenants_count }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end">
                                        <DropdownMenu
                                            :items="rowActions"
                                            :label="t('Actions for :name', { name: row.name })"
                                            @select="onRowAction($event, row)"
                                        />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="plans.data.length === 0">
                                <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ t('No plans yet.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-border px-4 py-3">
                    <Pagination :meta="plans.meta" @update:page="goToPage" @update:per-page="setPerPage" />
                </div>
            </div>
        </div>

        <PlanFormPanel :open="formOpen" :plan="editing" :module-options="moduleOptions" @close="formOpen = false" />
    </AdminLayout>
</template>
