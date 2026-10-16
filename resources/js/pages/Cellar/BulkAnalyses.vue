<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { ClipboardPaste, Search } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { ANALYSIS_FIELDS, METRIC_LABELS, parseDecimal, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import type { AnalysisParam, LotOption } from '@/types/cellar';

/**
 * Cellar › Bulk analyses — the lab sheet for the whole cellar: one row per
 * lot (or per vessel when "per vessel" is on), one column per parameter.
 * Type into the grid, or paste a CSV/TSV export from the lab (first column
 * the lot number, name or vessel; a header row names the parameters).
 * Replaces the prototype's photo scan, which stays deferred.
 */
const props = defineProps<{ lots: LotOption[] }>();

const { t } = useTranslations();
const { can } = useAuth();

const columns = ref<AnalysisParam[]>(['free_so2', 'total_so2', 'ph', 'alcohol', 'total_acidity', 'volatile_acidity']);
const perVessel = ref(false);
const date = ref(new Date().toISOString().slice(0, 10));
const search = ref('');

interface Row {
    key: string;
    lotId: string;
    vesselId: string | null;
    label: string;
    sub: string;
    match: string[];
}

const rows = computed<Row[]>(() =>
    props.lots.flatMap((l): Row[] =>
        perVessel.value && l.vessels.length
            ? l.vessels.map((v) => ({ key: `${l.id}:${v.id}`, lotId: l.id, vesselId: v.id, label: v.name, sub: `${l.name} · ${qty(v.volume)} L`, match: [v.name, l.name, l.lot_number] }))
            : [{ key: l.id, lotId: l.id, vesselId: null, label: l.name, sub: `${l.lot_number} · ${qty(l.current_volume)} L`, match: [l.name, l.lot_number, ...l.vessels.map((v) => v.name)] }],
    ),
);
const shown = computed(() => {
    const q = search.value.trim().toLowerCase();

    return q === '' ? rows.value : rows.value.filter((r) => r.match.some((m) => m.toLowerCase().includes(q)));
});

const cells = reactive<Record<string, Partial<Record<AnalysisParam, string>>>>({});
watch(rows, (list) => {
    for (const r of list) cells[r.key] ??= {};
}, { immediate: true });

const filled = computed(() =>
    rows.value
        .map((r) => {
            const values: Partial<Record<AnalysisParam, number>> = {};
            for (const c of columns.value) {
                const v = parseDecimal(cells[r.key]?.[c]);
                if (v !== null) values[c] = v;
            }

            return { r, values };
        })
        .filter((x) => Object.keys(x.values).length > 0),
);

function toggleColumn(c: AnalysisParam): void {
    columns.value = columns.value.includes(c) ? columns.value.filter((x) => x !== c) : [...columns.value, c];
}

// ── Paste ───────────────────────────────────────────────────────────────────
const pasteOpen = ref(false);
const pasteText = ref('');
const pasteReport = ref<string | null>(null);
const PASTE_EXAMPLE = 'Lot\tFree SO2\tTotal SO2\tpH\nLOT-2026-001\t24\t80\t3,45';

/** Header words the lab sheets use, mapped onto parameters. */
const HEADER_ALIASES: Record<string, AnalysisParam> = {
    free: 'free_so2', 'free so2': 'free_so2', 'slobodni': 'free_so2', fso2: 'free_so2',
    total: 'total_so2', 'total so2': 'total_so2', 'ukupni': 'total_so2', tso2: 'total_so2',
    ph: 'ph', alc: 'alcohol', alcohol: 'alcohol', alkohol: 'alcohol',
    ta: 'total_acidity', 'uk': 'total_acidity', acidity: 'total_acidity',
    va: 'volatile_acidity', hk: 'volatile_acidity',
    sugar: 'residual_sugar', rs: 'residual_sugar', 'šećer': 'residual_sugar',
    malic: 'malic', lactic: 'lactic', brix: 'brix', temp: 'temperature', density: 'density', tpi: 'tpi', gf: 'glucose_fructose',
};

function applyPaste(): void {
    const lines = pasteText.value.split(/\r?\n/).filter((l) => l.trim() !== '');
    if (lines.length < 2) {
        pasteReport.value = t('Paste a header row and at least one data row.');

        return;
    }
    const split = (l: string) => (l.includes('\t') ? l.split('\t') : l.split(/;|,(?=\s*[^\d])/)).map((c) => c.trim());
    const header = split(lines[0]!).map((h) => h.toLowerCase().replace(/[₂]/g, '2').replace(/\s*\(.*\)$/, ''));
    const map = header.map((h) => HEADER_ALIASES[h] ?? (ANALYSIS_FIELDS.some((f) => f.key === h) ? (h as AnalysisParam) : null));
    let matched = 0;
    const unmatched: string[] = [];
    for (const line of lines.slice(1)) {
        const cols = split(line);
        const id = (cols[0] ?? '').toLowerCase();
        const row = rows.value.find((r) => r.match.some((m) => m.toLowerCase() === id)) ?? rows.value.find((r) => r.match.some((m) => m.toLowerCase().includes(id)));
        if (!row || id === '') {
            unmatched.push(cols[0] ?? '?');
            continue;
        }
        matched++;
        map.forEach((param, i) => {
            if (param && i > 0 && cols[i] !== undefined && cols[i] !== '') {
                cells[row.key] = { ...cells[row.key], [param]: cols[i] };
                if (!columns.value.includes(param)) columns.value = [...columns.value, param];
            }
        });
    }
    pasteReport.value = t(':n rows filled.', { n: matched }) + (unmatched.length ? ' ' + t('Not matched: :list', { list: unmatched.join(', ') }) : '');
    if (unmatched.length === 0) pasteOpen.value = false;
}

const busy = ref(false);
function save(): void {
    busy.value = true;
    router.post(
        '/cellar/bulk-analyses',
        { analyses: filled.value.map(({ r, values }) => ({ wine_lot_id: r.lotId, vessel_id: r.vesselId, date: date.value, ...values })) } as never,
        {
            preserveScroll: true,
            onSuccess: () => {
                for (const key of Object.keys(cells)) cells[key] = {};
                pasteReport.value = null;
            },
            onFinish: () => (busy.value = false),
        },
    );
}
</script>

<template>
    <AppLayout :title="t('Bulk analyses')">
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Bulk analyses')" :description="t('Enter the lab’s results for many lots at once — type them in or paste the lab’s sheet.')">
                <template #actions><Button size="sm" variant="outline" href="/cellar/bulk-additions">{{ t('Bulk additions') }}</Button></template>
            </PageHeader>
            <CellarTabs current="lots" />

            <Card class="flex flex-col gap-3 p-4">
                <div class="flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-sm">{{ t('Date') }} <Input v-model="date" type="date" class="w-40" /></label>
                    <Checkbox v-model="perVessel" :label="t('Per vessel')" />
                    <div class="relative min-w-40 flex-1">
                        <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" class="pl-8" :placeholder="t('Search lots...')" />
                    </div>
                    <Button size="sm" variant="outline" @click="pasteOpen = !pasteOpen"><ClipboardPaste class="size-3.5" /> {{ t('Paste from lab sheet') }}</Button>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="f in ANALYSIS_FIELDS"
                        :key="f.key"
                        type="button"
                        :class="cn('h-7 border px-2 text-xs', columns.includes(f.key) ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card')"
                        @click="toggleColumn(f.key)"
                    >
                        {{ t(METRIC_LABELS[f.key]) }}
                    </button>
                </div>
                <div v-if="pasteOpen" class="flex flex-col gap-2">
                    <Textarea v-model="pasteText" :rows="6" :placeholder="PASTE_EXAMPLE" class="font-mono text-xs" />
                    <div class="flex items-center gap-2">
                        <Button size="sm" @click="applyPaste">{{ t('Fill the grid') }}</Button>
                        <span v-if="pasteReport" class="text-xs text-muted-foreground">{{ pasteReport }}</span>
                    </div>
                </div>
            </Card>

            <Card class="overflow-auto">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">{{ perVessel ? t('Vessel') : t('Lot') }}</th>
                            <th v-for="c in columns" :key="c" class="px-2 py-2 text-right font-medium">{{ t(METRIC_LABELS[c]) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in shown" :key="r.key" class="border-t border-border">
                            <td class="px-3 py-1.5"><p class="font-medium">{{ r.label }}</p><p class="text-[11px] text-muted-foreground">{{ r.sub }}</p></td>
                            <td v-for="c in columns" :key="c" class="px-1 py-1">
                                <Input
                                    :model-value="cells[r.key]?.[c] ?? ''"
                                    inputmode="decimal"
                                    :invalid="(cells[r.key]?.[c] ?? '') !== '' && parseDecimal(cells[r.key]?.[c]) === null"
                                    class="ml-auto h-7 w-20 text-right"
                                    :aria-label="`${r.label} ${t(METRIC_LABELS[c])}`"
                                    @update:model-value="cells[r.key] = { ...cells[r.key], [c]: $event }"
                                />
                            </td>
                        </tr>
                        <tr v-if="shown.length === 0"><td :colspan="columns.length + 1" class="px-3 py-8 text-center text-muted-foreground">{{ t('No lots match the filters') }}</td></tr>
                    </tbody>
                </table>
            </Card>

            <div v-if="can('cellar.manage')" class="flex items-center justify-end gap-3">
                <span class="text-xs text-muted-foreground">{{ t(':n entered', { n: filled.length }) }}</span>
                <Button :disabled="busy || filled.length === 0" @click="save">{{ t('Save (:n)', { n: filled.length }) }}</Button>
            </div>
        </div>
    </AppLayout>
</template>
