<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { parseDecimal, qty } from '@/lib/cellar';
import type { LotOption, LotRef, Vessel } from '@/types/cellar';
import type { ComboboxOption } from '@/types/ui';

/**
 * Move wine: rack within this lot (vessel → vessel), blend part of it into
 * another lot, or split it off into another lot. The receiving lot picks up
 * grape share and cost by volume (server side).
 */
const props = defineProps<{ open: boolean; lot: LotRef; lots: LotOption[]; vessels: Pick<Vessel, 'id' | 'name' | 'room' | 'capacity_liters' | 'current_volume'>[]; vesselId?: string | null }>();
const emit = defineEmits<{ close: []; saved: [] }>();

const { t } = useTranslations();

const form = useForm({ type: 'RACK', to_lot_id: null as string | null, from_vessel_id: '', to_vessel_id: null as string | null, volume_liters: '', note: '' });

watch(() => props.open, (open) => {
    if (!open) return;
    form.defaults({ type: 'RACK', to_lot_id: null, from_vessel_id: props.vesselId ?? props.lot.vessels[0]?.vessel_id ?? '', to_vessel_id: null, volume_liters: '', note: '' });
    form.reset();
    form.clearErrors();
});

const fromOptions = computed(() => props.lot.vessels.map((v) => ({ value: v.vessel_id, label: `${v.vessel_name ?? '—'} · ${qty(v.volume)} L` })));
const toVesselOptions = computed<ComboboxOption[]>(() =>
    props.vessels
        .filter((v) => v.id !== form.from_vessel_id)
        .map((v) => ({ value: v.id, label: v.name, description: t(':free L free', { free: qty(Number(v.capacity_liters) - Number(v.current_volume)) }), keywords: [v.room ?? ''] })),
);
const lotOptions = computed<ComboboxOption[]>(() =>
    props.lots.filter((l) => l.id !== props.lot.id).map((l) => ({ value: l.id, label: [l.name, l.vintage].filter(Boolean).join(' '), description: `${qty(l.current_volume)} L`, keywords: [l.lot_number] })),
);
const available = computed(() => props.lot.vessels.find((v) => v.vessel_id === form.from_vessel_id)?.volume ?? props.lot.current_volume);
const err = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

function submit(): void {
    form.transform((d) => ({
        type: d.type,
        to_lot_id: d.type === 'RACK' ? props.lot.id : d.to_lot_id,
        from_vessel_id: d.from_vessel_id || null,
        to_vessel_id: d.to_vessel_id,
        volume_liters: parseDecimal(d.volume_liters),
        note: d.note || null,
    }));
    form.post(`/cellar/lots/${props.lot.id}/transfers`, { preserveScroll: true, onSuccess: () => { emit('saved'); emit('close'); } });
}
</script>

<template>
    <SidePanel :open="open" :title="t('Move wine')" :subtitle="lot.name" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <Tabs
                :items="[
                    { label: t('Rack'), value: 'RACK' },
                    { label: t('Blend into lot'), value: 'BLEND' },
                    { label: t('Split into lot'), value: 'SPLIT' },
                ]"
                :current="form.type"
                full
                @select="form.type = $event"
            />
            <FormField v-slot="{ id, invalid }" v-if="form.type !== 'RACK'" :label="t('Into lot')" required :error="err('to_lot_id')">
                <Combobox :id="id" :invalid="invalid" v-model="form.to_lot_id" :options="lotOptions" :placeholder="t('Select lot…')" />
            </FormField>
            <FormField v-slot="{ id, invalid }" :label="t('From vessel')" :error="err('from_vessel_id')"><Select :id="id" :invalid="invalid" v-model="form.from_vessel_id" :options="fromOptions" :placeholder="t('Select…')" /></FormField>
            <FormField v-slot="{ id, invalid }" :label="t('To vessel')" :required="form.type === 'RACK'" :error="err('to_vessel_id')">
                <Combobox :id="id" :invalid="invalid" v-model="form.to_vessel_id" :options="toVesselOptions" :placeholder="t('Select vessel…')" clearable />
            </FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Litres')" required :hint="t(':n L available', { n: qty(available) })" :error="err('volume_liters')">
                <Input :id="id" :invalid="invalid" v-model="form.volume_liters" inputmode="decimal" />
            </FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Note')" :error="form.errors.note"><Textarea :id="id" :invalid="invalid" v-model="form.note" :rows="2" /></FormField>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ t('Move wine') }}</Button>
        </template>
    </SidePanel>
</template>
