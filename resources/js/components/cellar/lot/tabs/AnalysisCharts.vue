<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

import LineChart, { type LineSeries } from '@/components/ui/LineChart.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ANALYSIS_FIELDS } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import type { AnalysisParam, AnalysisRow } from '@/types/cellar';
import type { SharedProps } from '@/types';

/**
 * Trends of a lot's readings, one small chart per parameter (the prototype's small multiples).
 *
 * Whole lot: one line per barrel so barrels can be compared, plus a heavier line for the lot's
 * average (the mean of every reading that day). One barrel: just that barrel's line. Only
 * parameters that have readings are offered; free SO₂ also shades its healthy 25–45 range.
 * Readings are the lot's full history (oldest first), bounded by how many were taken — it is the
 * data of a chart, not a table, so it is not paged.
 */
const props = defineProps<{ trend: AnalysisRow[]; focusId: string | null }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const locale = computed(() => page.props.locale);

/** A categorical palette: distinct in light and dark, and apart from the foreground used for the average. */
const PALETTE = ['#2563eb', '#d97706', '#059669', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#dc2626', '#9333ea', '#ca8a04'];

const num = (row: AnalysisRow, key: AnalysisParam): number | null => {
    const v = row[key];

    return v === null || v === undefined || v === '' ? null : Number(v);
};

const rows = computed(() => (props.focusId ? props.trend.filter((r) => r.vessel_id === props.focusId) : props.trend));
const available = computed(() => ANALYSIS_FIELDS.filter((f) => rows.value.some((r) => num(r, f.key) !== null)));

const selected = ref<AnalysisParam[] | null>(null);
const chosen = computed<AnalysisParam[]>(() => {
    const keys = available.value.map((f) => f.key);
    const picked = (selected.value ?? keys.slice(0, 3)).filter((k) => keys.includes(k));

    return picked.length ? picked : keys.slice(0, 3);
});
function toggle(key: AnalysisParam): void {
    const now = chosen.value;
    selected.value = now.includes(key) ? now.filter((k) => k !== key) : [...now, key];
}

const barrels = computed(() => {
    const seen = new Map<string, string>();
    for (const r of rows.value) if (r.vessel_id) seen.set(r.vessel_id, r.vessel_name ?? '—');

    return [...seen.entries()].map(([id, name], i) => ({ id, name, color: PALETTE[i % PALETTE.length]! }));
});

/** Many barrels make a spaghetti chart, so past eight only the average shows until asked for. */
const showBarrels = ref<boolean | null>(null);
const barrelsOn = computed(() => showBarrels.value ?? barrels.value.length <= 8);

const day = (iso: string): number => new Date(iso.slice(0, 10)).getTime();

function seriesFor(key: AnalysisParam): LineSeries[] {
    const withValue = rows.value.filter((r) => num(r, key) !== null);
    const out: LineSeries[] = [];

    if (props.focusId) {
        const b = barrels.value.find((x) => x.id === props.focusId);
        out.push({ key: props.focusId, label: b?.name ?? t('This barrel'), color: PALETTE[0]!, strong: true, points: withValue.map((r) => ({ t: day(r.date), value: num(r, key)! })) });

        return out;
    }

    // The lot's average: every reading that day, whichever barrel it came from.
    const byDay = new Map<number, number[]>();
    for (const r of withValue) byDay.set(day(r.date), [...(byDay.get(day(r.date)) ?? []), num(r, key)!]);
    out.push({
        key: 'avg',
        label: t('Lot average'),
        color: 'var(--color-foreground)',
        strong: true,
        points: [...byDay.entries()].sort((a, b) => a[0] - b[0]).map(([tt, vals]) => ({ t: tt, value: vals.reduce((s, v) => s + v, 0) / vals.length })),
    });

    if (barrelsOn.value) {
        for (const b of barrels.value) {
            const pts = withValue.filter((r) => r.vessel_id === b.id).map((r) => ({ t: day(r.date), value: num(r, key)! }));
            if (pts.length) out.push({ key: b.id, label: b.name, color: b.color, points: pts });
        }
        const lotLevel = withValue.filter((r) => !r.vessel_id).map((r) => ({ t: day(r.date), value: num(r, key)! }));
        if (lotLevel.length) out.push({ key: 'lot', label: t('Whole-lot readings'), color: '#94a3b8', points: lotLevel });
    }

    return out;
}

const cards = computed(() =>
    chosen.value.map((key) => {
        const field = ANALYSIS_FIELDS.find((f) => f.key === key)!;
        const series = seriesFor(key);
        const primary = series[0]!.points;
        const values = rows.value.map((r) => num(r, key)).filter((v): v is number => v !== null);

        return {
            key,
            label: t(field.label),
            series,
            latest: primary.length ? primary[primary.length - 1]!.value : null,
            min: Math.min(...values),
            max: Math.max(...values),
            count: values.length,
            decimals: ['ph', 'density', 'total_acidity', 'volatile_acidity', 'malic', 'lactic'].includes(key) ? 2 : 1,
        };
    }),
);

const f = (v: number | null, d: number) => (v === null ? '—' : v.toLocaleString(locale.value, { maximumFractionDigits: d }));
</script>

<template>
    <div v-if="available.length" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-1.5">
            <button
                v-for="p in available"
                :key="p.key"
                type="button"
                :aria-pressed="chosen.includes(p.key)"
                :class="cn('px-2 py-1 text-[11px]', chosen.includes(p.key) ? 'bg-primary text-primary-foreground' : 'border border-border hover:bg-accent')"
                @click="toggle(p.key)"
            >
                {{ t(p.label) }}
            </button>
            <label v-if="!focusId && barrels.length > 1" class="ml-auto inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                <input type="checkbox" class="size-3.5 accent-primary" :checked="barrelsOn" @change="showBarrels = ($event.target as HTMLInputElement).checked" />
                {{ t('Show each barrel') }}
            </label>
        </div>

        <div class="grid gap-3 lg:grid-cols-2 2xl:grid-cols-3">
            <div v-for="c in cards" :key="c.key" class="flex flex-col gap-2 border border-border bg-card p-3">
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-xs font-semibold">{{ c.label }}</span>
                    <span class="text-xl font-semibold tabular-nums">{{ f(c.latest, c.decimals) }}</span>
                </div>
                <LineChart
                    v-if="c.series.some((s) => s.points.length > 1)"
                    :series="c.series"
                    :height="150"
                    :decimals="c.decimals"
                    :band="c.key === 'free_so2' ? { from: 25, to: 45, label: t('Healthy free SO₂: 25–45 mg/L') } : null"
                    :aria-label="c.label"
                />
                <p v-else class="py-8 text-center text-xs text-muted-foreground">{{ t('Two or more readings are needed for a trend.') }}</p>
                <p class="text-[11px] text-muted-foreground">{{ t('min :a · max :b · :n readings', { a: f(c.min, c.decimals), b: f(c.max, c.decimals), n: c.count }) }}</p>
            </div>
        </div>

        <p v-if="!focusId && barrelsOn && barrels.length > 1" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-muted-foreground">
            <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 bg-foreground" aria-hidden="true" />{{ t('Lot average') }}</span>
            <span v-for="b in barrels" :key="b.id" class="inline-flex items-center gap-1.5"><span class="size-2" :style="{ background: b.color }" aria-hidden="true" />{{ b.name }}</span>
        </p>
    </div>
    <p v-else class="border border-border bg-card px-4 py-10 text-center text-xs text-muted-foreground">{{ t('No analyses yet.') }}</p>
</template>
