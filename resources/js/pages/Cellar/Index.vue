<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import AssignWinePanel from '@/components/cellar/AssignWinePanel.vue';
import AttentionList from '@/components/cellar/AttentionList.vue';
import BulkVesselsPanel from '@/components/cellar/BulkVesselsPanel.vue';
import CellarMap from '@/components/cellar/CellarMap.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import LotFormPanel from '@/components/cellar/LotFormPanel.vue';
import MapReadingsPanel from '@/components/cellar/MapReadingsPanel.vue';
import TransferPanel from '@/components/cellar/lot/TransferPanel.vue';
import UnassignWinePanel from '@/components/cellar/UnassignWinePanel.vue';
import VesselFormPanel from '@/components/cellar/VesselFormPanel.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import Disclosure from '@/components/ui/Disclosure.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import StatCard from '@/components/ui/StatCard.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { LOT_STATUS_LABELS, LOT_STATUS_TONES, lotRefFromOption, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import type { CellarAttentionItem, LotOption, MapVessel, Vessel, WineLotSummary } from '@/types/cellar';
import type { SharedProps } from '@/types';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * Cellar home — the cellar at a glance: headline figures, the map (which is
 * also where vessels are arranged and wine is placed), the lots in the cellar
 * by vintage, and the bottled archive.
 */
const props = defineProps<{
    stats: { vessels: number; lots: number; volume: string };
    vessels: MapVessel[];
    lots: WineLotSummary[];
    bottled: WineLotSummary[];
    /** All bottled lots; `bottled` is only the latest few. */
    bottledTotal: number;
    lotOptions: LotOption[];
    attention: CellarAttentionItem[];
}>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can } = useAuth();
const canManage = computed(() => can('cellar.manage'));
const locale = computed(() => page.props.locale);

const date = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short' }) : '');

function byVintage(list: WineLotSummary[]): { vintage: string; lots: WineLotSummary[]; volume: number }[] {
    const groups = new Map<string, WineLotSummary[]>();
    for (const l of list) {
        const key = l.vintage ?? '—';
        groups.set(key, [...(groups.get(key) ?? []), l]);
    }

    return [...groups.entries()]
        .sort(([a], [b]) => b.localeCompare(a))
        .map(([vintage, lots]) => ({ vintage, lots, volume: lots.reduce((s, l) => s + Number(l.current_volume), 0) }));
}

const active = computed(() => byVintage(props.lots));
const archive = computed(() => byVintage(props.bottled));

const rooms = computed(() => [...new Set(props.vessels.map((v) => v.room ?? 'Main Cellar'))]);

// Panels
const lotPanel = ref(false);
const vesselPanel = ref(false);
const bulkPanel = ref(false);
const bulkRoom = ref('');
const assignIds = ref<string[] | null>(null);
const unassignIds = ref<string[] | null>(null);
const pick = (ids: string[] | null) => props.vessels.filter((v) => (ids ?? []).includes(v.id));

// From the map's action bar: record SO₂ for the picked vessels, or move one vessel's wine.
const readingIds = ref<string[] | null>(null);
const map = ref<InstanceType<typeof CellarMap> | null>(null);
/** An action on the selection is done — start the next one from a clean map. */
function doneWithSelection(): void {
    readingIds.value = null;
    moveFrom.value = null;
    map.value?.select([]);
}
const moveFrom = ref<string | null>(null);
const moveLot = computed(() => {
    const v = props.vessels.find((x) => x.id === moveFrom.value);
    const lotId = v?.lots?.find((l) => l.lot !== null)?.wine_lot_id;
    const option = props.lotOptions.find((l) => l.id === lotId);

    return option ? lotRefFromOption(option) : null;
});

function analysisLine(l: WineLotSummary): string {
    const a = l.latest_analysis;
    if (!a) return '';
    const parts = [date(a.date)];
    if (a.free_so2 !== null || a.total_so2 !== null) parts.push(`SO₂ ${qty(a.free_so2)}/${qty(a.total_so2)}`);
    if (a.ph !== null) parts.push(`pH ${qty(a.ph)}`);
    if (a.alcohol !== null) parts.push(`${qty(a.alcohol)}%`);

    return parts.join(' · ');
}

function additionLine(l: WineLotSummary): string {
    const a = l.latest_addition;

    return a ? `${a.name} ${qty(a.quantity)} ${a.unit} · ${date(a.date)}` : '';
}
</script>

<template>
    <Head :title="t('Cellar')" />
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Cellar')">
                <template v-if="canManage" #actions>
                    <Button size="sm" variant="outline" @click="vesselPanel = true"><Plus class="size-3.5" /> {{ t('Vessel') }}</Button>
                    <Button size="sm" @click="lotPanel = true"><Plus class="size-3.5" /> {{ t('Wine lot') }}</Button>
                </template>
            </PageHeader>
            <CellarTabs current="overview" />

            <AttentionList :items="attention" />

            <div class="grid grid-cols-3 gap-3">
                <StatCard :label="t('Vessels')" :value="String(stats.vessels)" />
                <StatCard :label="t('Active lots')" :value="String(stats.lots)" />
                <StatCard :label="t('Volume (L)')" :value="qty(stats.volume)" />
            </div>

            <Card class="p-4">
                <SectionHeader :title="t('Cellar map')" />
                <CellarMap
                    ref="map"
                    class="mt-3"
                    :vessels="vessels"
                    :can-manage="canManage"
                    @assign="assignIds = $event"
                    @unassign="unassignIds = $event"
                    @record-so2="readingIds = $event"
                    @move="moveFrom = $event"
                    @add-vessels="(r) => ((bulkRoom = r), (bulkPanel = true))"
                />
            </Card>

            <section class="flex flex-col gap-3">
                <SectionHeader :title="t('Wine lots')" />
                <div v-if="lots.length === 0" class="border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                    {{ t('No active wine lots. Create a lot when you receive grapes or start a new batch.') }}
                </div>
                <div v-for="g in active" :key="g.vintage" class="flex flex-col gap-1">
                    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        {{ t('Vintage :v', { v: g.vintage }) }} · {{ t(':n lots', { n: g.lots.length }) }} · {{ qty(g.volume) }} L
                    </h4>
                    <!-- desktop -->
                    <Card class="hidden overflow-x-auto md:block">
                        <table class="w-full text-xs">
                            <thead class="bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium">{{ t('Lot') }}</th>
                                    <th class="px-3 py-2 text-left font-medium">{{ t('Grape') }}</th>
                                    <th class="px-3 py-2 text-left font-medium">{{ t('Status') }}</th>
                                    <th class="px-3 py-2 text-left font-medium">{{ t('Last analysis') }}</th>
                                    <th class="px-3 py-2 text-left font-medium">{{ t('Last addition') }}</th>
                                    <th class="px-3 py-2 text-right font-medium">{{ t('Volume') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="l in g.lots" :key="l.id" class="border-t border-border hover:bg-accent/50">
                                    <td class="px-3 py-2">
                                        <Link :href="`/cellar/lots/${l.id}`" class="font-medium text-foreground hover:underline">{{ l.name }}</Link>
                                        <div class="text-[11px] text-muted-foreground">{{ l.lot_number }}</div>
                                    </td>
                                    <td class="px-3 py-2">{{ l.grape_variety }}</td>
                                    <td class="px-3 py-2"><span :class="cn('px-1.5 py-0.5 text-[10px] font-medium', LOT_STATUS_TONES[l.status])">{{ t(LOT_STATUS_LABELS[l.status] ?? l.status) }}</span></td>
                                    <td class="px-3 py-2 text-muted-foreground">{{ analysisLine(l) || '—' }}</td>
                                    <td class="px-3 py-2 text-muted-foreground">{{ additionLine(l) || '—' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ qty(l.current_volume) }} <span class="text-muted-foreground">/ {{ qty(l.initial_volume) }} L</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </Card>
                    <!-- mobile -->
                    <div class="flex flex-col gap-2 md:hidden">
                        <Link v-for="l in g.lots" :key="l.id" :href="`/cellar/lots/${l.id}`" class="border border-border bg-card p-3 text-xs">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-medium">{{ l.name }}</span>
                                <span :class="cn('px-1.5 py-0.5 text-[10px] font-medium', LOT_STATUS_TONES[l.status])">{{ t(LOT_STATUS_LABELS[l.status] ?? l.status) }}</span>
                            </div>
                            <p class="text-muted-foreground">{{ l.grape_variety }} · {{ qty(l.current_volume) }} L {{ t('of :n L', { n: qty(l.initial_volume) }) }}</p>
                            <p v-if="analysisLine(l)" class="mt-1 text-muted-foreground">{{ analysisLine(l) }}</p>
                            <p v-if="additionLine(l)" class="text-muted-foreground">{{ additionLine(l) }}</p>
                        </Link>
                    </div>
                </div>
            </section>

            <Disclosure v-if="bottled.length" :title="t('Bottled wines (:n)', { n: bottledTotal })">
                <div v-for="g in archive" :key="g.vintage" class="mb-3">
                    <h4 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ t('Vintage :v', { v: g.vintage }) }}</h4>
                    <div v-for="l in g.lots" :key="l.id" class="flex flex-wrap items-center justify-between gap-2 border-b border-border py-2 text-xs last:border-0">
                        <Link :href="`/cellar/lots/${l.id}`" class="font-medium hover:underline">{{ l.name }}</Link>
                        <span v-if="l.latest_bottling" class="text-muted-foreground">
                            {{ t(':n bottles', { n: l.latest_bottling.bottle_count }) }}<template v-if="l.latest_bottling.inventory_item"> → {{ l.latest_bottling.inventory_item.name }}</template> · {{ date(l.latest_bottling.date) }}
                        </span>
                        <span v-if="l.counts" class="text-muted-foreground">
                            {{ t(':a add. · :b an. · :c bot.', { a: l.counts.additions, b: l.counts.analyses, c: l.counts.bottlings }) }}
                        </span>
                    </div>
                </div>
                <Link v-if="bottledTotal > bottled.length" href="/cellar/lots?status=BOTTLED" class="inline-block pt-1 text-xs underline underline-offset-2 hover:text-foreground">
                    {{ t('Showing the latest :n — view all :total bottled lots →', { n: bottled.length, total: bottledTotal }) }}
                </Link>
            </Disclosure>
        </div>

        <LotFormPanel :open="lotPanel" :lot="null" :vessels="vessels as Vessel[]" @close="lotPanel = false" />
        <VesselFormPanel :open="vesselPanel" :vessel="null" :rooms="rooms" @close="vesselPanel = false" />
        <BulkVesselsPanel :open="bulkPanel" :room="bulkRoom" @close="bulkPanel = false" />
        <AssignWinePanel :open="assignIds !== null" :vessels="pick(assignIds)" :lots="lotOptions" @close="assignIds = null" />
        <UnassignWinePanel :open="unassignIds !== null" :vessels="pick(unassignIds)" @close="unassignIds = null" />
        <MapReadingsPanel :open="readingIds !== null" :vessels="pick(readingIds)" @close="doneWithSelection" />
        <TransferPanel v-if="moveLot" :open="moveFrom !== null" :lot="moveLot" :lots="lotOptions" :vessels="vessels" :vessel-id="moveFrom" @close="doneWithSelection" />
</template>
