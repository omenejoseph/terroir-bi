<script lang="ts">
import { ref } from 'vue';

import type { LotOption, ProductOption, Vessel } from '@/types/cellar';

type Options = { lots: LotOption[]; products: ProductOption[]; vessels: Pick<Vessel, 'id' | 'name' | 'room' | 'capacity_liters' | 'current_volume'>[] };

// Module scope, not component scope: saving a form re-renders the page (and the
// layout this drawer lives in), and the drawer must come back where it was.
const options = ref<Options | null>(null);
const loadError = ref(false);
const where = ref<string | null>(null);
const justSaved = ref<string | null>(null);
</script>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { ArrowRightLeft, Droplets, FlaskConical, Scale, Wine, Wrench } from 'lucide-vue-next';

import AdditionPanel from '@/components/cellar/lot/AdditionPanel.vue';
import AnalysisPanel from '@/components/cellar/lot/AnalysisPanel.vue';
import ProcessPanel from '@/components/cellar/lot/ProcessPanel.vue';
import TastingPanel from '@/components/cellar/lot/TastingPanel.vue';
import TransferPanel from '@/components/cellar/lot/TransferPanel.vue';
import VolumePanel from '@/components/cellar/lot/VolumePanel.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Kbd from '@/components/ui/Kbd.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { useAuth } from '@/composables/useAuth';
import { useRecordDrawer } from '@/composables/useRecordDrawer';
import { useTranslations } from '@/composables/useTranslations';
import { lotRefFromOption, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import type { ComboboxOption } from '@/types/ui';

/**
 * One way to record cellar work from anywhere: pick where (a vessel or a lot),
 * then what was done; the matching form opens for it. Opened by the cellar
 * pages' Record button or the `R` key. After a save it comes back with the
 * same place picked, so a round of readings is pick → type → save, repeated.
 */
const { t } = useTranslations();
const { can, hasModule } = useAuth();
const { state, closeRecord } = useRecordDrawer();
const enabled = computed(() => hasModule('cellar') && can('cellar.manage'));

async function load(): Promise<void> {
    try {
        const res = await fetch('/cellar/record/options', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(String(res.status));
        options.value = ((await res.json()) as { data: Options }).data;
        loadError.value = false;
    } catch {
        loadError.value = true;
    }
}

// ── Where ───────────────────────────────────────────────────────────────────
/** `v:<vessel>:<lot>` for a vessel holding a lot, `l:<lot>` for the whole lot. */
const whereOptions = computed<ComboboxOption[]>(() => {
    const lots = options.value?.lots ?? [];
    const vessels = lots
        .flatMap((l) => l.vessels.map((v) => ({ v, l })))
        .sort((a, b) => a.v.name.localeCompare(b.v.name, undefined, { numeric: true }))
        .map(({ v, l }) => ({ value: `v:${v.id}:${l.id}`, label: v.name, description: `${l.name} · ${qty(v.volume)} L`, keywords: [l.lot_number, v.room ?? ''] }));
    const whole = lots.map((l) => ({ value: `l:${l.id}`, label: l.name, description: `${l.lot_number} · ${t('whole lot')} · ${qty(l.current_volume)} L`, keywords: [l.lot_number, l.grape_variety ?? ''] }));

    return [...vessels, ...whole];
});
const parsed = computed(() => {
    const [kind, a, b] = (where.value ?? '').split(':');
    if (kind === 'v' && a && b) return { lotId: b, vesselId: a };
    if (kind === 'l' && a) return { lotId: a, vesselId: null };

    return null;
});
const lotOption = computed(() => options.value?.lots.find((l) => l.id === parsed.value?.lotId) ?? null);
const lotRef = computed(() => (lotOption.value ? lotRefFromOption(lotOption.value) : null));
const whereLabel = computed(() => whereOptions.value.find((o) => o.value === where.value)?.label ?? '');

// Recent places, per browser.
const RECENT_KEY = 'terroir.cellar.recordRecent';
const recent = ref<string[]>([]);
function readRecent(): void {
    try {
        recent.value = JSON.parse(window.localStorage.getItem(RECENT_KEY) ?? '[]') as string[];
    } catch {
        recent.value = [];
    }
}
function remember(value: string): void {
    recent.value = [value, ...recent.value.filter((v) => v !== value)].slice(0, 5);
    try {
        window.localStorage.setItem(RECENT_KEY, JSON.stringify(recent.value));
    } catch {
        // storage unavailable — recents last for this visit
    }
}
const recentOptions = computed(() => recent.value.map((v) => whereOptions.value.find((o) => o.value === v)).filter((o): o is ComboboxOption => o !== undefined));

// ── What ────────────────────────────────────────────────────────────────────
type Action = 'analysis' | 'addition' | 'transfer' | 'process' | 'tasting' | 'adjust';
const ACTIONS: { key: Action; label: string; hint: string; icon: unknown }[] = [
    { key: 'analysis', label: 'Reading', hint: 'SO₂, pH, sugar…', icon: FlaskConical },
    { key: 'addition', label: 'Addition', hint: 'from stock', icon: Droplets },
    { key: 'transfer', label: 'Move wine', hint: 'rack, top up', icon: ArrowRightLeft },
    { key: 'process', label: 'Process', hint: 'pump-over, filtration…', icon: Wrench },
    { key: 'tasting', label: 'Tasting', hint: 'notes, score', icon: Wine },
    { key: 'adjust', label: 'Volume', hint: 'loss or gain', icon: Scale },
];
const action = ref<Action | null>(null);

function choose(a: Action): void {
    if (!lotRef.value || where.value === null) return;
    remember(where.value);
    justSaved.value = null;
    action.value = a;
    closeRecord();
}

function onSaved(): void {
    justSaved.value = whereLabel.value;
    void load(); // volumes and stock just changed
}
/** A form closed: after a save, come back to the picker with the same place; after a cancel, just close. */
function onPanelClose(): void {
    action.value = null;
    if (justSaved.value !== null) state.open = true;
}

watch(
    () => state.open,
    (open) => {
        if (!open) return;
        readRecent();
        if (options.value === null) void load();
        if (state.target) {
            const v = state.target.vesselId;
            where.value = v ? `v:${v}:${state.target.lotId}` : `l:${state.target.lotId}`;
            state.target = null;
        }
    },
);

// ── `R` opens it from anywhere (not while typing, not over another dialog) ──
function onKey(e: KeyboardEvent): void {
    if (!enabled.value || e.key.toLowerCase() !== 'r' || e.metaKey || e.ctrlKey || e.altKey) return;
    const el = e.target as HTMLElement | null;
    if (el && (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || el.isContentEditable)) return;
    if (document.querySelector('[role="dialog"]')) return;
    e.preventDefault();
    justSaved.value = null;
    state.open = true;
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const vesselId = computed(() => parsed.value?.vesselId ?? null);
</script>

<template>
    <template v-if="enabled">
        <SidePanel :open="state.open" :title="t('Record')" :subtitle="t('What did you do, and where?')" @close="closeRecord">
            <template #header-actions><Kbd keys="R" /></template>
            <div class="flex flex-col gap-5">
                <p v-if="justSaved" class="bg-success/10 px-3 py-2 text-xs text-success" role="status">{{ t('Saved for :where. Record something else, or close.', { where: justSaved }) }}</p>
                <p v-if="loadError" class="text-xs text-destructive" role="alert">{{ t('The cellar could not be loaded.') }} <button type="button" class="underline" @click="load">{{ t('Try again') }}</button></p>

                <div class="flex flex-col gap-1.5">
                    <label for="record-where" class="text-xs font-medium">{{ t('Where') }}</label>
                    <Combobox id="record-where" v-model="where" :options="whereOptions" :placeholder="t('Vessel or lot…')" />
                    <div v-if="recentOptions.length" class="flex flex-wrap items-center gap-1.5 text-xs">
                        <span class="text-muted-foreground">{{ t('Recent') }}</span>
                        <button
                            v-for="o in recentOptions"
                            :key="o.value"
                            type="button"
                            :class="cn('border px-2 py-0.5', where === o.value ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-accent')"
                            @click="where = o.value"
                        >
                            {{ o.label }}
                        </button>
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <span class="text-xs font-medium">{{ t('What did you do?') }}</span>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <button
                            v-for="a in ACTIONS"
                            :key="a.key"
                            type="button"
                            :disabled="!lotRef"
                            class="flex min-h-16 flex-col items-start gap-0.5 border border-border p-3 text-left hover:border-foreground disabled:cursor-not-allowed disabled:opacity-40"
                            @click="choose(a.key)"
                        >
                            <span class="flex items-center gap-1.5 text-sm font-semibold"><component :is="a.icon" class="size-4" /> {{ t(a.label) }}</span>
                            <span class="text-[11px] text-muted-foreground">{{ t(a.hint) }}</span>
                        </button>
                    </div>
                    <p v-if="!lotRef" class="text-[11px] text-muted-foreground">{{ t('Pick a vessel or lot first.') }}</p>
                </div>
            </div>
        </SidePanel>

        <template v-if="lotRef && options">
            <AnalysisPanel :open="action === 'analysis'" :lot="lotRef" :record="null" :vessel-id="vesselId" @saved="onSaved" @close="onPanelClose" />
            <AdditionPanel :open="action === 'addition'" :lot="lotRef" :products="options.products" :record="null" :vessel-id="vesselId" @saved="onSaved" @close="onPanelClose" />
            <TransferPanel :open="action === 'transfer'" :lot="lotRef" :lots="options.lots" :vessels="options.vessels" :vessel-id="vesselId" @saved="onSaved" @close="onPanelClose" />
            <ProcessPanel :open="action === 'process'" :lot="lotRef" :vessel-id="vesselId" @saved="onSaved" @close="onPanelClose" />
            <TastingPanel :open="action === 'tasting'" :lot-id="lotRef.id" :lot-name="lotRef.name" :vessels="lotRef.vessels" :record="null" :vessel-id="vesselId" @saved="onSaved" @close="onPanelClose" />
            <VolumePanel :open="action === 'adjust'" mode="adjust" :lot="lotRef" :vessels="options.vessels" :vessel-id="vesselId" @saved="onSaved" @close="onPanelClose" />
        </template>
    </template>
</template>
