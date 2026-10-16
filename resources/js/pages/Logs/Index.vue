<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { formatActionLabel, formatMetadata } from '@/lib/auditLog';
import type { TenantAuditLog } from '@/types/logs';
import type { Paginated } from '@/types';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * A tenant's own audit trail — the per-tenant counterpart of
 * Admin/AuditLogs/Index.vue, which is platform-wide. Same table shape; scoped
 * server-side to this tenant only (see Web\LogController).
 */
const props = defineProps<{
    logs: Paginated<TenantAuditLog> & { filters?: { area: string | null; subject_id: string | null } };
}>();

const { t } = useTranslations();
const { hasModule } = useAuth();

/** The area filter rides along with paging, so page 2 of "Cellar" stays Cellar. */
const area = computed(() => props.logs.filters?.area ?? null);
const query = (extra: Record<string, unknown>) => ({ ...(area.value ? { area: area.value } : {}), ...extra });

function goToPage(page: number): void {
    router.get('/logs', query({ page }), { preserveState: true, preserveScroll: true, replace: true });
}

function setPerPage(perPage: number): void {
    router.get('/logs', query({ per_page: perPage, page: 1 }), { preserveState: true, preserveScroll: true, replace: true });
}

function setArea(value: string): void {
    router.get('/logs', value === 'all' ? {} : { area: value }, { preserveState: true, preserveScroll: true, replace: true });
}

/** Cellar subjects link to their page; a lot's child records (analyses, additions…) have no page of their own. */
function subjectHref(row: TenantAuditLog): string | null {
    const type = row.subject_type?.split('\\').pop() ?? '';
    if (row.subject_id === null) return null;
    if (type === 'WineLot') return `/cellar/lots/${row.subject_id}`;
    if (type === 'Vessel') return `/cellar/vessels/${row.subject_id}`;

    return null;
}

function subject(row: TenantAuditLog): string {
    if (row.subject_type === null || row.subject_id === null) return '—';

    // Short class name only — App\Models\Order → Order.
    const type = row.subject_type.split('\\').pop() ?? row.subject_type;

    return `${type} #${row.subject_id}`;
}
</script>

<template>
    <Head :title="t('Logs')" />
        <div class="space-y-5">
            <PageHeader :title="t('Logs')" />
            <Tabs
                v-if="hasModule('cellar')"
                :items="[
                    { label: t('All'), value: 'all' },
                    { label: t('Cellar'), value: 'cellar' },
                ]"
                :current="area ?? 'all'"
                @select="setArea"
            />

            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[64rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('When') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Actor') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Action') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Subject') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Details') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in logs.data"
                                :key="row.id"
                                class="border-b border-border transition-colors last:border-b-0 hover:bg-muted/40"
                            >
                                <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                    {{ row.created_at ? new Date(row.created_at).toLocaleString() : '—' }}
                                </td>
                                <td class="px-4 py-3 text-foreground">{{ row.actor_name ?? '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-foreground">{{ formatActionLabel(row.action) }}</td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    <Link v-if="subjectHref(row)" :href="subjectHref(row)!" class="underline-offset-2 hover:underline">{{ subject(row) }}</Link>
                                    <template v-else>{{ subject(row) }}</template>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ formatMetadata(row.metadata) }}</td>
                            </tr>

                            <tr v-if="logs.data.length === 0">
                                <td colspan="5" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ t('No log entries yet.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-border px-4 py-3">
                    <Pagination :meta="logs.meta" @update:page="goToPage" @update:per-page="setPerPage" />
                </div>
            </div>
        </div>
</template>
