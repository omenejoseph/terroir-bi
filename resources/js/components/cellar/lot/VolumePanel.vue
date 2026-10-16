<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { useTranslations } from '@/composables/useTranslations';
import { parseDecimal, qty } from '@/lib/cellar';
import type { LotRef, Vessel } from '@/types/cellar';
import type { ComboboxOption } from '@/types/ui';

/**
 * The volume card's three actions:
 * - adjust: a loss or gain (±L) with a reason, booked as a Loss/Gain process;
 * - assign: put unallocated wine into a vessel (capped at the vessel's room);
 * - blend-in: pull wine from another lot's vessel into one of this lot's.
 */
const props = defineProps<{ open: boolean; mode: 'adjust' | 'assign' | 'blend-in'; lot: LotRef; vessels: (Pick<Vessel, 'id' | 'name' | 'room' | 'capacity_liters' | 'current_volume'> & { lots?: Vessel['lots'] })[]; vesselId?: string | null }>();
const emit = defineEmits<{ close: []; saved: [] }>();

const { t } = useTranslations();

const REASONS = ['Evaporation', 'Sample', 'Spill', 'Lees', 'Topping', 'Measurement correction'];
const form = useForm({ delta: '', reason: '', vessel_id: null as string | null, volume: '', from_vessel_id: null as string | null, to_vessel_id: '' });

watch(() => [props.open, props.mode], () => {
    if (!props.open) return;
    form.defaults({ delta: '', reason: '', vessel_id: props.vesselId ?? null, volume: '', from_vessel_id: null, to_vessel_id: props.vesselId ?? props.lot.vessels[0]?.vessel_id ?? '' });
    form.reset();
    form.clearErrors();
});

const unallocated = computed(() => Math.max(0, Number(props.lot.current_volume) - props.lot.vessels.reduce((s, v) => s + Number(v.volume), 0)));
const lotVessels = computed(() => props.lot.vessels.map((v) => ({ value: v.vessel_id, label: `${v.vessel_name ?? '—'} · ${qty(v.volume)} L` })));
const freeVessels = computed<ComboboxOption[]>(() =>
    props.vessels.map((v) => ({ value: v.id, label: v.name, description: t(':free L free', { free: qty(Number(v.capacity_liters) - Number(v.current_volume)) }), keywords: [v.room ?? ''] })),
);
const otherWine = computed<ComboboxOption[]>(() =>
    props.vessels.flatMap((v) =>
        (v.lots ?? [])
            .filter((l) => l.wine_lot_id !== props.lot.id && l.lot)
            .map((l) => ({ value: v.id, label: `${v.name} — ${l.lot!.name}`, description: `${qty(l.volume)} L`, keywords: [l.lot!.lot_number] })),
    ),
);
const title = computed(() => ({ adjust: t('Adjust volume'), assign: t('Assign vessel'), 'blend-in': t('Blend in') })[props.mode]);
const err = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

function submit(): void {
    const base = `/cellar/lots/${props.lot.id}`;
    const opts = { preserveScroll: true, onSuccess: () => { emit('saved'); emit('close'); } };
    if (props.mode === 'adjust') {
        form.transform((d) => ({ delta: parseDecimal(d.delta), reason: d.reason || null, vessel_id: d.vessel_id || null }));
        form.post(`${base}/adjust-volume`, opts);
    } else if (props.mode === 'assign') {
        form.transform((d) => ({ vessel_id: d.vessel_id, volume: parseDecimal(d.volume) }));
        form.post(`${base}/vessels`, opts);
    } else {
        form.transform((d) => ({ from_vessel_id: d.from_vessel_id, to_vessel_id: d.to_vessel_id, volume: parseDecimal(d.volume) }));
        form.post(`${base}/blend-in`, opts);
    }
}
</script>

<template>
    <SidePanel :open="open" :title="title" :subtitle="lot.name" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <template v-if="mode === 'adjust'">
                <FormField v-slot="{ id, invalid }" :label="t('Change (L) — negative for a loss')" required :error="err('delta')"><Input :id="id" :invalid="invalid" v-model="form.delta" inputmode="decimal" placeholder="-5" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Reason')" :error="err('reason')">
                    <Input :id="id" :invalid="invalid" v-model="form.reason" list="volume-reasons" />
                    <datalist id="volume-reasons"><option v-for="r in REASONS" :key="r" :value="t(r)" /></datalist>
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Vessel')" :hint="t('Without one, the change is spread over the lot’s vessels by volume.')" :error="err('vessel_id')">
                    <Combobox :id="id" :invalid="invalid" v-model="form.vessel_id" :options="[{ value: '', label: t('Spread over all vessels') }, ...lotVessels]" :placeholder="t('Spread over all vessels')" :empty-text="t('No vessel matches.')" />
                </FormField>
            </template>
            <template v-else-if="mode === 'assign'">
                <p class="bg-muted px-3 py-2 text-xs">{{ t(':n L of this lot is not in a vessel.', { n: qty(unallocated) }) }}</p>
                <FormField v-slot="{ id, invalid }" :label="t('Vessel')" required :error="err('vessel_id')"><Combobox :id="id" :invalid="invalid" v-model="form.vessel_id" :options="freeVessels" :placeholder="t('Select vessel…')" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Litres')" required :error="err('volume')"><Input :id="id" :invalid="invalid" v-model="form.volume" inputmode="decimal" /></FormField>
            </template>
            <template v-else>
                <FormField v-slot="{ id, invalid }" :label="t('From (another lot’s vessel)')" required :error="err('from_vessel_id')">
                    <Combobox :id="id" :invalid="invalid" v-model="form.from_vessel_id" :options="otherWine" :placeholder="t('Select vessel…')" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Into this lot’s vessel')" required :error="err('to_vessel_id')"><Combobox :id="id" :invalid="invalid" v-model="form.to_vessel_id" :options="lotVessels" :empty-text="t('No vessel matches.')" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Litres')" required :error="err('volume')"><Input :id="id" :invalid="invalid" v-model="form.volume" inputmode="decimal" /></FormField>
            </template>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </SidePanel>
</template>
