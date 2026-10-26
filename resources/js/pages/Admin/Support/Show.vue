<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import TicketThread from '@/components/support/TicketThread.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import InputError from '@/components/ui/InputError.vue';
import Select from '@/components/ui/Select.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import { CATEGORY_LABELS, PRIORITY_LABELS, STATUS_LABELS, statusVariant } from '@/lib/support';
import type { SupportTicketDetail, SupportTicketPriority, SupportTicketStatus } from '@/lib/support';

defineOptions({ layout: AdminLayout });

/**
 * One ticket in the platform support queue: the whole conversation, internal
 * notes included, a reply box that can also post an internal note, and the
 * triage controls (status, priority, assignee) — each saves on change.
 */
const props = defineProps<{
    ticket: SupportTicketDetail;
    admins: { value: string; label: string }[];
}>();

const { t } = useTranslations();

const statusOptions = computed(() =>
    (Object.keys(STATUS_LABELS) as SupportTicketStatus[]).map((value) => ({ value, label: t(STATUS_LABELS[value]) })),
);
const priorityOptions = computed(() =>
    (Object.keys(PRIORITY_LABELS) as SupportTicketPriority[]).map((value) => ({ value, label: t(PRIORITY_LABELS[value]) })),
);
const assigneeOptions = computed(() => [{ value: '', label: t('Unassigned') }, ...props.admins]);

function update(changes: Record<string, string | null>): void {
    router.patch(`${ADMIN_BASE}/support/${props.ticket.id}`, changes, { preserveScroll: true });
}

const form = useForm({ body: '', internal: false });

function send(): void {
    form.post(`${ADMIN_BASE}/support/${props.ticket.id}/replies`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head :title="`#${ticket.number} ${ticket.subject}`" />
    <div class="space-y-5">
        <Button variant="ghost" size="sm" :href="`${ADMIN_BASE}/support`">
            <ArrowLeft class="size-3.5" :stroke-width="1.5" />
            {{ t('Support queue') }}
        </Button>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground">#{{ ticket.number }} · {{ t(CATEGORY_LABELS[ticket.category]) }}</p>
                <h2 class="text-xl font-semibold text-foreground">{{ ticket.subject }}</h2>
            </div>
            <Badge :variant="statusVariant(ticket.status)">{{ t(STATUS_LABELS[ticket.status]) }}</Badge>
        </div>

        <div class="grid gap-5 lg:grid-cols-[1fr_18rem]">
            <div class="min-w-0 space-y-4">
                <TicketThread :messages="ticket.messages" />

                <Card :class="form.internal ? 'space-y-3 border-dashed p-4' : 'space-y-3 p-4'">
                    <form class="space-y-3" @submit.prevent="send">
                        <Textarea
                            v-model="form.body"
                            :rows="5"
                            maxlength="10000"
                            :placeholder="form.internal ? t('Note for the team — the customer won\'t see this') : t('Reply to the customer…')"
                            :aria-label="t('Reply')"
                        />
                        <InputError :message="form.errors.body" />
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <label class="flex items-center gap-2 text-xs text-muted-foreground">
                                <input v-model="form.internal" type="checkbox" class="accent-primary" />
                                {{ t('Internal note') }}
                            </label>
                            <Button type="submit" size="sm" :disabled="form.processing || form.body.trim() === ''">
                                {{ form.internal ? t('Add note') : t('Send reply') }}
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>

            <aside class="space-y-4">
                <Card class="space-y-3 p-4 text-sm">
                    <div>
                        <p class="mb-1 text-xs text-muted-foreground">{{ t('Status') }}</p>
                        <Select :model-value="ticket.status" :options="statusOptions" @update:model-value="(v: string) => update({ status: v })" />
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-muted-foreground">{{ t('Priority') }}</p>
                        <Select :model-value="ticket.priority" :options="priorityOptions" @update:model-value="(v: string) => update({ priority: v })" />
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-muted-foreground">{{ t('Assignee') }}</p>
                        <Select
                            :model-value="ticket.assignee_id ?? ''"
                            :options="assigneeOptions"
                            @update:model-value="(v: string) => update({ assignee_id: v === '' ? null : v })"
                        />
                    </div>
                </Card>

                <Card class="space-y-2 p-4 text-sm">
                    <div>
                        <p class="text-xs text-muted-foreground">{{ t('Tenant') }}</p>
                        <Link v-if="ticket.tenant" :href="`${ADMIN_BASE}/tenants/${ticket.tenant.id}`" class="text-foreground underline underline-offset-2">
                            {{ ticket.tenant.name }}
                        </Link>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">{{ t('Requested by') }}</p>
                        <p class="text-foreground">{{ ticket.requester ?? '—' }}</p>
                        <a v-if="ticket.requester_email" :href="`mailto:${ticket.requester_email}`" class="text-xs text-muted-foreground underline underline-offset-2">
                            {{ ticket.requester_email }}
                        </a>
                    </div>
                    <div v-if="ticket.page_url">
                        <p class="text-xs text-muted-foreground">{{ t('Raised from') }}</p>
                        <code class="text-xs break-all text-foreground">{{ ticket.page_url }}</code>
                    </div>
                </Card>
            </aside>
        </div>
    </div>
</template>
