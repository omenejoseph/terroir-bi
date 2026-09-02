<script setup lang="ts">
import { computed } from 'vue';

/**
 * The daily exit histogram on Product Detail (Figma `449:1577`,
 * `ChartContainer`). Bars above the mean are emphasised, the rest sit back —
 * matching the design, where a handful of tall bars read darker.
 *
 * Deliberately CSS rather than a charting library: it is one series of counts
 * with no axes, so a dependency would cost more than it explains.
 */
const props = defineProps<{ values: number[]; unit: string }>();

const max = computed(() => Math.max(1, ...props.values));
const mean = computed(() =>
    props.values.length ? props.values.reduce((a, b) => a + b, 0) / props.values.length : 0,
);
</script>

<template>
    <div v-if="values.length" class="flex h-20 items-end gap-[3px]" role="img" :aria-label="`Daily ${unit} exited`">
        <div
            v-for="(value, i) in values"
            :key="i"
            class="min-h-px flex-1 rounded-[2px]"
            :class="value > mean ? 'bg-foreground' : 'bg-muted-foreground/30'"
            :style="{ height: `${(value / max) * 100}%` }"
            :title="`${value}`"
        />
    </div>
    <p v-else class="py-6 text-sm text-muted-foreground">No movements in this period.</p>
</template>
