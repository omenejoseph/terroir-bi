<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { LifeBuoy, Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import NewTicketPanel from '@/components/support/NewTicketPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useTranslations } from '@/composables/useTranslations';
import { relativeNotificationTime } from '@/lib/notifications';
import { CATEGORY_LABELS, STATUS_LABELS, statusVariant } from '@/lib/support';
import type { SupportTicketRow } from '@/lib/support';
import type { SharedProps } from '@/types';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * Help & support (Web\SupportTicketController): the member's requests to the
 * platform's support team, newest activity first with finished ones last,
 * and the "New request" panel. `?new=1&from=/path` opens the panel straight
 * away, remembering the screen they came from.
 */
defineProps<{
    tickets: SupportTicketRow[];
    /** Organisation admins see everyone's requests, not only their own. */
    seesAll: boolean;
}>();

const page = usePage<SharedProps>();
const { t } = useTranslations();

const query = new URLSearchParams(page.url.split('?', 2)[1] ?? '');
const panelOpen = ref(query.get('new') === '1');
const from = query.get('from');
</script>

<template>
    <Head :title="t('Help & support')" />
    <div class="max-w-3xl space-y-5">
        <PageHeader
            :title="t('Help & support')"
            :description="seesAll ? t('Every request your organisation has sent to our support team.') : t('Your requests to our support team.')"
        >
            <template #actions>
                <Button size="sm" @click="panelOpen = true">
                    <Plus class="size-3.5" :stroke-width="1.5" />
                    {{ t('New request') }}
                </Button>
            </template>
        </PageHeader>

        <Card v-if="tickets.length === 0" class="flex flex-col items-center gap-3 p-10 text-center">
            <LifeBuoy class="size-8 text-muted-foreground" :stroke-width="1.5" />
            <p class="text-sm font-medium text-foreground">{{ t('Stuck on something?') }}</p>
            <p class="max-w-sm text-xs text-muted-foreground">
                {{ t('Send us a request and our team will answer here and by email — usually within one working day.') }}
            </p>
            <Button size="sm" @click="panelOpen = true">{{ t('Contact support') }}</Button>
        </Card>

        <ul v-else class="overflow-hidden rounded-lg border border-border bg-card">
            <li v-for="ticket in tickets" :key="ticket.id" class="border-b border-border last:border-b-0">
                <Link :href="`/support/${ticket.id}`" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 hover:bg-muted/40">
                    <span class="text-xs text-muted-foreground tabular-nums">#{{ ticket.number }}</span>
                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-foreground">{{ ticket.subject }}</span>
                    <Badge :variant="statusVariant(ticket.status)">{{ t(STATUS_LABELS[ticket.status]) }}</Badge>
                    <span class="w-full text-xs text-muted-foreground sm:w-auto">
                        {{ t(CATEGORY_LABELS[ticket.category]) }}
                        <template v-if="seesAll && ticket.requester"> · {{ ticket.requester }}</template>
                        · {{ relativeNotificationTime(ticket.last_message_at, page.props.locale) }}
                    </span>
                </Link>
            </li>
        </ul>

        <NewTicketPanel :open="panelOpen" :page-url="from" @close="panelOpen = false" />
    </div>
</template>
