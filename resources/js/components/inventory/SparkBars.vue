<script setup lang="ts">
import { computed } from 'vue';

import { useTranslations } from '@/composables/useTranslations';

/**
 * The daily exit histogram on Product Detail (Figma `449:1577`,
 * `ChartContainer`). Bars above the mean are emphasised, the rest sit back —
 * matching the design, where a handful of tall bars read darker.
 *
 * Deliberately CSS rather than a charting library: it is one series of counts
 * with no axes, so a dependency would cost more than it explains.
 */
const props = defineProps<{
    values: number[];
    unit: string;
    /** A slice of each bar to draw dark (e.g. the Internal / POS bottles), aligned with `values`. */
    highlight?: number[];
    /** One tooltip heading per bar (usually its date or date range). */
    labels?: string[];
    /** Name of what the dark slice is, for the legend (e.g. "Internal / POS"). */
    highlightLabel?: string;
    /** Name of the rest of the bar, for the legend. */
    restLabel?: string;
}>();
const { t } = useTranslations();

function tip(i: number, value: number): string {
    const head = props.labels?.[i];
    const dark = props.highlight?.[i] ?? 0;
    const body = props.highlight
        ? `${value} ${props.unit} · ${dark} ${props.highlightLabel ?? ''}`.trim()
        : `${value} ${props.unit}`;

    return head ? `${head}: ${body}` : body;
}

const max = computed(() => Math.max(1, ...props.values));
const mean = computed(() =>
    props.values.length ? props.values.reduce((a, b) => a + b, 0) / props.values.length : 0,
);
</script>

<template>
    <div v-if="values.length" class="flex flex-col gap-2">
        <div class="flex h-20 items-end gap-[3px]" role="img" :aria-label="t('Daily :unit exited', { unit })">
            <div
                v-for="(value, i) in values"
                :key="i"
                class="flex min-h-px flex-1 flex-col justify-end"
                :class="!highlight && (value > mean ? 'bg-foreground' : 'bg-muted-foreground/30')"
                :style="{ height: `${(value / max) * 100}%` }"
                :title="tip(i, value)"
            >
                <!-- With a highlight series the bar is light and its highlighted slice sits dark at the base. -->
                <template v-if="highlight">
                    <div class="min-h-px flex-1 bg-muted-foreground/30" />
                    <div
                        v-if="(highlight[i] ?? 0) > 0"
                        class="shrink-0 bg-foreground"
                        :style="{ height: `${Math.min(100, ((highlight[i] ?? 0) / Math.max(value, 1)) * 100)}%` }"
                    />
                </template>
            </div>
        </div>

        <p v-if="highlight" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-2xs text-muted-foreground">
            <span class="inline-flex items-center gap-1.5"><span class="size-2 bg-foreground" aria-hidden="true" />{{ highlightLabel }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2 bg-muted-foreground/30" aria-hidden="true" />{{ restLabel }}</span>
        </p>
    </div>
    <p v-else class="py-6 text-sm text-muted-foreground">{{ t('No movements in this period.') }}</p>
</template>
