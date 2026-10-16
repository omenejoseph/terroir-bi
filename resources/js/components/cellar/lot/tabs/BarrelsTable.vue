<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ExternalLink, FlaskConical, Focus } from 'lucide-vue-next';

import ProgressBar from '@/components/ui/ProgressBar.vue';
import { useTranslations } from '@/composables/useTranslations';
import { SO2_STATUS_TONES, VESSEL_TYPE_LABELS, qty, so2Status } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import type { BarrelRow, BarrelsCombined } from '@/types/cellar';
import type { SharedProps } from '@/types';

/**
 * Every barrel (or tank) a lot sits in: where it is, what it is, how much of this lot is in it and
 * how full it is, what else it holds, and the newest reading of each parameter taken in it — with
 * the COMBINED row under it: total litres, and each parameter averaged across the barrels weighted by
 * the litres in each. Barrels without a reading are left out of an average and counted, never
 * treated as zero. `compact` drops the less used columns for the overview.
 */
const props = withDefaults(
    defineProps<{
        rows: BarrelRow[];
        combined: BarrelsCombined;
        lotId: string;
        focusId: string | null;
        canEdit?: boolean;
        compact?: boolean;
    }>(),
    { canEdit: false, compact: false },
);

const emit = defineEmits<{ focus: [vesselId: string]; reading: [vesselId: string] }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const locale = computed(() => page.props.locale);

const shortDate = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short' }) : '');
const n = (v: number | null | undefined, digits = 1) => (v === null || v === undefined ? '—' : v.toLocaleString(locale.value, { maximumFractionDigits: digits }));

const COLUMNS = computed(() => [
    { key: 'free_so2' as const, label: t('Free SO₂'), digits: 0 },
    { key: 'total_so2' as const, label: t('Total SO₂'), digits: 0 },
    { key: 'ph' as const, label: 'pH', digits: 2 },
    ...(props.compact
        ? []
        : [
              { key: 'alcohol' as const, label: t('Alcohol'), digits: 1 },
              { key: 'volatile_acidity' as const, label: t('VA'), digits: 2 },
          ]),
]);

const location = (b: BarrelRow) => [b.room, b.location].filter(Boolean).join(' · ') || '—';
const counted = (key: string) => props.combined.parameters[key]?.barrels_counted ?? 0;
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[40rem] text-xs">
            <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                <tr>
                    <th scope="col" class="px-3 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Barrel') }}</th>
                    <th scope="col" class="px-3 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Location') }}</th>
                    <th scope="col" class="px-3 py-2.5 text-right font-medium tracking-[0.08em] uppercase">{{ t('This lot') }}</th>
                    <th v-if="!compact" scope="col" class="px-3 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Fill') }}</th>
                    <th v-for="c in COLUMNS" :key="c.key" scope="col" class="px-3 py-2.5 text-right font-medium tracking-[0.08em] uppercase">{{ c.label }}</th>
                    <th v-if="!compact" scope="col" class="px-3 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Also holds') }}</th>
                    <th scope="col" class="w-24 px-3 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="b in rows"
                    :key="b.vessel_id"
                    :class="cn('border-b border-border align-top last:border-b-0 hover:bg-muted/40', b.vessel_id === focusId && 'bg-primary/5')"
                >
                    <td class="px-3 py-2.5">
                        <button type="button" class="flex items-center gap-1.5 text-left font-semibold hover:underline" @click="emit('focus', b.vessel_id)">
                            <AlertTriangle v-if="b.is_faulty" class="size-3.5 text-destructive" :aria-label="t('Faulty')" />
                            {{ b.name }}
                        </button>
                        <span class="block text-muted-foreground">{{ t(VESSEL_TYPE_LABELS[b.type] ?? b.type) }}<template v-if="b.material"> · {{ b.material.replace(/_/g, ' ') }}</template></span>
                    </td>
                    <td class="px-3 py-2.5">{{ location(b) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ qty(b.volume) }} L</td>
                    <td v-if="!compact" class="w-32 px-3 py-2.5">
                        <ProgressBar :value="b.fill_percent" :label="b.name" />
                        <span class="mt-1 block text-muted-foreground tabular-nums">{{ n(b.fill_percent, 0) }}% · {{ qty(b.capacity_liters) }} L</span>
                    </td>
                    <td v-for="c in COLUMNS" :key="c.key" class="px-3 py-2.5 text-right tabular-nums">
                        <template v-if="b.readings[c.key]">
                            <span :class="c.key === 'free_so2' && SO2_STATUS_TONES[so2Status(b.readings[c.key]!.value)]" class="font-medium">{{ n(b.readings[c.key]!.value, c.digits) }}</span>
                            <span class="block text-3xs text-muted-foreground" :title="b.readings[c.key]!.in_vessel ? undefined : t('A reading of the whole lot — none was taken in this barrel')">
                                {{ shortDate(b.readings[c.key]!.date) }}<template v-if="!b.readings[c.key]!.in_vessel"> · {{ t('lot') }}</template>
                            </span>
                        </template>
                        <span v-else class="text-muted-foreground">—</span>
                    </td>
                    <td v-if="!compact" class="px-3 py-2.5 text-muted-foreground">
                        <template v-if="b.others.length">
                            <Link v-for="o in b.others" :key="o.lot_id" :href="`/cellar/lots/${o.lot_id}`" class="block hover:underline">{{ o.name }}<template v-if="o.vintage"> {{ o.vintage }}</template> · {{ qty(o.volume) }} L</Link>
                        </template>
                        <span v-else>—</span>
                    </td>
                    <td class="px-3 py-2.5">
                        <div class="flex items-center justify-end gap-0.5">
                            <button type="button" class="p-1.5 text-muted-foreground hover:text-foreground" :title="t('Show only this barrel')" :aria-label="t('Show only :name', { name: b.name })" @click="emit('focus', b.vessel_id)"><Focus class="size-3.5" /></button>
                            <button v-if="canEdit" type="button" class="p-1.5 text-muted-foreground hover:text-foreground" :title="t('Record a reading')" :aria-label="t('Record a reading in :name', { name: b.name })" @click="emit('reading', b.vessel_id)"><FlaskConical class="size-3.5" /></button>
                            <Link :href="`/cellar/vessels/${b.vessel_id}`" class="p-1.5 text-muted-foreground hover:text-foreground" :title="t('Open the vessel page')" :aria-label="t('Open :name', { name: b.name })"><ExternalLink class="size-3.5" /></Link>
                        </div>
                    </td>
                </tr>
                <tr v-if="rows.length === 0">
                    <td :colspan="9" class="px-3 py-10 text-center text-muted-foreground">{{ t('This lot is not in a vessel.') }}</td>
                </tr>
            </tbody>
            <!-- A single barrel (one in view, or a lot in one) has nothing to combine. -->
            <tfoot v-if="combined.barrels > 1">
                <tr class="border-t-2 border-border bg-muted/40 font-medium">
                    <td class="px-3 py-2.5">{{ t('Whole lot') }}<span class="block font-normal text-muted-foreground">{{ t(':n barrels', { n: combined.barrels }) }}</span></td>
                    <td class="px-3 py-2.5" />
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ qty(combined.volume) }} L</td>
                    <td v-if="!compact" class="px-3 py-2.5 text-muted-foreground tabular-nums">{{ n(Number(combined.capacity) > 0 ? (Number(combined.volume) / Number(combined.capacity)) * 100 : 0, 0) }}% · {{ qty(combined.capacity) }} L</td>
                    <td v-for="c in COLUMNS" :key="c.key" class="px-3 py-2.5 text-right tabular-nums">
                        <template v-if="combined.parameters[c.key]?.average !== null && combined.parameters[c.key]?.average !== undefined">
                            {{ n(combined.parameters[c.key]!.average, c.digits) }}
                            <span class="block text-3xs font-normal text-muted-foreground" :title="t('Weighted by the litres in each barrel')">
                                {{ t('avg of :a of :b', { a: counted(c.key), b: combined.barrels }) }}
                            </span>
                            <span v-if="c.key === 'free_so2' && combined.parameters.free_so2!.min !== combined.parameters.free_so2!.max" class="block text-3xs font-normal text-muted-foreground">
                                {{ n(combined.parameters.free_so2!.min, 0) }}–{{ n(combined.parameters.free_so2!.max, 0) }}
                            </span>
                        </template>
                        <span v-else class="font-normal text-muted-foreground">—</span>
                    </td>
                    <td v-if="!compact" class="px-3 py-2.5" />
                    <td class="px-3 py-2.5" />
                </tr>
            </tfoot>
        </table>
    </div>
</template>
