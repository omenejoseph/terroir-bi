<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';

import { useTranslations } from '@/composables/useTranslations';
import type { SupportTicketMessage } from '@/lib/support';
import type { SharedProps } from '@/types';

/**
 * A support ticket's conversation, oldest first. Support's messages sit on a
 * tinted card so the two sides read apart at a glance; internal notes (admin
 * side only — the tenant never receives them) are marked and dashed.
 */
defineProps<{ messages: SupportTicketMessage[] }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();

const formatter = computed(() => new Intl.DateTimeFormat(page.props.locale, { dateStyle: 'medium', timeStyle: 'short' }));

function when(iso: string | null): string {
    return iso === null ? '' : formatter.value.format(new Date(iso));
}
</script>

<template>
    <ol class="space-y-3">
        <li
            v-for="message in messages"
            :key="message.id"
            class="rounded-lg border p-4"
            :class="
                message.is_internal
                    ? 'border-dashed border-border bg-muted/30'
                    : message.is_staff
                      ? 'border-border bg-muted/50'
                      : 'border-border bg-card'
            "
        >
            <div class="mb-2 flex flex-wrap items-center gap-2 text-xs">
                <span class="font-medium text-foreground">{{ message.author ?? t('Unknown user') }}</span>
                <span v-if="message.is_staff && !message.is_internal" class="text-muted-foreground">· {{ t('Support team') }}</span>
                <span v-if="message.is_internal" class="inline-flex items-center gap-1 text-muted-foreground">
                    · <Lock class="size-3" :stroke-width="1.5" /> {{ t('Internal note') }}
                </span>
                <span class="ml-auto text-muted-foreground">{{ when(message.created_at) }}</span>
            </div>
            <p class="text-sm whitespace-pre-wrap text-foreground">{{ message.body }}</p>
        </li>
    </ol>
</template>
