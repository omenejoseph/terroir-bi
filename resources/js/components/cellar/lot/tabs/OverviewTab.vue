<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    CalendarCheck,
    CalendarX,
    Cylinder,
    Droplets,
    FlaskConical,
    GlassWater,
    Grape,
    Pencil,
    RotateCcw,
    Trash2,
    Wine,
    Wrench,
} from 'lucide-vue-next';

import BarrelsTable from '@/components/cellar/lot/tabs/BarrelsTable.vue';
import TablePager from '@/components/cellar/lot/tabs/TablePager.vue';
import ProtocolCard from '@/components/cellar/lot/ProtocolCard.vue';
import Card from '@/components/ui/Card.vue';
import Disclosure from '@/components/ui/Disclosure.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import Button from '@/components/ui/Button.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ANALYSIS_FIELDS, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';
import type { AnalysisRow, BarrelRow, BarrelsCombined, FermentationTemplate, TimelineEvent, WineLotDetail } from '@/types/cellar';
import type { Paginated, SharedProps } from '@/types';
import type { MenuItem } from '@/types/ui';

/**
 * The lot at a glance: the latest reading of every parameter (in the barrel in view, or across the
 * lot), the barrels it sits in with their combined figures, and the lot's journey — paged on the
 * server and filterable by kind. The grapes, cost breakdown and fermentation protocol sit beside it.
 */
const props = defineProps<{
    lot: WineLotDetail;
    journey: Paginated<TimelineEvent> | null;
    kind: string | null;
    barrels: { rows: BarrelRow[]; combined: BarrelsCombined };
    trend: AnalysisRow[];
    focusId: string | null;
    templates: FermentationTemplate[];
    canManage: boolean;
    canEdit: boolean;
    finance: boolean;
}>();

const emit = defineEmits<{
    kind: [kind: string | null];
    page: [page: number];
    perPage: [perPage: number];
    row: [event: TimelineEvent, action: string];
    focus: [vesselId: string];
    reading: [vesselId: string];
    tab: [tab: 'barrels'];
    open: [panel: 'adjust' | 'assign' | 'blend-in'];
}>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const locale = computed(() => page.props.locale);
const currency = computed(() => page.props.org?.default_currency ?? 'EUR');
const money = (minor: number | null | undefined) => (minor === null || minor === undefined ? '—' : formatMoney(minor, currency.value));
const shortDate = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short' }) : '—');
const fullDate = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

/* ---- latest reading of every parameter, in scope ----------------------------------------------- */
const scoped = computed(() => (props.focusId ? props.trend.filter((r) => r.vessel_id === props.focusId || r.vessel_id === null) : props.trend));
const latest = computed(() =>
    ANALYSIS_FIELDS.map((f) => {
        // The trend is oldest first, so the newest reading of a parameter is the last that has it.
        const row = [...scoped.value].reverse().find((r) => r[f.key] !== null && r[f.key] !== undefined);

        return row ? { key: f.key, label: f.label, value: Number(row[f.key]), date: row.date, barrel: row.vessel_name ?? null } : null;
    }).filter((x): x is NonNullable<typeof x> => x !== null),
);

/* ---- journey ----------------------------------------------------------------------------------- */
const KINDS: { key: string; label: string }[] = [
    { key: 'analysis', label: 'Readings' },
    { key: 'addition', label: 'Additions' },
    { key: 'move', label: 'Moves' },
    { key: 'process', label: 'Processes' },
    { key: 'tasting', label: 'Tastings' },
    { key: 'bottling', label: 'Bottling' },
    { key: 'planned', label: 'Planned work' },
];

const ICONS: Record<string, unknown> = {
    vessel: Cylinder, addition: Droplets, analysis: FlaskConical, tasting: Wine, transfer: ArrowRightLeft, transfer_in: ArrowRightLeft,
    process: Wrench, bottling: GlassWater, created: Grape, planned_done: CalendarCheck, planned_skipped: CalendarX,
};
const TONES: Record<string, string> = {
    addition: 'text-tag-violet-foreground', analysis: 'text-tag-blue-foreground', tasting: 'text-tag-green-foreground',
    process: 'text-tag-orange-foreground', bottling: 'text-tag-slate-foreground',
};

const EDIT: MenuItem = { key: 'edit', label: 'Edit', icon: Pencil };
const DELETE: MenuItem = { key: 'delete', label: 'Delete', icon: Trash2, destructive: true };
const REVERSE: MenuItem = { key: 'reverse', label: 'Reverse', icon: RotateCcw, destructive: true };

function rowMenu(e: TimelineEvent): MenuItem[] {
    if (!e.ref_id) return [];
    const items = (() => {
        switch (e.type) {
            case 'addition':
            case 'analysis':
            case 'tasting':
                return props.canEdit ? [EDIT, DELETE] : [];
            case 'process':
                return props.canEdit ? [DELETE] : [];
            case 'transfer':
            case 'transfer_in':
                return props.canEdit ? [REVERSE] : [];
            case 'bottling':
                return props.canManage ? [REVERSE] : [];
            default:
                return [];
        }
    })();

    return items.map((m) => ({ ...m, label: t(m.label) }));
}

const grapeTotals = computed(() => ({
    kg: props.lot.grapes.reduce((n, g) => n + Number(g.weight_kg ?? 0), 0),
    cost: props.lot.grapes.reduce((n, g) => n + (g.price_per_kg && g.weight_kg ? Math.round(g.price_per_kg.minor * Number(g.weight_kg)) : 0), 0),
}));
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Latest reading of every parameter -->
        <Card v-if="latest.length" class="p-3">
            <SectionHeader :title="t('Latest readings')" :description="focusId ? t('In the barrel in view (and readings of the whole lot)') : t('The newest reading of each, across the lot')" />
            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-xs sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                <div v-for="l in latest" :key="l.key">
                    <dt class="text-muted-foreground">{{ t(l.label) }}</dt>
                    <dd class="mt-0.5 text-base font-semibold tabular-nums">{{ qty(l.value) }}</dd>
                    <dd class="text-3xs text-muted-foreground">{{ shortDate(l.date) }}<template v-if="l.barrel && !focusId"> · {{ l.barrel }}</template></dd>
                </div>
            </dl>
        </Card>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
            <div class="flex min-w-0 flex-col gap-4">
                <!-- Barrels at a glance -->
                <Card class="min-w-0 overflow-hidden">
                    <div class="flex items-center justify-between gap-2 border-b border-border px-3 py-2.5">
                        <span class="text-sm font-semibold">{{ t('Barrels') }} <span class="font-normal text-muted-foreground">· {{ barrels.combined.barrels }}</span></span>
                        <button v-if="barrels.combined.barrels > barrels.rows.length" type="button" class="text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground" @click="emit('tab', 'barrels')">{{ t('All :n barrels →', { n: barrels.combined.barrels }) }}</button>
                        <button v-else type="button" class="text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground" @click="emit('tab', 'barrels')">{{ t('Barrels tab →') }}</button>
                    </div>
                    <BarrelsTable :rows="barrels.rows" :combined="barrels.combined" :lot-id="lot.id" :focus-id="focusId" :can-edit="canEdit" compact @focus="emit('focus', $event)" @reading="emit('reading', $event)" />
                    <div v-if="canEdit" class="flex flex-wrap gap-1.5 border-t border-border px-3 py-2.5">
                        <Button size="sm" variant="outline" @click="emit('open', 'adjust')">{{ t('Adjust volume') }}</Button>
                        <Button size="sm" variant="outline" @click="emit('open', 'assign')">{{ t('Assign vessel') }}</Button>
                        <Button size="sm" variant="outline" @click="emit('open', 'blend-in')">{{ t('Blend in') }}</Button>
                    </div>
                </Card>

                <!-- Journey -->
                <Card class="min-w-0">
                    <div class="flex flex-wrap items-center gap-1.5 border-b border-border px-3 py-2.5">
                        <span class="mr-2 text-sm font-semibold">{{ t('Journey') }}</span>
                        <button type="button" :aria-pressed="kind === null" :class="cn('px-2 py-1 text-[11px]', kind === null ? 'bg-primary text-primary-foreground' : 'border border-border hover:bg-accent')" @click="emit('kind', null)">{{ t('All') }}</button>
                        <button
                            v-for="k in KINDS"
                            :key="k.key"
                            type="button"
                            :aria-pressed="kind === k.key"
                            :class="cn('px-2 py-1 text-[11px]', kind === k.key ? 'bg-primary text-primary-foreground' : 'border border-border hover:bg-accent')"
                            @click="emit('kind', k.key)"
                        >
                            {{ t(k.label) }}
                        </button>
                    </div>
                    <ol>
                        <li v-for="(e, i) in journey?.data ?? []" :key="`${e.type}-${e.ref_id ?? i}`" class="grid grid-cols-[64px_16px_minmax(0,1fr)_auto] items-start gap-2.5 border-b border-border px-3 py-2.5 text-xs last:border-0">
                            <span class="pt-0.5 text-[11px] text-muted-foreground">{{ shortDate(e.date) }}</span>
                            <component :is="ICONS[e.type] ?? Wrench" :class="cn('mt-0.5 size-3.5', TONES[e.type] ?? 'text-muted-foreground')" />
                            <div class="min-w-0">
                                <p class="font-medium">{{ e.title }}</p>
                                <p v-if="e.detail" class="text-muted-foreground">{{ e.detail }}</p>
                            </div>
                            <DropdownMenu v-if="rowMenu(e).length" :items="rowMenu(e)" align="right" @select="(k) => emit('row', e, k)" />
                        </li>
                        <li v-if="!journey || journey.data.length === 0" class="px-3 py-10 text-center text-xs text-muted-foreground">{{ t('Nothing recorded yet.') }}</li>
                    </ol>
                    <TablePager v-if="journey" :meta="journey.meta" @page="emit('page', $event)" @per-page="emit('perPage', $event)" />
                </Card>
            </div>

            <aside class="flex min-w-0 flex-col gap-3">
                <div v-if="lot.planned_additions.length" class="border border-dashed border-border p-3 text-xs">
                    <p class="mb-1 font-medium">{{ t('Planned — waiting for the cellar team · :n', { n: lot.planned_additions.length }) }}</p>
                    <p v-for="p in lot.planned_additions" :key="p.id" class="text-muted-foreground">
                        {{ p.planned_for }} · {{ p.product_name }} {{ qty(p.quantity) }} {{ p.unit }}<template v-if="p.vessel"> → {{ p.vessel.name }}</template>
                    </p>
                </div>

                <ProtocolCard v-if="lot.status === 'FERMENTING' || lot.fermentation_template_id" :lot="lot" :templates="templates" :can-manage="canManage" />

                <Card v-if="lot.grapes.length || finance" class="p-3 text-xs">
                    <Disclosure :title="finance ? t('Grapes & cost') : t('Grapes')" :summary="finance && lot.cost_breakdown ? money(lot.cost_breakdown.total) : undefined">
                        <table class="mt-1 w-full">
                            <thead class="text-[10px] uppercase text-muted-foreground">
                                <tr>
                                    <th class="py-1 text-left font-medium">{{ t('Variety') }}</th>
                                    <th class="py-1 text-right font-medium">%</th>
                                    <th class="py-1 text-right font-medium">kg</th>
                                    <th v-if="finance" class="py-1 text-right font-medium">{{ t('Cost') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="g in lot.grapes" :key="g.id" class="border-t border-border">
                                    <td class="py-1">{{ g.grape_variety }}<span v-if="finance && g.price_per_kg" class="text-muted-foreground"> · {{ money(g.price_per_kg.minor) }}/kg</span></td>
                                    <td class="py-1 text-right tabular-nums">{{ qty(g.percentage) }}</td>
                                    <td class="py-1 text-right tabular-nums">{{ qty(g.weight_kg) }}</td>
                                    <td v-if="finance" class="py-1 text-right tabular-nums">{{ g.price_per_kg && g.weight_kg ? money(Math.round(g.price_per_kg.minor * Number(g.weight_kg))) : '—' }}</td>
                                </tr>
                                <tr v-if="lot.grapes.length > 1" class="border-t border-border font-medium">
                                    <td class="py-1">{{ t('Total') }}</td>
                                    <td />
                                    <td class="py-1 text-right tabular-nums">{{ qty(grapeTotals.kg) }}</td>
                                    <td v-if="finance" class="py-1 text-right tabular-nums">{{ money(grapeTotals.cost) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-if="finance && lot.cost_breakdown && lot.cost_breakdown.total > 0" class="mt-3 flex flex-col gap-1 border-t border-border pt-2">
                            <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Grape cost') }}</span><span>{{ money(lot.cost_breakdown.grape) }}</span></div>
                            <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Additions') }}</span><span>{{ money(lot.cost_breakdown.additions) }}</span></div>
                            <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Linked costs') }}</span><span>{{ money(lot.cost_breakdown.linked) }}</span></div>
                            <div v-if="lot.cost_breakdown.carried" class="flex justify-between"><span class="text-muted-foreground">{{ t('Carried in by blends') }}</span><span>{{ money(lot.cost_breakdown.carried) }}</span></div>
                            <div class="flex justify-between font-semibold"><span>{{ t('Total') }}</span><span>{{ money(lot.cost_breakdown.total) }}</span></div>
                            <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Per 750 ml bottle') }}</span><span>{{ money(lot.cost_breakdown.per_bottle_750) }}</span></div>
                        </div>
                    </Disclosure>
                </Card>
            </aside>
        </div>
    </div>
</template>
