<script setup lang="ts">
import { router } from '@inertiajs/vue3';

import AdminLayout from '@/layouts/AdminLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import { formatActionLabel, formatMetadata } from '@/lib/auditLog';
import type { AdminAuditLog } from '@/types/admin';
import type { Paginated } from '@/types';

/**
 * A read-only, reverse-chronological view of `audit_logs` — currently written
 * only by this task's security-sensitive actions (suspend/unsuspend, a
 * password-reset trigger, impersonation start/stop). See
 * App\Services\Audit\AuditLogger's docblock: broader "log every action"
 * instrumentation is a separate, later task — this page grows to cover it
 * then rather than being rebuilt.
 */
const props = defineProps<{
    logs: Paginated<AdminAuditLog>;
}>();

const { t } = useTranslations();

function goToPage(page: number): void {
    router.get(`${ADMIN_BASE}/audit-logs`, { page }, { preserveState: true, preserveScroll: true, replace: true });
}

function setPerPage(perPage: number): void {
    router.get(
        `${ADMIN_BASE}/audit-logs`,
        { per_page: perPage, page: 1 },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function subject(row: AdminAuditLog): string {
    if (row.subject_type === null || row.subject_id === null) return '—';

    // Short class name only — App\Models\User → User.
    const type = row.subject_type.split('\\').pop() ?? row.subject_type;

    return `${type} #${row.subject_id}`;
}
</script>

<template>
    <AdminLayout :title="t('Audit Logs')">
        <div class="space-y-5">
            <PageHeader :title="t('Audit Logs')" />

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
                                <td class="px-4 py-3 text-muted-foreground">{{ subject(row) }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ formatMetadata(row.metadata) }}</td>
                            </tr>

                            <tr v-if="logs.data.length === 0">
                                <td colspan="5" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ t('No audit log entries yet.') }}
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
    </AdminLayout>
</template>
