<script setup lang="ts">
import { computed } from 'vue';

import { useTranslations } from '@/composables/useTranslations';

/**
 * Tenant distribution across plans — port of Filament's TenantsByPlanChart
 * (Chart.js doughnut). Hand-rolled SVG doughnut via stacked `<circle>`
 * segments (stroke-dasharray/-dashoffset), same "no charting dependency"
 * approach as AreaChart/BarChart — there was nothing existing to reuse for a
 * radial chart, so this is new but follows the same philosophy.
 */
const props = defineProps<{ points: { label: string; value: number }[] }>();

const { t } = useTranslations();

/** Brand-toned slices (wine first, then supporting hues) — matches the Filament widget's palette. */
const COLORS = ['#7a1f2b', '#b4434f', '#d98a92', '#52525b', '#a1a1aa', '#d4d4d8'];

const SIZE = 160;
const RADIUS = 60;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const total = computed(() => props.points.reduce((sum, p) => sum + p.value, 0));

const segments = computed(() => {
    let offset = 0;

    return props.points.map((point, i) => {
        const fraction = total.value > 0 ? point.value / total.value : 0;
        const length = fraction * CIRCUMFERENCE;
        const segment = {
            ...point,
            color: COLORS[i % COLORS.length]!,
            dasharray: `${length} ${CIRCUMFERENCE - length}`,
            dashoffset: -offset,
        };
        offset += length;

        return segment;
    });
});
</script>

<template>
    <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
        <svg :viewBox="`0 0 ${SIZE} ${SIZE}`" :width="SIZE" :height="SIZE" role="img" aria-label="Tenants by plan">
            <circle :cx="SIZE / 2" :cy="SIZE / 2" :r="RADIUS" fill="none" stroke="var(--color-muted)" stroke-width="20" />
            <circle
                v-for="segment in segments"
                :key="segment.label"
                :cx="SIZE / 2"
                :cy="SIZE / 2"
                :r="RADIUS"
                fill="none"
                :stroke="segment.color"
                stroke-width="20"
                :stroke-dasharray="segment.dasharray"
                :stroke-dashoffset="segment.dashoffset"
                :transform="`rotate(-90 ${SIZE / 2} ${SIZE / 2})`"
            />
        </svg>

        <ul class="flex-1 space-y-1.5 text-xs">
            <li v-for="segment in segments" :key="segment.label" class="flex items-center gap-2">
                <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: segment.color }" />
                <span class="flex-1 text-foreground">{{ segment.label }}</span>
                <span class="tabular-nums text-muted-foreground">{{ segment.value }}</span>
            </li>
            <li v-if="points.length === 0" class="text-muted-foreground">{{ t('No tenants yet.') }}</li>
        </ul>
    </div>
</template>
