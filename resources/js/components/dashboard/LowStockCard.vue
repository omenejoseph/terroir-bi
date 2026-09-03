<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

import Badge from '@/components/ui/Badge.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import { formatQuantity } from '@/lib/money';
import type { StockWatchItem } from '@/types/dashboard';
import type { SharedProps } from '@/types';

/**
 * "Low stock" (Figma `286:1024`): items actually below their minimum, worst
 * shortfall first, each as a "current/min" fraction with a progress bar.
 * `App\Queries\InventoryAnalyticsQuery::stockWatch()` does the ranking; the
 * badge count is the same items, so it can never disagree with the list below
 * it.
 */
const props = defineProps<{ items: StockWatchItem[] }>();

const page = usePage<SharedProps>();

const qty = (value: string) => formatQuantity(value, page.props.locale);

/** 'bottles' -> 'btl', 'cases' -> 'cs' — the design's abbreviations (`286:1035`, `286:1045`). */
const UNIT_ABBREV: Record<string, string> = { bottles: 'btl', cases: 'cs' };
const unitLabel = (unit: string) => UNIT_ABBREV[unit] ?? unit;

function pct(item: StockWatchItem): number {
    const min = Number.parseFloat(item.min);

    return min > 0 ? (Number.parseFloat(item.stock) / min) * 100 : 0;
}
</script>

<template>
    <div class="flex h-full flex-col border border-border bg-card p-4">
        <div class="flex items-center gap-1.5">
            <h3 class="text-sm font-semibold">Low stock</h3>
            <Badge v-if="items.length">{{ items.length }}</Badge>
        </div>

        <ul v-if="items.length" class="mt-4 flex-1 space-y-3">
            <li v-for="item in items" :key="item.name" class="space-y-1.5">
                <div class="flex items-baseline justify-between gap-3 text-xs">
                    <span class="truncate font-medium text-foreground">{{ item.name }}</span>
                    <span class="shrink-0 tabular-nums text-muted-foreground">
                        {{ qty(item.stock) }}/{{ qty(item.min) }} {{ unitLabel(item.unit) }}
                    </span>
                </div>
                <ProgressBar :value="pct(item)" :label="item.name" />
            </li>
        </ul>
        <p v-else class="mt-4 flex-1 text-xs text-muted-foreground">Nothing is below its minimum.</p>
    </div>
</template>
