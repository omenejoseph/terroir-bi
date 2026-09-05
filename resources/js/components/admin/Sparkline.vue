<script setup lang="ts">
import { computed } from 'vue';

/**
 * A minimal inline trend line — the "Orders (30d)" stat card's per-day series
 * (Filament's Stat::chart()). Deliberately simpler than AreaChart.vue: no
 * axes, no labels, just a shape. Same "no charting dependency" approach as
 * AreaChart/BarChart.
 */
const props = withDefaults(defineProps<{ values: number[]; height?: number }>(), { height: 32 });

const W = 96;

const max = computed(() => Math.max(1, ...props.values));

const points = computed(() =>
    props.values
        .map((value, i) => {
            const x = props.values.length <= 1 ? 0 : (i / (props.values.length - 1)) * W;
            const y = props.height - (value / max.value) * props.height;

            return `${x},${y}`;
        })
        .join(' '),
);
</script>

<template>
    <svg :viewBox="`0 0 ${W} ${height}`" :style="{ height: `${height}px` }" class="w-full" role="presentation">
        <polyline :points="points" fill="none" stroke="var(--color-primary)" stroke-width="1.5" />
    </svg>
</template>
