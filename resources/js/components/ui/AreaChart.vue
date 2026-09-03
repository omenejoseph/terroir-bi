<script setup lang="ts">
import { computed } from 'vue';

/**
 * Figma's revenue trend (node `208:6043`) — a filled area under a line, one
 * series over a linear scale. Sibling to `BarChart.vue` and built the same
 * way for the same reason: a couple of points on one axis is not worth a
 * charting dependency.
 */
const props = withDefaults(
    defineProps<{
        points: { label: string; value: number }[];
        height?: number;
    }>(),
    { height: 160 },
);

const W = 720;
const PAD = { top: 8, right: 8, bottom: 22, left: 8 };

const max = computed(() => Math.max(1, ...props.points.map((p) => p.value)));

/** Round the axis top to a friendly number so the fill never clips a peak. */
const ceiling = computed(() => {
    const magnitude = 10 ** Math.floor(Math.log10(max.value));
    const step = magnitude / 2 || 1;

    return Math.ceil((max.value * 1.05) / step) * step;
});

const plotH = computed(() => props.height - PAD.top - PAD.bottom);
const plotW = W - PAD.left - PAD.right;

const y = (value: number) => PAD.top + plotH.value - (value / ceiling.value) * plotH.value;
const x = (index: number) =>
    PAD.left + (props.points.length <= 1 ? plotW / 2 : (index / (props.points.length - 1)) * plotW);

const linePath = computed(() =>
    props.points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${x(i)} ${y(p.value)}`).join(' '),
);

const areaPath = computed(() => {
    if (props.points.length === 0) return '';

    const baseline = y(0);

    return `${linePath.value} L ${x(props.points.length - 1)} ${baseline} L ${x(0)} ${baseline} Z`;
});
</script>

<template>
    <svg
        :viewBox="`0 0 ${W} ${height}`"
        :style="{ height: `${height}px` }"
        class="w-full"
        role="img"
        aria-label="Revenue by month"
    >
        <defs>
            <linearGradient id="area-chart-fill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="var(--color-foreground)" stop-opacity="0.16" />
                <stop offset="100%" stop-color="var(--color-foreground)" stop-opacity="0" />
            </linearGradient>
        </defs>

        <line
            :x1="PAD.left"
            :x2="W - PAD.right"
            :y1="y(0)"
            :y2="y(0)"
            stroke="var(--color-border)"
            stroke-width="1"
        />

        <path :d="areaPath" fill="url(#area-chart-fill)" />
        <path :d="linePath" fill="none" stroke="var(--color-foreground)" stroke-width="1.5" />

        <g v-for="(point, i) in points" :key="point.label">
            <circle :cx="x(i)" :cy="y(point.value)" r="2" fill="var(--color-foreground)" />
            <text
                :x="x(i)"
                :y="height - 4"
                text-anchor="middle"
                font-size="11"
                fill="var(--color-muted-foreground)"
            >
                {{ point.label }}
            </text>
        </g>
    </svg>
</template>
