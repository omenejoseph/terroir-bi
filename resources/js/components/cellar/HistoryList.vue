<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import Card from '@/components/ui/Card.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatActionLabel, formatMetadata } from '@/lib/auditLog';
import type { HistoryEntry } from '@/types/cellar';
import type { SharedProps } from '@/types';

/** A lot's or vessel's audit trail: when, who, what, and the details recorded. Same wording as the Logs page. */
defineProps<{ entries: HistoryEntry[] | undefined }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const locale = computed(() => page.props.locale);
</script>

<template>
    <Card>
        <p v-if="entries === undefined" class="p-8 text-center text-xs text-muted-foreground">{{ t('Loading…') }}</p>
        <div v-for="h in entries ?? []" :key="h.id" class="flex flex-col gap-0.5 border-b border-border px-3 py-2 text-xs last:border-0 sm:flex-row sm:gap-3">
            <span class="shrink-0 text-muted-foreground sm:w-40">{{ h.created_at ? new Date(h.created_at).toLocaleString(locale) : '' }}</span>
            <span class="shrink-0 truncate sm:w-32">{{ h.actor_name ?? t('System') }}</span>
            <span class="shrink-0 font-medium sm:w-56">{{ formatActionLabel(h.action) }}</span>
            <span class="min-w-0 flex-1 truncate text-muted-foreground" :title="formatMetadata(h.metadata)">{{ formatMetadata(h.metadata) }}</span>
        </div>
        <p v-if="entries && entries.length === 0" class="p-8 text-center text-xs text-muted-foreground">{{ t('No changes recorded yet.') }}</p>
    </Card>
</template>
