<script setup lang="ts">
import { computed } from 'vue';

import { formatMonth } from '@/lib/stock';

/**
 * "In and out · 12 months" (Figma `382:1592`) — monthly bottles received
 * against bottles shipped.
 *
 * Hand-drawn SVG rather than a charting library: it is two series over twelve
 * buckets with a linear scale, so a dependency would add more weight than it
 * removes. One scale places the bars, the gridlines and the labels, so a label
 * always names a value the chart actually reaches.
 */
const props = defineProps<{ points: { month: string; in: number; out: number }[]; locale: string }>();

const W = 720;
const H = 240;
const PAD = { top: 12, right: 8, bottom: 34, left: 46 };

const max = computed(() => Math.max(1, ...props.points.flatMap((p) => [p.in, p.out])));

/** Round the axis top to a friendly number so ticks are readable. */
const ceiling = computed(() => {
    const m = max.value;
    const magnitude = 10 ** Math.floor(Math.log10(m));
    const step = magnitude / 2 || 1;

    return Math.ceil(m / step) * step;
});

const ticks = computed(() => {
    const count = 4;

    return Array.from({ length: count + 1 }, (_, i) => Math.round((ceiling.value / count) * i));
});

const plotW = W - PAD.left - PAD.right;
const plotH = H - PAD.top - PAD.bottom;

const y = (value: number) => PAD.top + plotH - (value / ceiling.value) * plotH;
const bandW = computed(() => plotW / Math.max(1, props.points.length));

/** Two bars per month, centred in the band with a gap between pairs. */
function bar(index: number, series: 'in' | 'out') {
    const band = bandW.value;
    const barW = Math.max(2, (band * 0.62) / 2);
    const left = PAD.left + index * band + band / 2 - barW;
    const value = props.points[index]?.[series] ?? 0;

    return { x: series === 'in' ? left : left + barW, width: barW, y: y(value), height: Math.max(0, y(0) - y(value)) };
}

/** Show every other month label when the axis is crowded. */
const labelEvery = computed(() => (props.points.length > 8 ? 2 : 1));
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="overflow-x-auto">
            <svg
                :viewBox="`0 0 ${W} ${H}`"
                class="h-60 w-full min-w-[36rem]"
                role="img"
                aria-label="Units received against units shipped, by month"
            >
                <!-- Gridlines + y axis -->
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

                <!-- Bars -->
                <g v-for="(point, i) in points" :key="point.month">
                    <rect v-bind="bar(i, 'in')" fill="var(--color-foreground)" rx="1" />
                    <rect v-bind="bar(i, 'out')" fill="var(--color-muted-foreground)" opacity="0.45" rx="1" />
                    <text
                        v-if="i % labelEvery === 0"
                        :x="PAD.left + i * bandW + bandW / 2"
                        :y="H - 12"
                        text-anchor="middle"
                        font-size="11"
                        fill="var(--color-muted-foreground)"
                    >
                        {{ formatMonth(point.month, locale) }}
                    </text>
                </g>
            </svg>
        </div>

        <div class="flex flex-wrap items-center gap-4 text-13 text-muted-foreground">
            <span class="inline-flex items-center gap-2">
                <span class="size-2.5 rounded-[2px] bg-foreground" aria-hidden="true" />
                In · bottling and receipts
            </span>
            <span class="inline-flex items-center gap-2">
                <span class="size-2.5 rounded-[2px] bg-muted-foreground/45" aria-hidden="true" />
                Out · shipped
            </span>
        </div>
    </div>
</template>
