<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Pencil, Trash2 } from 'lucide-vue-next';

import AnalysisCharts from '@/components/cellar/lot/tabs/AnalysisCharts.vue';
import TablePager from '@/components/cellar/lot/tabs/TablePager.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ANALYSIS_FIELDS, qty } from '@/lib/cellar';
import { formatMoney } from '@/lib/money';
import type { AnalysisRow, LotBottlingRow, LotTransferRow, WineLotDetail } from '@/types/cellar';
import type { Paginated, SharedProps } from '@/types';
import type { MenuItem } from '@/types/ui';

/**
 * The lot page's record tabs — Analysis, Additions, Tasting, Transfers, Processes — as tables that
 * page on the server. One component with a table per kind keeps the columns, the empty states and the
 * row menu alike; the page (Show.vue) owns navigation, the edit panels and deletes.
 */
type Kind = 'analysis' | 'additions' | 'tasting' | 'transfers' | 'processes';
type Addition = WineLotDetail['additions'][number];
type Tasting = WineLotDetail['tasting_notes'][number] & { vessel_name?: string | null };
type Process = WineLotDetail['processes'][number];

const props = defineProps<{
    kind: Kind;
    table: Paginated<never> | null;
    trend: AnalysisRow[];
    focusId: string | null;
    lot: WineLotDetail;
    bottlings: Paginated<LotBottlingRow> | null;
    canEdit: boolean;
    finance: boolean;
}>();

const emit = defineEmits<{
    page: [page: number];
    perPage: [perPage: number];
    edit: [kind: 'analysis' | 'addition' | 'tasting', record: never];
    remove: [url: string, confirmTitle?: string];
    bottle: [];
}>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const locale = computed(() => page.props.locale);
const currency = computed(() => page.props.org?.default_currency ?? 'EUR');
const money = (m: { minor: number } | null | undefined) => (m ? formatMoney(m.minor, currency.value) : '—');
const date = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

const rows = computed(() => (props.table?.data ?? []) as unknown[]);
const meta = computed(() => props.table?.meta ?? { current_page: 1, last_page: 1, per_page: 25, total: 0 });

const menu = computed<MenuItem[]>(() => (props.canEdit ? [
    { key: 'edit', label: t('Edit'), icon: Pencil },
    { key: 'delete', label: t('Delete'), icon: Trash2, destructive: true },
] : []));
const deleteOnly = computed<MenuItem[]>(() => (props.canEdit ? [{ key: 'delete', label: t('Delete'), icon: Trash2, destructive: true }] : []));
const reverseOnly = computed<MenuItem[]>(() => (props.canEdit ? [{ key: 'delete', label: t('Reverse'), icon: Trash2, destructive: true }] : []));

const base = computed(() => `/cellar/lots/${props.lot.id}`);

/** Only the parameters this page of readings actually has values for, so the table is not 14 empty columns. */
const analysisCols = computed(() => ANALYSIS_FIELDS.filter((f) => (rows.value as AnalysisRow[]).some((r) => r[f.key] !== null && r[f.key] !== undefined)));

const th = 'px-3 py-2.5 font-medium tracking-[0.08em] uppercase';
const td = 'px-3 py-2.5';
</script>

<template>
    <div class="flex flex-col gap-3">
        <!-- Analysis: charts, then every reading -->
        <AnalysisCharts v-if="kind === 'analysis'" :trend="trend" :focus-id="focusId" />

        <!-- Planned additions wait here, above the table of those made -->
        <div v-if="kind === 'additions' && lot.planned_additions.length" class="border border-dashed border-border p-3 text-xs">
            <p class="mb-1 font-medium">{{ t('Planned — waiting for the cellar team · :n', { n: lot.planned_additions.length }) }}</p>
            <p v-for="p in lot.planned_additions" :key="p.id" class="text-muted-foreground">
                {{ p.planned_for }} · {{ p.product_name }} {{ qty(p.quantity) }} {{ p.unit }}<template v-if="p.vessel"> → {{ p.vessel.name }}</template>
            </p>
        </div>

        <div class="overflow-hidden border border-border bg-card">
            <div class="overflow-x-auto">
                <!-- Analysis -->
                <table v-if="kind === 'analysis'" class="w-full min-w-[40rem] text-xs">
                    <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                        <tr>
                            <th scope="col" :class="th">{{ t('Date') }}</th>
                            <th scope="col" :class="th">{{ t('Barrel') }}</th>
                            <th v-for="f in analysisCols" :key="f.key" scope="col" :class="[th, 'text-right']">{{ t(f.label) }}</th>
                            <th scope="col" :class="th">{{ t('Note') }}</th>
                            <th scope="col" class="w-10 px-3 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in (rows as AnalysisRow[])" :key="a.id" class="border-b border-border last:border-b-0 hover:bg-muted/40">
                            <td :class="[td, 'whitespace-nowrap']">{{ date(a.date) }}</td>
                            <td :class="td">{{ a.vessel_name ?? t('Whole lot') }}</td>
                            <td v-for="f in analysisCols" :key="f.key" :class="[td, 'text-right tabular-nums']">{{ a[f.key] !== null && a[f.key] !== undefined ? qty(a[f.key]) : '—' }}</td>
                            <td :class="[td, 'text-muted-foreground']">{{ a.note ?? '' }}</td>
                            <td :class="td"><DropdownMenu v-if="menu.length" :items="menu" align="right" @select="(k) => (k === 'edit' ? emit('edit', 'analysis', a as never) : emit('remove', `${base}/analyses/${a.id}`))" /></td>
                        </tr>
                        <tr v-if="rows.length === 0"><td :colspan="analysisCols.length + 4" class="px-3 py-10 text-center text-muted-foreground">{{ t('No analyses yet.') }}</td></tr>
                    </tbody>
                </table>

                <!-- Additions -->
                <table v-else-if="kind === 'additions'" class="w-full min-w-[40rem] text-xs">
                    <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                        <tr>
                            <th scope="col" :class="th">{{ t('Date') }}</th>
                            <th scope="col" :class="th">{{ t('Addition') }}</th>
                            <th scope="col" :class="[th, 'text-right']">{{ t('Quantity') }}</th>
                            <th scope="col" :class="th">{{ t('Barrel') }}</th>
                            <th v-if="finance" scope="col" :class="[th, 'text-right']">{{ t('Cost') }}</th>
                            <th scope="col" :class="th">{{ t('Note') }}</th>
                            <th scope="col" class="w-10 px-3 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in (rows as Addition[])" :key="a.id" class="border-b border-border last:border-b-0 hover:bg-muted/40">
                            <td :class="[td, 'whitespace-nowrap']">{{ date(a.date) }}</td>
                            <td :class="td"><span class="font-medium">{{ a.name }}</span><span v-if="a.category" class="block text-muted-foreground">{{ a.category }}</span></td>
                            <td :class="[td, 'text-right tabular-nums']">{{ qty(a.quantity) }} {{ a.unit }}</td>
                            <td :class="td">{{ a.vessel?.name ?? t('Whole lot') }}</td>
                            <td v-if="finance" :class="[td, 'text-right tabular-nums']">{{ money(a.total_cost) }}</td>
                            <td :class="[td, 'text-muted-foreground']">{{ a.note ?? '' }}</td>
                            <td :class="td"><DropdownMenu v-if="menu.length" :items="menu" align="right" @select="(k) => (k === 'edit' ? emit('edit', 'addition', a as never) : emit('remove', `${base}/additions/${a.id}`))" /></td>
                        </tr>
                        <tr v-if="rows.length === 0"><td :colspan="7" class="px-3 py-10 text-center text-muted-foreground">{{ t('No additions yet.') }}</td></tr>
                    </tbody>
                    <tfoot v-if="finance && lot.cost_breakdown && lot.cost_breakdown.additions > 0">
                        <tr class="border-t-2 border-border bg-muted/40 font-medium">
                            <td :class="td" colspan="4">{{ t('All additions to this lot') }}</td>
                            <td :class="[td, 'text-right tabular-nums']">{{ formatMoney(lot.cost_breakdown.additions, currency) }}</td>
                            <td :class="td" colspan="2" />
                        </tr>
                    </tfoot>
                </table>

                <!-- Tasting -->
                <table v-else-if="kind === 'tasting'" class="w-full min-w-[40rem] text-xs">
                    <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                        <tr>
                            <th scope="col" :class="th">{{ t('Date') }}</th>
                            <th scope="col" :class="th">{{ t('Barrel') }}</th>
                            <th scope="col" :class="[th, 'text-right']">{{ t('Score') }}</th>
                            <th scope="col" :class="th">{{ t('Appearance') }}</th>
                            <th scope="col" :class="th">{{ t('Nose') }}</th>
                            <th scope="col" :class="th">{{ t('Palate') }}</th>
                            <th scope="col" :class="th">{{ t('Overall') }}</th>
                            <th scope="col" class="w-10 px-3 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="n in (rows as Tasting[])" :key="n.id" class="border-b border-border align-top last:border-b-0 hover:bg-muted/40">
                            <td :class="[td, 'whitespace-nowrap']">{{ date(n.date) }}</td>
                            <td :class="td">{{ n.vessel_name ?? t('Whole lot') }}</td>
                            <td :class="[td, 'text-right font-semibold tabular-nums']">{{ n.score !== null ? `${n.score}/100` : '—' }}</td>
                            <td :class="td">{{ n.appearance ?? '—' }}</td>
                            <td :class="td">{{ n.nose ?? '—' }}</td>
                            <td :class="td">{{ n.palate ?? '—' }}</td>
                            <td :class="td">{{ n.overall ?? n.note ?? '—' }}</td>
                            <td :class="td"><DropdownMenu v-if="menu.length" :items="menu" align="right" @select="(k) => (k === 'edit' ? emit('edit', 'tasting', n as never) : emit('remove', `${base}/tasting-notes/${n.id}`))" /></td>
                        </tr>
                        <tr v-if="rows.length === 0"><td :colspan="8" class="px-3 py-10 text-center text-muted-foreground">{{ t('No tasting notes yet.') }}</td></tr>
                    </tbody>
                </table>

                <!-- Transfers -->
                <table v-else-if="kind === 'transfers'" class="w-full min-w-[40rem] text-xs">
                    <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                        <tr>
                            <th scope="col" :class="th">{{ t('Date') }}</th>
                            <th scope="col" :class="th">{{ t('Move') }}</th>
                            <th scope="col" :class="th">{{ t('From') }}</th>
                            <th scope="col" :class="th">{{ t('To') }}</th>
                            <th scope="col" :class="[th, 'text-right']">{{ t('Litres') }}</th>
                            <th scope="col" :class="th">{{ t('Note') }}</th>
                            <th scope="col" class="w-10 px-3 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="x in (rows as LotTransferRow[])" :key="x.id" class="border-b border-border last:border-b-0 hover:bg-muted/40">
                            <td :class="[td, 'whitespace-nowrap']">{{ date(x.date) }}</td>
                            <td :class="td">{{ x.direction === 'in' ? t('In') : t('Out') }} · {{ x.type }}</td>
                            <td :class="td"><Link v-if="x.from_lot_id !== lot.id" :href="`/cellar/lots/${x.from_lot_id}`" class="hover:underline">{{ x.from_lot_name }}</Link><span v-else>{{ t('This lot') }}</span><span v-if="x.from_vessel_name" class="block text-muted-foreground">{{ x.from_vessel_name }}</span></td>
                            <td :class="td"><Link v-if="x.to_lot_id !== lot.id" :href="`/cellar/lots/${x.to_lot_id}`" class="hover:underline">{{ x.to_lot_name }}</Link><span v-else>{{ t('This lot') }}</span><span v-if="x.to_vessel_name" class="block text-muted-foreground">{{ x.to_vessel_name }}</span></td>
                            <td :class="[td, 'text-right tabular-nums']" >{{ x.direction === 'in' ? '+' : '−' }}{{ qty(x.volume_liters) }}</td>
                            <td :class="[td, 'text-muted-foreground']">{{ x.note ?? '' }}</td>
                            <td :class="td"><DropdownMenu v-if="reverseOnly.length" :items="reverseOnly" align="right" @select="() => emit('remove', `${base}/transfers/${x.id}`)" /></td>
                        </tr>
                        <tr v-if="rows.length === 0"><td :colspan="7" class="px-3 py-10 text-center text-muted-foreground">{{ t('No transfers yet.') }}</td></tr>
                    </tbody>
                </table>

                <!-- Processes -->
                <table v-else class="w-full min-w-[32rem] text-xs">
                    <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                        <tr>
                            <th scope="col" :class="th">{{ t('Date') }}</th>
                            <th scope="col" :class="th">{{ t('Process') }}</th>
                            <th scope="col" :class="th">{{ t('Barrel') }}</th>
                            <th scope="col" :class="[th, 'text-right']">{{ t('Volume') }}</th>
                            <th scope="col" :class="th">{{ t('Note') }}</th>
                            <th scope="col" class="w-10 px-3 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in (rows as Process[])" :key="p.id" class="border-b border-border last:border-b-0 hover:bg-muted/40">
                            <td :class="[td, 'whitespace-nowrap']">{{ date(p.date) }}</td>
                            <td :class="[td, 'font-medium']">{{ p.kind }}</td>
                            <td :class="td">{{ p.vessel_name ?? t('Whole lot') }}</td>
                            <td :class="[td, 'text-right tabular-nums']">{{ p.volume ? `${qty(p.volume)} L` : '—' }}</td>
                            <td :class="[td, 'text-muted-foreground']">{{ p.note ?? '' }}</td>
                            <td :class="td"><DropdownMenu v-if="deleteOnly.length" :items="deleteOnly" align="right" @select="() => emit('remove', `${base}/processes/${p.id}`)" /></td>
                        </tr>
                        <tr v-if="rows.length === 0"><td :colspan="6" class="px-3 py-10 text-center text-muted-foreground">{{ t('No processes yet.') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <TablePager :meta="meta" @page="emit('page', $event)" @per-page="emit('perPage', $event)" />
        </div>

        <!-- Bottlings: where wine leaves the lot -->
        <div v-if="kind === 'transfers' && bottlings && bottlings.data.length" class="overflow-hidden border border-border bg-card">
            <p class="border-b border-border px-3 py-2.5 text-sm font-semibold">{{ t('Bottlings') }}</p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[32rem] text-xs">
                    <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                        <tr>
                            <th scope="col" :class="th">{{ t('Date') }}</th>
                            <th scope="col" :class="th">{{ t('Bottled') }}</th>
                            <th scope="col" :class="[th, 'text-right']">{{ t('Wine used') }}</th>
                            <th scope="col" :class="th">{{ t('Product') }}</th>
                            <th scope="col" class="w-10 px-3 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in bottlings.data" :key="b.id" class="border-b border-border last:border-b-0 hover:bg-muted/40">
                            <td :class="[td, 'whitespace-nowrap']">{{ date(b.date) }}</td>
                            <td :class="td">{{ b.bottle_count }} × {{ b.bottle_volume_ml }} ml</td>
                            <td :class="[td, 'text-right tabular-nums']">{{ qty(b.volume_used) }} L</td>
                            <td :class="td"><Link v-if="b.inventory_item_id" :href="`/inventory/${b.inventory_item_id}`" class="hover:underline">{{ b.inventory_item_name }}</Link></td>
                            <td :class="td"><DropdownMenu v-if="reverseOnly.length" :items="reverseOnly" align="right" @select="() => emit('remove', `${base}/bottlings/${b.id}`, t('Reverse this bottling? The wine goes back into its vessels and the bottles leave stock.'))" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
