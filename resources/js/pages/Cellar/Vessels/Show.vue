<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, ExternalLink, Pencil, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import AssignWinePanel from '@/components/cellar/AssignWinePanel.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import HistoryList from '@/components/cellar/HistoryList.vue';
import VesselFormPanel from '@/components/cellar/VesselFormPanel.vue';
import AnalysisPanel from '@/components/cellar/lot/AnalysisPanel.vue';
import TastingPanel from '@/components/cellar/lot/TastingPanel.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { ANALYSIS_FIELDS, VESSEL_TYPE_LABELS, qty } from '@/lib/cellar';
import type { AnalysisRow, HistoryEntry, LotOption, MapVessel, Vessel, WineLotDetail } from '@/types/cellar';
import type { SharedProps } from '@/types';
import type { TabItem } from '@/types/ui';

/**
 * A vessel: how full it is, what it holds, the SO₂ in it, the analyses and
 * tasting notes taken in it (whatever lot), its details and fault state.
 */
interface VesselPage extends Vessel {
    analyses: (AnalysisRow & { wine_lot_id: string; lot_name: string | null })[];
    tasting_notes: (WineLotDetail['tasting_notes'][number] & { wine_lot_id: string; lot_name: string | null })[];
    so2: { wine_lot_id: string; free_so2: number | null; total_so2: number | null; reading_date: string | null; reading_in_vessel: boolean; projected_free_so2: number | null; latest_addition: { name: string; quantity: string; unit: string; date: string | null } | null }[];
}

const props = defineProps<{ vessel: VesselPage; rooms: string[]; lots: LotOption[]; history?: HistoryEntry[] }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can } = useAuth();
const canManage = computed(() => can('cellar.manage'));
const locale = computed(() => page.props.locale);
const date = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

const v = computed(() => props.vessel);
const pct = computed(() => (Number(v.value.capacity_liters) > 0 ? (Number(v.value.current_volume) / Number(v.value.capacity_liters)) * 100 : 0));

type TabKey = 'overview' | 'analysis' | 'tasting' | 'history' | 'details';
const tab = ref<TabKey>('overview');
const tabs = computed<TabItem[]>(() => [
    { label: t('Overview'), value: 'overview' },
    { label: `${t('Analysis')} (${v.value.analyses.length})`, value: 'analysis' },
    { label: `${t('Tasting')} (${v.value.tasting_notes.length})`, value: 'tasting' },
    { label: t('History'), value: 'history' },
    { label: t('Details'), value: 'details' },
]);
watch(tab, (k) => {
    if (k === 'history' && props.history === undefined) router.reload({ only: ['history'] });
});

const panel = ref<'edit' | 'assign' | 'analysis' | 'tasting' | null>(null);
const panelLot = ref<string | null>(null);
const editAnalysis = ref<AnalysisRow | null>(null);
const editTasting = ref<WineLotDetail['tasting_notes'][number] | null>(null);

/** The analysis/tasting panels need a lot; a vessel holding several asks which. */
const contents = computed(() => v.value.lots ?? []);
const lotForPanel = computed<WineLotDetail | null>(() => {
    const c = contents.value.find((l) => l.wine_lot_id === panelLot.value);
    if (!c || !c.lot) return null;

    return { id: c.wine_lot_id, name: c.lot.name, current_volume: c.volume, vessels: [{ vessel_lot_id: c.vessel_lot_id, vessel_id: v.value.id, vessel_name: v.value.name, volume: c.volume }] } as unknown as WineLotDetail;
});

function newRecord(kind: 'analysis' | 'tasting', lotId: string): void {
    panelLot.value = lotId;
    editAnalysis.value = null;
    editTasting.value = null;
    panel.value = kind;
}

// Fault toggle
const faultNote = ref(v.value.fault_note ?? '');
function setFault(on: boolean): void {
    router.patch(`/cellar/vessels/${v.value.id}`, { is_faulty: on, fault_note: on ? faultNote.value || null : null }, { preserveScroll: true });
}

async function destroy(): Promise<void> {
    const ok = await confirmDialog({ title: t('Delete :name?', { name: v.value.name }), description: t('A vessel that still holds wine cannot be deleted.'), confirmLabel: t('Delete'), tone: 'danger' });
    if (ok) router.delete(`/cellar/vessels/${v.value.id}`);
}

/** Readings and tasting notes delete at once — the toast offers Undo. */
function removeRecord(url: string): void {
    router.delete(url, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="v.name">
        <div class="flex flex-col gap-5">
            <Link href="/cellar" class="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ArrowLeft class="size-3.5" /> {{ t('Cellar') }}</Link>
            <PageHeader :title="v.name" :description="[...new Set([t(VESSEL_TYPE_LABELS[v.type] ?? v.type), v.material, v.room, v.location].filter(Boolean))].join(' · ')">
                <template #actions>
                    <span v-if="v.is_faulty" class="inline-flex items-center gap-1 bg-tag-red px-2 py-0.5 text-[11px] text-tag-red-foreground"><AlertTriangle class="size-3" /> {{ t('Faulty') }}</span>
                    <Button v-if="canManage" size="sm" variant="outline" @click="panel = 'edit'"><Pencil class="size-3.5" /> {{ t('Edit') }}</Button>
                    <Button v-if="can('cellar.delete')" size="sm" variant="ghost" :aria-label="t('Delete')" @click="destroy"><Trash2 class="size-3.5" /></Button>
                </template>
            </PageHeader>
            <CellarTabs current="overview" />
            <Tabs :items="tabs" :current="tab" @select="tab = $event as TabKey" />

            <div v-if="tab === 'overview'" class="grid gap-4 lg:grid-cols-2">
                <Card class="flex flex-col gap-3 p-4">
                    <SectionHeader :title="t('Capacity')" />
                    <p class="text-3xl font-semibold tabular-nums">{{ qty(v.current_volume) }} <span class="text-base font-normal text-muted-foreground">/ {{ qty(v.capacity_liters) }} L ({{ Math.round(pct) }}%)</span></p>
                    <ProgressBar :value="pct" />
                    <div v-for="s in v.so2" :key="s.wine_lot_id" class="text-xs">
                        <p>
                            SO₂ <span class="font-semibold">{{ s.free_so2 ?? '—' }}</span> {{ t('free') }} / <span class="font-semibold">{{ s.total_so2 ?? '—' }}</span> {{ t('total') }}
                            <span class="text-muted-foreground">· {{ date(s.reading_date) }}<template v-if="!s.reading_in_vessel"> ({{ t('lot reading') }})</template></span>
                        </p>
                        <p v-if="s.projected_free_so2 !== null" class="text-tag-blue-foreground">→ {{ s.projected_free_so2 }} {{ t('free after :name', { name: s.latest_addition?.name ?? '' }) }}</p>
                    </div>
                </Card>
                <Card class="flex flex-col gap-2 p-4 text-sm">
                    <SectionHeader :title="t('Contents')" />
                    <div v-for="c in contents" :key="c.vessel_lot_id" class="flex items-center justify-between border-b border-border py-2 last:border-0">
                        <Link :href="`/cellar/lots/${c.wine_lot_id}?vessel=${v.id}`" class="flex flex-col hover:underline">
                            <span class="inline-flex items-center gap-1 font-medium">{{ c.lot?.name }} <ExternalLink class="size-3" /></span>
                            <span class="text-xs text-muted-foreground">{{ [c.lot?.lot_number, c.lot?.grape_variety].filter(Boolean).join(' · ') }}</span>
                        </Link>
                        <span class="tabular-nums">{{ qty(c.volume) }} L</span>
                    </div>
                    <p v-if="contents.length === 0" class="text-xs text-muted-foreground">{{ t('This vessel is empty.') }}</p>
                    <Button v-if="canManage && v.status !== 'RETIRED'" size="sm" variant="outline" class="mt-2 w-fit" @click="panel = 'assign'">{{ t('Assign wine') }}</Button>
                </Card>
            </div>

            <div v-else-if="tab === 'analysis'" class="flex flex-col gap-3">
                <div v-if="canManage && contents.length" class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-muted-foreground">{{ contents.length > 1 ? t('Select which wine lot to analyse:') : '' }}</span>
                    <Button v-for="c in contents" :key="c.wine_lot_id" size="sm" variant="outline" @click="newRecord('analysis', c.wine_lot_id)">+ {{ t('Analysis') }} · {{ c.lot?.name }}</Button>
                </div>
                <Card>
                    <div v-for="a in v.analyses" :key="a.id" class="flex gap-3 border-b border-border px-3 py-2 text-xs last:border-0">
                        <span class="w-24 shrink-0 text-muted-foreground">{{ date(a.date) }}</span>
                        <span class="w-28 shrink-0 truncate">{{ a.lot_name }}</span>
                        <span class="min-w-0 flex-1">
                            <span v-for="f in ANALYSIS_FIELDS.filter((x) => a[x.key] !== null && a[x.key] !== undefined)" :key="f.key" class="mr-3 inline-block"><span class="text-muted-foreground">{{ t(f.label) }}</span> {{ qty(a[f.key]) }}</span>
                        </span>
                        <template v-if="canManage">
                            <button type="button" class="text-muted-foreground hover:text-foreground" :aria-label="t('Edit')" @click="((panelLot = a.wine_lot_id), (editAnalysis = a), (panel = 'analysis'))"><Pencil class="size-3.5" /></button>
                            <button type="button" class="text-muted-foreground hover:text-destructive" :aria-label="t('Delete')" @click="removeRecord(`/cellar/lots/${a.wine_lot_id}/analyses/${a.id}`)"><Trash2 class="size-3.5" /></button>
                        </template>
                    </div>
                    <p v-if="v.analyses.length === 0" class="p-8 text-center text-xs text-muted-foreground">{{ t('No analyses taken in this vessel yet.') }}</p>
                </Card>
            </div>

            <div v-else-if="tab === 'tasting'" class="flex flex-col gap-3">
                <div v-if="canManage && contents.length" class="flex flex-wrap gap-2">
                    <Button v-for="c in contents" :key="c.wine_lot_id" size="sm" variant="outline" @click="newRecord('tasting', c.wine_lot_id)">+ {{ t('Tasting') }} · {{ c.lot?.name }}</Button>
                </div>
                <Card>
                    <div v-for="n in v.tasting_notes" :key="n.id" class="flex gap-3 border-b border-border px-3 py-2 text-xs last:border-0">
                        <span class="w-10 shrink-0 text-lg font-semibold">{{ n.score ?? '—' }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-muted-foreground">{{ date(n.date) }} · {{ n.lot_name }}</p>
                            <p>{{ [n.nose, n.palate, n.overall].filter(Boolean).join(' · ') || n.note }}</p>
                        </div>
                        <template v-if="canManage">
                            <button type="button" class="text-muted-foreground hover:text-foreground" :aria-label="t('Edit')" @click="((panelLot = n.wine_lot_id), (editTasting = n), (panel = 'tasting'))"><Pencil class="size-3.5" /></button>
                            <button type="button" class="text-muted-foreground hover:text-destructive" :aria-label="t('Delete')" @click="removeRecord(`/cellar/lots/${n.wine_lot_id}/tasting-notes/${n.id}`)"><Trash2 class="size-3.5" /></button>
                        </template>
                    </div>
                    <p v-if="v.tasting_notes.length === 0" class="p-8 text-center text-xs text-muted-foreground">{{ t('No tasting notes for this vessel yet.') }}</p>
                </Card>
            </div>

            <HistoryList v-else-if="tab === 'history'" :entries="history" />

            <div v-else class="grid gap-4 lg:grid-cols-2">
                <Card class="flex flex-col gap-1.5 p-4 text-xs">
                    <SectionHeader :title="t('Details')" />
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Type') }}</span><span>{{ t(VESSEL_TYPE_LABELS[v.type] ?? v.type) }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Material') }}</span><span>{{ v.material ?? '—' }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Capacity') }}</span><span>{{ qty(v.capacity_liters) }} L</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Room') }}</span><span>{{ v.room ?? '—' }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Location') }}</span><span>{{ v.location ?? '—' }}</span></div>
                    <p v-if="v.notes" class="mt-2 whitespace-pre-line">{{ v.notes }}</p>
                </Card>
                <Card v-if="canManage" class="flex flex-col gap-3 p-4">
                    <SectionHeader :title="t('Fault')" />
                    <FormField v-slot="{ id, invalid }" :label="t('Fault note (optional)')"><Input :id="id" :invalid="invalid" v-model="faultNote" /></FormField>
                    <SwitchRow :model-value="v.is_faulty" :label="t('Mark as faulty')" :hint="t('A faulty vessel shows red on the map.')" @update:model-value="setFault" />
                </Card>
            </div>
        </div>

        <VesselFormPanel :open="panel === 'edit'" :vessel="v" :rooms="rooms" @close="panel = null" />
        <AssignWinePanel :open="panel === 'assign'" :vessels="[v as unknown as MapVessel]" :lots="lots" @close="panel = null" />
        <AnalysisPanel v-if="lotForPanel" :open="panel === 'analysis'" :lot="lotForPanel" :record="editAnalysis" :vessel-id="v.id" @close="panel = null" />
        <TastingPanel
            v-if="lotForPanel"
            :open="panel === 'tasting'"
            :lot-id="lotForPanel.id"
            :lot-name="lotForPanel.name"
            :vessels="lotForPanel.vessels"
            :record="editTasting"
            :vessel-id="v.id"
            @close="panel = null"
        />
    </AppLayout>
</template>
