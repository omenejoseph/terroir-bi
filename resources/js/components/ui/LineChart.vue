<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

import { useElementWidth } from '@/composables/useElementWidth';
import type { SharedProps } from '@/types';

/**
 * A time-series line chart with one or more series — one line per barrel plus a heavier one for the
 * lot's average, say — a shaded target band, and hover titles on every point. Hand-authored SVG at
 * the width it is shown at (like AreaChart/BarChart), so text and strokes keep their real size.
 *
 * Points are `{ t: <ms since epoch>, value }`. The y range always includes the band, so a target
 * stays visible even when every reading sits outside it.
 */
export interface LineSeries {
    key: string;
    label: string;
    color: string;
    /** Drawn heavier — the lot's average over the individual barrels. */
    strong?: boolean;
    points: { t: number; value: number }[];
}

const props = withDefaults(
    defineProps<{
        series: LineSeries[];
        height?: number;
        /** A shaded target range on the y axis. */
        band?: { from: number; to: number; label?: string } | null;
        /** Decimal places for the axis and the tooltips. */
        decimals?: number;
        ariaLabel?: string;
    }>(),
    { height: 170, band: null, decimals: 2, ariaLabel: 'Trend over time' },
);

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);

const root = ref<HTMLElement | null>(null);
const W = useElementWidth(root, 320);
const PAD = { top: 10, right: 12, bottom: 22, left: 40 };

const all = computed(() => props.series.flatMap((s) => s.points));

const xDomain = computed<[number, number]>(() => {
    const ts = all.value.map((p) => p.t);
    const min = Math.min(...ts);
    const max = Math.max(...ts);

    // One day of readings still needs a width to sit in.
    return min === max ? [min - 43_200_000, max + 43_200_000] : [min, max];
});

const yDomain = computed<[number, number]>(() => {
    const values = [...all.value.map((p) => p.value), ...(props.band ? [props.band.from, props.band.to] : [])];
    const min = Math.min(...values);
    const max = Math.max(...values);
    const pad = (max - min || Math.abs(max) || 1) * 0.1;

    return [min - pad, max + pad];
});

const plotW = computed(() => Math.max(10, W.value - PAD.left - PAD.right));
const plotH = computed(() => props.height - PAD.top - PAD.bottom);
const x = (t: number) => PAD.left + ((t - xDomain.value[0]) / (xDomain.value[1] - xDomain.value[0])) * plotW.value;
const y = (v: number) => PAD.top + plotH.value - ((v - yDomain.value[0]) / (yDomain.value[1] - yDomain.value[0])) * plotH.value;

const ticks = computed(() => Array.from({ length: 4 }, (_, i) => yDomain.value[0] + ((yDomain.value[1] - yDomain.value[0]) / 3) * i));
const fmt = (v: number) => v.toLocaleString(locale.value, { maximumFractionDigits: props.decimals });
const day = (t: number) => new Date(t).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: '2-digit' });

const xLabels = computed(() => {
    const [a, b] = xDomain.value;

    return [a, (a + b) / 2, b].map((t, i) => ({ t, anchor: i === 0 ? 'start' : i === 2 ? 'end' : 'middle' }));
});

const path = (points: { t: number; value: number }[]) => points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${x(p.t).toFixed(1)} ${y(p.value).toFixed(1)}`).join(' ');
</script>

<template>
    <div ref="root" class="w-full min-w-0">
        <svg :viewBox="`0 0 ${W} ${height}`" :width="W" :height="height" class="block max-w-full" role="img" :aria-label="ariaLabel">
            <rect
                v-if="band"
                :x="PAD.left"
                :y="y(band.to)"
                :width="plotW"
                :height="Math.max(1, y(band.from) - y(band.to))"
                fill="var(--color-success, #16a34a)"
                opacity="0.1"
            >
                <title>{{ band.label }}</title>
            </rect>

            <g>
                <template v-for="tick in ticks" :key="tick">
                    <line :x1="PAD.left" :x2="W - PAD.right" :y1="y(tick)" :y2="y(tick)" stroke="var(--color-border)" stroke-width="1" />
                    <text :x="PAD.left - 6" :y="y(tick) + 3.5" text-anchor="end" font-size="10" fill="var(--color-muted-foreground)">{{ fmt(tick) }}</text>
                </template>
            </g>

            <text v-for="l in xLabels" :key="l.t" :x="Math.min(Math.max(x(l.t), PAD.left), W - PAD.right)" :y="height - 6" :text-anchor="l.anchor" font-size="10" fill="var(--color-muted-foreground)">
                {{ day(l.t) }}
            </text>

            <g v-for="s in series" :key="s.key">
                <path v-if="s.points.length > 1" :d="path(s.points)" fill="none" :stroke="s.color" :stroke-width="s.strong ? 2.5 : 1.5" stroke-linejoin="round" :opacity="s.strong ? 1 : 0.9" />
                <circle v-for="(p, i) in s.points" :key="i" :cx="x(p.t)" :cy="y(p.value)" :r="s.strong ? 3 : 2.5" :fill="s.color">
                    <title>{{ s.label }} · {{ day(p.t) }}: {{ fmt(p.value) }}</title>
                </circle>
            </g>
        </svg>
    </div>
</template>
