<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { MenuItemRow, VillaRow } from '@/types/accommodation';

/** Add / edit an in-villa menu item; a new one can be added to every active villa at once. */
const props = defineProps<{ open: boolean; item: MenuItemRow | null; villas: VillaRow[]; categories: string[] }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();
const isEdit = computed(() => props.item !== null);

const form = useForm({ villa_id: '', every_villa: false, category: '', name: '', description: '', price: '', vat_included: true, is_active: true });

watch(
    () => [props.open, props.item?.id],
    () => {
        if (!props.open) return;
        const i = props.item;
        form.defaults({
            villa_id: i?.villa_id ?? props.villas.find((v) => v.is_active)?.id ?? '',
            every_villa: false,
            category: i?.category ?? props.categories[0] ?? '',
            name: i?.name ?? '',
            description: i?.description ?? '',
            price: i ? String(i.price.minor / 100) : '',
            vat_included: i?.vat_included ?? true,
            is_active: i?.is_active ?? true,
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const villaOptions = computed(() => props.villas.filter((v) => v.is_active || v.id === props.item?.villa_id).map((v) => ({ value: v.id, label: v.name })));

function submit(): void {
    form
        .transform((d) => ({
            villa_id: d.every_villa ? null : d.villa_id,
            every_villa: !isEdit.value && d.every_villa,
            category: d.category.trim(),
            name: d.name.trim(),
            description: d.description.trim() === '' ? null : d.description.trim(),
            price: Math.round(Number.parseFloat(d.price.replace(',', '.')) * 100),
            vat_included: d.vat_included,
            is_active: d.is_active,
        }))
        .submit(isEdit.value ? 'patch' : 'post', isEdit.value ? `/accommodation/menu/${props.item!.id}` : '/accommodation/menu', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}

const canSubmit = computed(
    () => !form.processing && form.name.trim() !== '' && form.category.trim() !== '' && form.price.trim() !== '' && (form.every_villa || form.villa_id !== ''),
);
</script>

<template>
    <SidePanel :open="open" :title="isEdit ? t('Edit menu item') : t('New menu item')" @close="emit('close')">
        <form id="menu-item-form" class="flex flex-col gap-5" @submit.prevent="submit">
            <SwitchRow v-if="!isEdit" v-model="form.every_villa" :label="t('Add to every villa')" :hint="t('Creates the same item on each active villa’s menu.')" />
            <FormField v-if="!form.every_villa" v-slot="{ id }" :label="t('Villa')" required :error="form.errors.villa_id">
                <Select :id="id" v-model="form.villa_id" :options="villaOptions" />
            </FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Category')" required :hint="t('e.g. Breakfast, Wine, Extras')" :error="form.errors.category">
                <Input :id="id" v-model="form.category" :invalid="invalid" maxlength="255" list="menu-categories" />
                <datalist id="menu-categories">
                    <option v-for="c in categories" :key="c" :value="c" />
                </datalist>
            </FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Name')" required :error="form.errors.name">
                <Input :id="id" v-model="form.name" :invalid="invalid" maxlength="255" />
            </FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Price')" required :error="form.errors.price">
                <Input :id="id" v-model="form.price" type="number" min="0" step="0.01" inputmode="decimal" :invalid="invalid" />
            </FormField>
            <FormField v-slot="{ id }" :label="t('Description')" :error="form.errors.description">
                <Textarea :id="id" v-model="form.description" :rows="3" :placeholder="t('Optional')" />
            </FormField>
            <SwitchRow v-model="form.vat_included" :label="t('Price includes VAT')" />
            <SwitchRow v-model="form.is_active" :label="t('Active')" :hint="t('Inactive items are hidden from guests.')" />
        </form>
        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="menu-item-form" :disabled="!canSubmit">{{ isEdit ? t('Save changes') : t('Add item') }}</Button>
        </template>
    </SidePanel>
</template>
