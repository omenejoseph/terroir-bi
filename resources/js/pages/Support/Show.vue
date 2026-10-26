<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import TicketThread from '@/components/support/TicketThread.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import InputError from '@/components/ui/InputError.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { CATEGORY_LABELS, STATUS_LABELS, statusVariant } from '@/lib/support';
import type { SupportTicketDetail } from '@/lib/support';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * One support request: the conversation with the support team, a reply box,
 * and "Mark as resolved". Replying to a resolved request reopens it; a
 * closed one can't be replied to (support closes those).
 */
const props = defineProps<{ ticket: SupportTicketDetail }>();

const { t } = useTranslations();

const form = useForm({ body: '' });

function reply(): void {
    form.post(`/support/${props.ticket.id}/replies`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function resolve(): void {
    router.post(`/support/${props.ticket.id}/resolve`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`#${ticket.number} ${ticket.subject}`" />
    <div class="max-w-3xl space-y-5">
        <Button variant="ghost" size="sm" href="/support">
            <ArrowLeft class="size-3.5" :stroke-width="1.5" />
            {{ t('All requests') }}
        </Button>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground">#{{ ticket.number }} · {{ t(CATEGORY_LABELS[ticket.category]) }}</p>
                <h2 class="text-xl font-semibold text-foreground">{{ ticket.subject }}</h2>
            </div>
            <div class="flex items-center gap-2">
                <Badge :variant="statusVariant(ticket.status)">{{ t(STATUS_LABELS[ticket.status]) }}</Badge>
                <Button v-if="ticket.status !== 'resolved' && ticket.status !== 'closed'" variant="outline" size="sm" @click="resolve">
                    <CheckCircle2 class="size-3.5" :stroke-width="1.5" />
                    {{ t('Mark as resolved') }}
                </Button>
            </div>
        </div>

        <TicketThread :messages="ticket.messages" />

        <Card v-if="ticket.status !== 'closed'" class="space-y-3 p-4">
            <form class="space-y-3" @submit.prevent="reply">
                <Textarea v-model="form.body" :rows="4" maxlength="10000" :placeholder="t('Write a reply…')" :aria-label="t('Reply')" />
                <InputError :message="form.errors.body" />
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs text-muted-foreground">
                        {{ ticket.status === 'resolved' ? t('Replying reopens this request.') : t('We\'ll email you when support answers.') }}
                    </p>
                    <Button type="submit" size="sm" :disabled="form.processing || form.body.trim() === ''">
                        {{ form.processing ? t('Sending…') : t('Send reply') }}
                    </Button>
                </div>
            </form>
        </Card>
        <p v-else class="text-xs text-muted-foreground">{{ t('This request is closed. Open a new one if you still need help.') }}</p>
    </div>
</template>
