<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { PricingTierRow } from '@/types/customers';

/**
 * New / edit a pricing tier: a name, a default rebate and an optional description. A customer's own
 * rebate still overrides the tier's, which the hint says. Per-item tier prices are set from the
 * product, not here.
 */
const props = defineProps<{ open: boolean; tier: PricingTierRow | null }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const isEdit = computed(() => props.tier !== null);

const form = useForm({ name: '', rebate_percent: '', description: '' });

watch(
    () => [props.open, props.tier?.id],
    () => {
        if (!props.open) return;

        form.defaults({
            name: props.tier?.name ?? '',
            rebate_percent: props.tier ? String(Number(props.tier.rebate_percent)) : '',
            description: props.tier?.description ?? '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function submit(): void {
    form
        .transform((data) => ({
            name: data.name.trim(),
            rebate_percent: data.rebate_percent === '' ? 0 : Number(data.rebate_percent),
            description: data.description.trim() === '' ? null : data.description.trim(),
        }))
        .submit(isEdit.value ? 'patch' : 'post', isEdit.value ? `/customers/pricing-tiers/${props.tier!.id}` : '/customers/pricing-tiers', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}
</script>

<template>
    <SidePanel :open="open" :title="isEdit ? t('Edit pricing tier') : t('New pricing tier')" @close="emit('close')">
        <form id="pricing-tier-form" class="flex flex-col gap-5" @submit.prevent="submit">
            <FormField v-slot="{ id, invalid }" :label="t('Name')" required :error="form.errors.name">
                <Input :id="id" v-model="form.name" :invalid="invalid" :placeholder="t('e.g. Distributor')" maxlength="255" />
            </FormField>

            <FormField
                v-slot="{ id, invalid }"
                :label="t('Rebate %')"
                :hint="t('Applied to customers on this tier. A rebate set on the customer overrides it.')"
                :error="form.errors.rebate_percent"
            >
                <Input :id="id" v-model="form.rebate_percent" :invalid="invalid" type="number" step="0.01" min="0" max="100" placeholder="0" />
            </FormField>

            <FormField v-slot="{ id }" :label="t('Description')" :error="form.errors.description">
                <Textarea :id="id" v-model="form.description" :rows="3" :placeholder="t('Optional')" />
            </FormField>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="pricing-tier-form" :disabled="form.processing || form.name.trim() === ''">
                {{ isEdit ? t('Save changes') : t('Add tier') }}
            </Button>
        </template>
    </SidePanel>
</template>
