<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { useTranslations } from '@/composables/useTranslations';
import { parseDecimal, qty } from '@/lib/cellar';
import type { MapVessel } from '@/types/cellar';

/**
 * Record SO₂ for the vessels picked on the map: free and total for each wine
 * in them, saved as one reading per vessel through the SO₂ page's readings
 * route. Empty rows are skipped.
 */
const props = defineProps<{ open: boolean; vessels: MapVessel[] }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const rows = computed(() =>
    props.vessels.flatMap((v) =>
        (v.lots ?? [])
            .filter((l) => l.lot !== null)
            .map((l) => ({ key: `${l.wine_lot_id}:${v.id}`, wineLotId: l.wine_lot_id, vesselId: v.id, vessel: v.name, lot: l.lot?.name ?? '', volume: l.volume, last: l.so2?.free ?? null })),
    ),
);

const values = reactive<Record<string, { free: string; total: string }>>({});
const date = ref(new Date().toISOString().slice(0, 10));
const busy = ref(false);
const error = ref<string | null>(null);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        for (const k of Object.keys(values)) delete values[k];
        for (const r of rows.value) values[r.key] = { free: '', total: '' };
        date.value = new Date().toISOString().slice(0, 10);
        error.value = null;
    },
);

const filled = computed(() =>
    rows.value
        .map((r) => ({ r, free: parseDecimal(values[r.key]?.free), total: parseDecimal(values[r.key]?.total) }))
        .filter((x) => x.free !== null || x.total !== null),
);

function save(): void {
    if (filled.value.length === 0) {
        error.value = t('Enter at least one reading.');

        return;
    }
    busy.value = true;
    router.post(
        '/cellar/so2/readings',
        {
            analyses: filled.value.map(({ r, free, total }) => ({
                wine_lot_id: r.wineLotId,
                vessel_id: r.vesselId,
                date: date.value,
                ...(free !== null ? { free_so2: free } : {}),
                ...(total !== null ? { total_so2: total } : {}),
            })),
        },
        { preserveScroll: true, onSuccess: () => emit('close'), onFinish: () => (busy.value = false) },
    );
}
</script>

<template>
    <SidePanel :open="open" :title="t('Record SO₂')" :subtitle="t(':n vessels', { n: vessels.length })" @close="emit('close')">
        <form class="flex flex-col gap-4" @submit.prevent="save">
            <label class="flex items-center gap-2 text-xs">{{ t('Date') }} <Input v-model="date" type="date" class="w-40" /></label>
            <table class="w-full text-xs">
                <thead class="text-[10px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="py-1 text-left font-medium">{{ t('Vessel') }}</th>
                        <th class="w-20 py-1 text-right font-medium">{{ t('Free') }}</th>
                        <th class="w-20 py-1 text-right font-medium">{{ t('Total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.key" class="border-t border-border">
                        <td class="py-2 pr-2">
                            <p class="font-medium">{{ r.vessel }}</p>
                            <p class="text-muted-foreground">{{ r.lot }} · {{ qty(r.volume) }} L<template v-if="r.last !== null"> · {{ t('last :n', { n: r.last }) }}</template></p>
                        </td>
                        <td class="py-2 pl-1"><Input v-model="values[r.key]!.free" inputmode="decimal" class="text-right" :aria-label="t('Free SO₂ in :vessel', { vessel: r.vessel })" /></td>
                        <td class="py-2 pl-1"><Input v-model="values[r.key]!.total" inputmode="decimal" class="text-right" :aria-label="t('Total SO₂ in :vessel', { vessel: r.vessel })" /></td>
                    </tr>
                </tbody>
            </table>
            <p v-if="error" class="text-xs text-destructive" role="alert">{{ error }}</p>
        </form>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="busy" @click="save">{{ t('Save :n readings', { n: filled.length }) }}</Button>
        </template>
    </SidePanel>
</template>
