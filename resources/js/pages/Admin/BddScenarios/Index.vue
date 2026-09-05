<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { PencilLine, Play, Plus, Search, Trash2 } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import BddScenarioFormPanel from '@/components/admin/BddScenarioFormPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminBddScenarioSummary } from '@/types/admin';
import type { Paginated } from '@/types';
import type { MenuItem } from '@/types/ui';

/**
 * BDD Scenarios — port of App\Filament\Resources\BddScenarios\**, an AI
 * test-runner UI. Filament's table polled every 10s (`wire:poll`); the
 * Inertia equivalent is a plain partial reload on an interval, since this is
 * infrequent enough that a full round-trip is cheap (see the plan's polling
 * decision — the 2s in-progress log on the Show page uses a JSON endpoint
 * instead, since that one is frequent enough to matter).
 */
const props = defineProps<{
    scenarios: Paginated<AdminBddScenarioSummary>;
    filters: { search: string | null };
}>();

const { t } = useTranslations();

const search = ref(props.filters.search ?? '');
const formOpen = ref(false);
const editing = ref<AdminBddScenarioSummary | null>(null);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reload({ search: value || undefined }), 300);
});

function reload(overrides: Record<string, unknown>): void {
    router.get(
        `${ADMIN_BASE}/bdd-scenarios`,
        {
            search: props.filters.search ?? undefined,
            per_page: props.scenarios.meta.per_page,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['scenarios', 'filters'] },
    );
}

/** Keeps the status/last-run badges fresh while queued runs execute. */
let poll: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    poll = setInterval(() => {
        router.reload({ only: ['scenarios'], showProgress: false });
    }, 10_000);
});

onBeforeUnmount(() => clearInterval(poll));

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

function edit(scenario: AdminBddScenarioSummary): void {
    editing.value = scenario;
    formOpen.value = true;
}

function run(scenario: AdminBddScenarioSummary): void {
    if (!confirm(t('Queue a background run: an AI agent executes the Gherkin live against a throwaway sandbox (always rolled back). Costs one AI call.'))) {
        return;
    }

    router.post(`${ADMIN_BASE}/bdd-scenarios/${scenario.id}/run`, {}, { preserveScroll: true });
}

function runAll(): void {
    if (!confirm(t('Queue a background run for every active scenario: an AI agent executes each Gherkin live against a throwaway sandbox (always rolled back). Costs one AI call per scenario.'))) {
        return;
    }

    router.post(`${ADMIN_BASE}/bdd-scenarios/run-all`, {}, { preserveScroll: true });
}

function rowActions(scenario: AdminBddScenarioSummary): MenuItem[] {
    return [
        { key: 'edit', label: t('Edit'), icon: PencilLine },
        { key: 'delete', label: t('Delete'), icon: Trash2, destructive: true, disabled: scenario.in_flight },
    ];
}

function onRowAction(key: string, scenario: AdminBddScenarioSummary): void {
    if (key === 'edit') {
        edit(scenario);

        return;
    }

    if (key === 'delete') destroy(scenario);
}

function destroy(scenario: AdminBddScenarioSummary): void {
    if (!confirm(t('Delete :title?', { title: scenario.title }))) return;

    router.delete(`${ADMIN_BASE}/bdd-scenarios/${scenario.id}`, { preserveScroll: true });
}

const STATUS_VARIANT: Record<string, 'success' | 'warning' | 'destructive' | 'neutral'> = {
    READY: 'success',
    NEEDS_ACCESS: 'warning',
    COMPILE_FAILED: 'destructive',
};

const RUN_STATUS_VARIANT: Record<string, 'success' | 'warning' | 'destructive' | 'neutral'> = {
    PASS: 'success',
    FAIL: 'destructive',
    ERROR: 'destructive',
    NEEDS_ACCESS: 'warning',
    QUEUED: 'neutral',
    RUNNING: 'neutral',
};

const statusVariant = computed(() => (status: string) => STATUS_VARIANT[status] ?? 'neutral');
const runStatusVariant = computed(() => (status: string) => RUN_STATUS_VARIANT[status] ?? 'neutral');
</script>

<template>
    <AdminLayout :title="t('BDD Scenarios')">
        <div class="space-y-5">
            <PageHeader :title="t('BDD Scenarios')">
                <template #actions>
                    <Button variant="outline" size="sm" @click="runAll">
                        <Play class="size-3.5" :stroke-width="1.5" />
                        {{ t('Run all') }}
                    </Button>
                    <Button size="sm" @click="create">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        {{ t('New scenario') }}
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
                    :placeholder="t('Filter by title…')"
                    :aria-label="t('Filter scenarios')"
                    class="h-8 w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
            </div>

            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[48rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Title') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Status') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Last run') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Active') }}</th>
                                <th scope="col" class="w-24 px-4 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in scenarios.data"
                                :key="row.id"
                                class="border-b border-border transition-colors last:border-b-0 hover:bg-muted/40"
                            >
                                <td class="px-4 py-3">
                                    <Link :href="`${ADMIN_BASE}/bdd-scenarios/${row.id}`" class="font-medium text-foreground hover:underline">
                                        {{ row.title }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3"><Badge :variant="statusVariant(row.status)">{{ row.status }}</Badge></td>
                                <td class="px-4 py-3">
                                    <Badge v-if="row.last_run_status" :variant="runStatusVariant(row.last_run_status)">
                                        {{ row.last_run_status }}
                                    </Badge>
                                    <span v-else class="text-muted-foreground">{{ t('— never —') }}</span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ row.is_active ? t('Yes') : t('No') }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <Button
                                            v-if="row.is_runnable"
                                            variant="ghost"
                                            size="sm"
                                            :disabled="row.in_flight"
                                            @click="run(row)"
                                        >
                                            <Play class="size-3.5" :stroke-width="1.5" />
                                        </Button>
                                        <DropdownMenu
                                            :items="rowActions(row)"
                                            :label="t('Actions for :title', { title: row.title })"
                                            @select="onRowAction($event, row)"
                                        />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="scenarios.data.length === 0">
                                <td colspan="5" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ t('No scenarios yet.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-border px-4 py-3">
                    <Pagination :meta="scenarios.meta" @update:page="goToPage" @update:per-page="setPerPage" />
                </div>
            </div>
        </div>

        <BddScenarioFormPanel :open="formOpen" :scenario="editing" @close="formOpen = false" />
    </AdminLayout>
</template>
