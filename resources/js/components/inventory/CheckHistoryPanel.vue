<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import SidePanel from '@/components/ui/SidePanel.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatQuantity } from '@/lib/money';
import type { SharedProps } from '@/types';

export interface CheckHistoryEntry {
    id: string;
    reference: string;
    performed_by: string | null;
    items_counted: number;
    items_adjusted: number;
    net_difference: string;
    created_at: string | null;
}

/**
 * The count sheet's "History" affordance (Figma `271:12639`) — past
 * stocktakes, most recent first. The list itself (up to the last 10) is
 * already loaded with the page (InventoryController::check()); this only
 * needed the drawer to show it in, not a new endpoint.
 */
const props = defineProps<{ open: boolean; history: CheckHistoryEntry[] }>();
const emit = defineEmits<{ close: [] }>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { t } = useTranslations();

function dateTime(iso: string | null): string {
    if (iso === null) return '—';

    return new Date(iso).toLocaleString(locale.value, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** Sign-prefixed, so a shortfall (-) and a surplus (+) read at a glance. */
function netDifference(entry: CheckHistoryEntry): string {
    const value = Number.parseFloat(entry.net_difference);
    const formatted = formatQuantity(entry.net_difference, locale.value);

    return value > 0 ? `+${formatted}` : formatted;
}
</script>

<template>
    <SidePanel :open="open" :title="t('Check history')" @close="emit('close')">
        <div v-if="history.length === 0" class="py-12 text-center text-sm text-muted-foreground">
            {{ t('No stocktakes recorded yet.') }}
        </div>

        <ul v-else class="flex flex-col divide-y divide-border">
            <li v-for="entry in history" :key="entry.id" class="flex flex-col gap-1 py-4 first:pt-0 last:pb-0">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm font-medium text-foreground">{{ entry.reference }}</span>
                    <span
                        class="text-sm font-semibold tabular-nums"
                        :class="{
                            'text-destructive': Number.parseFloat(entry.net_difference) < 0,
                            'text-success': Number.parseFloat(entry.net_difference) > 0,
                        }"
                    >
                        {{ netDifference(entry) }}
                    </span>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ dateTime(entry.created_at) }}
                    <template v-if="entry.performed_by"> · {{ t('by :name', { name: entry.performed_by }) }}</template>
                </p>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(':counted items counted · :adjusted adjusted', {
                            counted: entry.items_counted,
                            adjusted: entry.items_adjusted,
                        })
                    }}
                </p>
            </li>
        </ul>
    </SidePanel>
</template>
