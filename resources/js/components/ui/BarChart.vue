<script setup lang="ts">
import { computed } from 'vue';

import { cn } from '@/lib/cn';

/**
 * Figma component `ChartContainer` — the axis-and-bars treatment the design
 * uses for both the 12-month in/out series (`382:1592`) and the daily exit
 * histogram (`386:1673`). One component with one or two series rather than two
 * charts, so the axis, ticks and label logic cannot drift apart.
 *
 * Hand-authored SVG on purpose: a couple of series over a linear scale, where a
 * charting dependency would weigh more than it explains. One scale places the
 * bars, the gridlines and the labels, so a label always names a value the chart
 * actually reaches.
 */
const props = withDefaults(
    defineProps<{
        /** Each point's `label` is the x-axis tick; `values` holds 1 or 2 series. */
        points: { label: string; values: number[] }[];
        /** Legend entries, one per series. Omit to hide the legend. */
        series?: string[];
        height?: number;
    }>(),
    { height: 240 },
);

const W = 720;
const PAD = { top: 12, right: 8, bottom: 34, left: 46 };

const seriesCount = computed(() => Math.max(1, ...props.points.map((p) => p.values.length)));
const max = computed(() => Math.max(1, ...props.points.flatMap((p) => p.values)));

/** Round the axis top to a friendly number so ticks read cleanly. */
const ceiling = computed(() => {
    const magnitude = 10 ** Math.floor(Math.log10(max.value));
    const step = magnitude / 2 || 1;

    return Math.ceil(max.value / step) * step;
});

const ticks = computed(() => Array.from({ length: 5 }, (_, i) => Math.round((ceiling.value / 4) * i)));

const plotH = computed(() => props.height - PAD.top - PAD.bottom);
const plotW = W - PAD.left - PAD.right;

const y = (value: number) => PAD.top + plotH.value - (value / ceiling.value) * plotH.value;
const bandW = computed(() => plotW / Math.max(1, props.points.length));

function bar(index: number, series: number) {
    const band = bandW.value;
    const groupW = band * 0.62;
    const barW = Math.max(2, groupW / seriesCount.value);
    const left = PAD.left + index * band + band / 2 - groupW / 2 + series * barW;
    const value = props.points[index]?.values[series] ?? 0;

    return { x: left, width: barW, y: y(value), height: Math.max(0, y(0) - y(value)) };
}

const TONES = ['var(--color-foreground)', 'var(--color-muted-foreground)'];
const OPACITY = [1, 0.45];

/** Thin the x labels when the axis is crowded. */
const labelEvery = computed(() => Math.ceil(props.points.length / 12) || 1);
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="overflow-x-auto">
            <svg
                :viewBox="`0 0 ${W} ${height}`"
                :style="{ height: `${height}px` }"
                class="w-full min-w-[36rem]"
                role="img"
                :aria-label="series?.join(' against ') ?? 'Values by period'"
            >
                <g>
                    <template v-for="tick in ticks" :key="tick">
                        <line
                            :x1="PAD.left"
                            :x2="W - PAD.right"
                            :y1="y(tick)"
                            :y2="y(tick)"
                            stroke="var(--color-border)"
                            stroke-width="1"
                        />
                        <text
                            :x="PAD.left - 8"
                            :y="y(tick) + 4"
                            text-anchor="end"
                            font-size="11"
                            fill="var(--color-muted-foreground)"
                        >
                            {{ tick }}
                        </text>
                    </template>
                </g>

                <g v-for="(point, i) in points" :key="point.label">
                    <rect
                        v-for="(_, s) in seriesCount"
                        :key="s"
                        v-bind="bar(i, s)"
                        :fill="TONES[s] ?? TONES[0]"
                        :opacity="OPACITY[s] ?? 1"
                        rx="1"
                    />
                    <text
                        v-if="i % labelEvery === 0"
                        :x="PAD.left + i * bandW + bandW / 2"
                        :y="height - 12"
                        text-anchor="middle"
                        font-size="11"
                        fill="var(--color-muted-foreground)"
                    >
                        {{ point.label }}
                    </text>
                </g>
            </svg>
        </div>

        <div v-if="series?.length" class="flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
            <span v-for="(name, i) in series" :key="name" class="inline-flex items-center gap-2">
                <span
                    class="size-2.5"
                    :class="cn(i === 0 ? 'bg-foreground' : 'bg-muted-foreground/45')"
                    aria-hidden="true"
                />
                {{ name }}
            </span>
        </div>
    </div>
</template>
