<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Select from '@/components/ui/Select.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import { relativeNotificationTime } from '@/lib/notifications';
import { PRIORITY_LABELS, STATUS_LABELS, statusVariant } from '@/lib/support';
import type { SupportTicketPriority, SupportTicketRow, SupportTicketStatus } from '@/lib/support';
import type { Paginated, SharedProps } from '@/types';
import type { TabItem } from '@/types/ui';

defineOptions({ layout: AdminLayout });

/**
 * The platform support queue (Web\Admin\SupportTicketController): every
 * tenant's tickets, active ones by default, urgent first and then whatever
 * has waited longest since its last message.
 */
const props = defineProps<{
    tickets: Paginated<SupportTicketRow>;
    filters: { status: string; assignee: string; priority: string; search: string };
    counts: { active: number; unassigned: number };
}>();

const page = usePage<SharedProps>();
const { t } = useTranslations();

const STATUS_TABS = computed<TabItem[]>(() => [
    { value: 'active', label: `${t('Active')} (${props.counts.active})` },
    { value: 'open', label: t(STATUS_LABELS.open) },
    { value: 'in_progress', label: t(STATUS_LABELS.in_progress) },
    { value: 'waiting_on_customer', label: t(STATUS_LABELS.waiting_on_customer) },
    { value: 'resolved', label: t(STATUS_LABELS.resolved) },
    { value: 'closed', label: t(STATUS_LABELS.closed) },
    { value: 'all', label: t('All') },
]);

const assigneeOptions = computed(() => [
    { value: '', label: t('Anyone') },
    { value: 'me', label: t('Assigned to me') },
    { value: 'none', label: `${t('Unassigned')} (${props.counts.unassigned})` },
]);

const priorityOptions = computed(() => [
    { value: '', label: t('Any priority') },
    ...(Object.keys(PRIORITY_LABELS) as SupportTicketPriority[]).map((p) => ({ value: p, label: t(PRIORITY_LABELS[p]) })),
]);

const search = ref(props.filters.search);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ search: value || undefined, page: 1 }), 300);
});

function reload(overrides: Record<string, unknown>): void {
    router.get(
        `${ADMIN_BASE}/support`,
        {
            status: props.filters.status,
            assignee: props.filters.assignee || undefined,
            priority: props.filters.priority || undefined,
            search: props.filters.search || undefined,
            per_page: props.tickets.meta.per_page,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['tickets', 'filters', 'counts'] },
    );
}

function priorityVariant(priority: SupportTicketPriority): 'destructive' | 'warning' | 'neutral' {
    return priority === 'urgent' ? 'destructive' : priority === 'high' ? 'warning' : 'neutral';
}
</script>

<template>
    <Head :title="t('Support')" />
    <div class="space-y-5">
        <PageHeader :title="t('Support')" :description="t('Requests from every tenant. Urgent first, then whoever has waited longest.')" />

        <Tabs :items="STATUS_TABS" :current="filters.status" @select="(value: string) => reload({ status: value, page: 1 })" />

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative w-full max-w-xs">
                <Search class="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" :stroke-width="1.5" />
                <input
                    v-model="search"
                    type="search"
                    class="h-8 w-full rounded-lg border border-input bg-transparent pr-3 pl-8 text-sm"
                    :placeholder="t('Search #number, subject or tenant')"
                />
            </div>
            <Select
                :model-value="filters.assignee"
                :options="assigneeOptions"
                class="w-44"
                @update:model-value="(value: string) => reload({ assignee: value || undefined, page: 1 })"
            />
            <Select
                :model-value="filters.priority"
                :options="priorityOptions"
                class="w-40"
                @update:model-value="(value: string) => reload({ priority: value || undefined, page: 1 })"
            />
        </div>

        <div class="overflow-hidden border border-border bg-card">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[56rem] text-xs">
                    <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">#</th>
                            <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Subject') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Tenant') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Status') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Priority') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Assignee') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Last activity') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="ticket in tickets.data"
                            :key="ticket.id"
                            class="cursor-pointer border-b border-border last:border-b-0 hover:bg-muted/40"
                            @click="router.visit(`${ADMIN_BASE}/support/${ticket.id}`)"
                        >
                            <td class="px-4 py-3 text-muted-foreground tabular-nums">{{ ticket.number }}</td>
                            <td class="px-4 py-3">
                                <Link :href="`${ADMIN_BASE}/support/${ticket.id}`" class="block text-sm font-medium text-foreground" @click.stop>
                                    {{ ticket.subject }}
                                </Link>
                                <span class="text-muted-foreground">{{ ticket.requester ?? '—' }}</span>
                            </td>
                            <td class="px-4 py-3">{{ ticket.tenant?.name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="statusVariant(ticket.status as SupportTicketStatus)">{{ t(STATUS_LABELS[ticket.status]) }}</Badge>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :variant="priorityVariant(ticket.priority)">{{ t(PRIORITY_LABELS[ticket.priority]) }}</Badge>
                            </td>
                            <td class="px-4 py-3">{{ ticket.assignee ?? t('Unassigned') }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ relativeNotificationTime(ticket.last_message_at, page.props.locale) }}</td>
                        </tr>
                        <tr v-if="tickets.data.length === 0">
                            <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">{{ t('No tickets here.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="border-t border-border">
                <Pagination
                    :meta="tickets.meta"
                    @update:page="(p: number) => reload({ page: p })"
                    @update:per-page="(n: number) => reload({ per_page: n, page: 1 })"
                />
            </div>
        </div>
    </div>
</template>
