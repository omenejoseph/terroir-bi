<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { LOT_STATUS_LABELS, WINE_TYPE_OPTIONS, parseDecimal, qty } from '@/lib/cellar';
import type { LotFormValue, Vessel } from '@/types/cellar';
import type { ComboboxOption } from '@/types/ui';

/**
 * New / edit wine lot. Grapes are rows (variety, share, weight and — for
 * finance — price per kg); the lot's grape label is derived from them. A new
 * lot can start in a vessel. Editing keeps each existing grape row's harvest
 * link and percentage (the server matches rows by variety).
 */
const props = defineProps<{ open: boolean; lot: LotFormValue | null; vessels: Vessel[] }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();
const { can } = useAuth();
const finance = computed(() => can('finance.view'));

interface GrapeRow {
    grape_variety: string;
    percentage: string;
    weight_kg: string;
    price: string;
}

const form = useForm({
    name: '',
    vintage: String(new Date().getFullYear()),
    vineyard: '',
    wine_type: '',
    status: 'FERMENTING',
    initial_volume: '',
    vessel_id: null as string | null,
    harvest_weight_kg: '',
    price: '',
    notes: '',
    grapes: [{ grape_variety: '', percentage: '', weight_kg: '', price: '' }] as GrapeRow[],
});

const minorToMajor = (m: { minor: number } | null | undefined) => (m ? String(m.minor / 100) : '');

watch(
    () => [props.open, props.lot?.id],
    () => {
        if (!props.open) return;
        const l = props.lot;
        form.defaults({
            name: l?.name ?? '',
            vintage: l?.vintage ?? String(new Date().getFullYear()),
            vineyard: l?.vineyard ?? '',
            wine_type: l?.wine_type ?? '',
            status: l?.status ?? 'FERMENTING',
            initial_volume: '',
            vessel_id: null,
            harvest_weight_kg: l?.harvest_weight_kg ?? '',
            price: minorToMajor(l?.grape_price_per_kg),
            notes: l?.notes ?? '',
            grapes: l?.grapes?.length
                ? l.grapes.map((g) => ({ grape_variety: g.grape_variety, percentage: g.percentage ?? '', weight_kg: g.weight_kg ?? '', price: minorToMajor(g.price_per_kg) }))
                : [{ grape_variety: l?.grape_variety ?? '', percentage: '', weight_kg: '', price: '' }],
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const vesselOptions = computed<ComboboxOption[]>(() =>
    props.vessels.map((v) => ({
        value: v.id,
        label: v.name,
        description: t(':free L free of :cap L', { free: qty(Number(v.capacity_liters) - Number(v.current_volume)), cap: qty(v.capacity_liters) }),
        keywords: [v.room ?? ''],
    })),
);

const toMinor = (s: string) => {
    const n = parseDecimal(s);

    return n === null ? null : Math.round(n * 100);
};

function submit(): void {
    const grapes = form.grapes.filter((g) => g.grape_variety.trim() !== '');
    form.transform((d) => ({
        name: d.name,
        vintage: d.vintage,
        vineyard: d.vineyard || null,
        wine_type: d.wine_type || null,
        notes: d.notes || null,
        harvest_weight_kg: parseDecimal(d.harvest_weight_kg),
        grape_variety: grapes.map((g) => g.grape_variety.trim()).join(' / ') || d.name,
        grapes: grapes.map((g) => ({
            grape_variety: g.grape_variety.trim(),
            percentage: parseDecimal(g.percentage),
            weight_kg: parseDecimal(g.weight_kg),
            ...(finance.value ? { price_per_kg: toMinor(g.price) } : {}),
        })),
        ...(finance.value ? { grape_price_per_kg: toMinor(d.price) } : {}),
        ...(props.lot ? { status: d.status } : { initial_volume: parseDecimal(d.initial_volume), vessel_id: d.vessel_id }),
    }));

    if (props.lot) form.patch(`/cellar/lots/${props.lot.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
    else form.post('/cellar/lots', { onSuccess: () => emit('close') });
}

/** Server errors are keyed by the request's fields, which the transform renames. */
const err = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

const STATUS_OPTIONS = computed(() => Object.entries(LOT_STATUS_LABELS).map(([value, label]) => ({ value, label: t(label) })));
</script>

<template>
    <SidePanel :open="open" :title="lot ? t('Edit wine lot') : t('New wine lot')" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <FormField v-slot="{ id, invalid }" :label="t('Name')" required :error="form.errors.name"><Input :id="id" :invalid="invalid" v-model="form.name" /></FormField>
            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Vintage')" required :error="form.errors.vintage"><Input :id="id" :invalid="invalid" v-model="form.vintage" inputmode="numeric" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Wine type')" :error="form.errors.wine_type">
                    <Select :id="id" :invalid="invalid" v-model="form.wine_type" :placeholder="t('Select…')" :options="WINE_TYPE_OPTIONS.map((o) => ({ ...o, label: t(o.label) }))" />
                </FormField>
            </FieldRow>

            <div class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium">{{ t('Grapes') }}</span>
                    <button type="button" class="inline-flex items-center gap-1 text-xs underline-offset-2 hover:underline" @click="form.grapes.push({ grape_variety: '', percentage: '', weight_kg: '', price: '' })">
                        <Plus class="size-3" /> {{ t('Add grape') }}
                    </button>
                </div>
                <div v-for="(g, i) in form.grapes" :key="i" class="grid grid-cols-[1fr_60px_70px_auto] items-end gap-2" :class="finance && 'grid-cols-[1fr_60px_70px_70px_auto]'">
                    <FormField v-slot="{ id, invalid }" :label="i === 0 ? t('Variety') : ''" :error="err(`grapes.${i}.grape_variety`)"><Input :id="id" :invalid="invalid" v-model="g.grape_variety" /></FormField>
                    <FormField v-slot="{ id, invalid }" :label="i === 0 ? '%' : ''"><Input :id="id" :invalid="invalid" v-model="g.percentage" inputmode="decimal" /></FormField>
                    <FormField v-slot="{ id, invalid }" :label="i === 0 ? 'kg' : ''"><Input :id="id" :invalid="invalid" v-model="g.weight_kg" inputmode="decimal" /></FormField>
                    <FormField v-slot="{ id, invalid }" v-if="finance" :label="i === 0 ? '€/kg' : ''"><Input :id="id" :invalid="invalid" v-model="g.price" inputmode="decimal" /></FormField>
                    <button type="button" class="mb-2 p-1 text-muted-foreground hover:text-destructive disabled:opacity-30" :disabled="form.grapes.length === 1" :aria-label="t('Remove')" @click="form.grapes.splice(i, 1)">
                        <Trash2 class="size-4" />
                    </button>
                </div>
                <p v-if="err('grape_variety')" class="text-xs text-destructive">{{ err('grape_variety') }}</p>
            </div>

            <FormField v-slot="{ id, invalid }" :label="t('Vineyard')" :error="form.errors.vineyard"><Input :id="id" :invalid="invalid" v-model="form.vineyard" /></FormField>

            <template v-if="!lot">
                <FieldRow>
                    <FormField v-slot="{ id, invalid }" :label="t('Initial volume (L)')" required :error="err('initial_volume')"><Input :id="id" :invalid="invalid" v-model="form.initial_volume" inputmode="decimal" /></FormField>
                    <FormField v-slot="{ id, invalid }" :label="t('Harvest weight (kg)')" :error="form.errors.harvest_weight_kg"><Input :id="id" :invalid="invalid" v-model="form.harvest_weight_kg" inputmode="decimal" /></FormField>
                </FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Starting vessel (optional)')" :error="form.errors.vessel_id">
                    <Combobox :id="id" :invalid="invalid" v-model="form.vessel_id" :options="vesselOptions" :placeholder="t('Select vessel…')" clearable />
                </FormField>
            </template>
            <FieldRow v-else>
                <FormField v-slot="{ id, invalid }" :label="t('Status')" :error="form.errors.status"><Select :id="id" :invalid="invalid" v-model="form.status" :options="STATUS_OPTIONS" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Harvest weight (kg)')" :error="form.errors.harvest_weight_kg"><Input :id="id" :invalid="invalid" v-model="form.harvest_weight_kg" inputmode="decimal" /></FormField>
            </FieldRow>

            <FormField v-slot="{ id, invalid }" v-if="finance" :label="t('Grape price (€/kg, whole lot)')" :hint="t('Used when grapes have no price of their own.')" :error="err('grape_price_per_kg')">
                <Input :id="id" :invalid="invalid" v-model="form.price" inputmode="decimal" />
            </FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Notes')" :error="form.errors.notes"><Textarea :id="id" :invalid="invalid" v-model="form.notes" :rows="3" /></FormField>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ lot ? t('Save') : t('Create lot') }}</Button>
        </template>
    </SidePanel>
</template>
