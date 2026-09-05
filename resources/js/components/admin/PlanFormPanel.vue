<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import ModulesCheckboxGroup from '@/components/admin/ModulesCheckboxGroup.vue';
import Button from '@/components/ui/Button.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import FormSection from '@/components/ui/FormSection.vue';
import Input from '@/components/ui/Input.vue';
import Separator from '@/components/ui/Separator.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminOption, AdminPlan } from '@/types/admin';

/**
 * Create/edit — port of App\Filament\Resources\Plans\Schemas\PlanForm. The
 * `price_minor` field name matches the model attribute even though it shows a
 * major-unit string (e.g. "15.00"); the server converts it, same as Filament's
 * dehydrateStateUsing() did.
 */
const props = defineProps<{ open: boolean; plan: AdminPlan | null; moduleOptions: AdminOption[] }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const isEdit = computed(() => props.plan !== null);

const form = useForm({
    name: '',
    slug: '',
    price_minor: '' as string,
    currency: 'EUR',
    modules: [] as string[],
    stripe_price_id: '',
    trial_days: '0',
    grace_full_days: '0',
    grace_readonly_days: '0',
    interval: 'month',
    is_active: true,
    is_public: true,
});

watch(
    () => [props.open, props.plan?.id],
    () => {
        if (!props.open) return;

        const p = props.plan;

        form.defaults({
            name: p?.name ?? '',
            slug: p?.slug ?? '',
            price_minor: p?.price_major ?? '',
            currency: p?.currency ?? 'EUR',
            modules: p?.modules ?? [],
            stripe_price_id: p?.stripe_price_id ?? '',
            trial_days: String(p?.trial_days ?? 0),
            grace_full_days: String(p?.grace_full_days ?? 0),
            grace_readonly_days: String(p?.grace_readonly_days ?? 0),
            interval: p?.interval ?? 'month',
            is_active: p?.is_active ?? true,
            is_public: p?.is_public ?? true,
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    if (isEdit.value) {
        form.patch(`${ADMIN_BASE}/plans/${props.plan!.id}`, options);
    } else {
        form.post(`${ADMIN_BASE}/plans`, options);
    }
}
</script>

<template>
    <SidePanel :open="open" :title="isEdit ? t('Edit plan') : t('New plan')" @close="emit('close')">
        <form id="plan-form" class="flex flex-col gap-6" @submit.prevent="submit">
            <FormSection :label="t('Identity')">
                <FormField :label="t('Name')" required :error="form.errors.name">
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.name" :invalid="invalid" />
                    </template>
                </FormField>
                <FormField :label="t('Slug')" required :error="form.errors.slug">
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.slug" :invalid="invalid" />
                    </template>
                </FormField>
            </FormSection>

            <Separator />

            <FormSection :label="t('Billing')">
                <FieldRow>
                    <FormField
                        :label="t('Price')"
                        :hint="t('Amount billed per interval, in major units (e.g. 15.00). Leave blank for a free plan.')"
                        :error="form.errors.price_minor"
                    >
                        <template #default="{ id, invalid }">
                            <Input :id="id" v-model="form.price_minor" type="number" step="0.01" min="0" :invalid="invalid" />
                        </template>
                    </FormField>
                    <FormField :label="t('Currency')" required :error="form.errors.currency">
                        <template #default="{ id, invalid }">
                            <Input :id="id" v-model="form.currency" :invalid="invalid" maxlength="3" />
                        </template>
                    </FormField>
                </FieldRow>

                <FormField :label="t('Stripe price ID')" :hint="t('Leave blank for a free/internal plan.')" :error="form.errors.stripe_price_id">
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.stripe_price_id" :invalid="invalid" />
                    </template>
                </FormField>

                <FieldRow>
                    <FormField :label="t('Trial (days)')" required :error="form.errors.trial_days">
                        <template #default="{ id, invalid }">
                            <Input :id="id" v-model="form.trial_days" type="number" min="0" :invalid="invalid" />
                        </template>
                    </FormField>
                    <FormField :label="t('Interval')" required :error="form.errors.interval">
                        <template #default="{ id, invalid }">
                            <Input :id="id" v-model="form.interval" :invalid="invalid" />
                        </template>
                    </FormField>
                </FieldRow>

                <FieldRow>
                    <FormField :label="t('Full-access grace (days)')" required :error="form.errors.grace_full_days">
                        <template #default="{ id, invalid }">
                            <Input :id="id" v-model="form.grace_full_days" type="number" min="0" :invalid="invalid" />
                        </template>
                    </FormField>
                    <FormField :label="t('Read-only grace (days)')" required :error="form.errors.grace_readonly_days">
                        <template #default="{ id, invalid }">
                            <Input :id="id" v-model="form.grace_readonly_days" type="number" min="0" :invalid="invalid" />
                        </template>
                    </FormField>
                </FieldRow>
            </FormSection>

            <Separator />

            <FormSection :label="t('Modules')">
                <p class="text-xs text-muted-foreground">{{ t('Tenants on this plan see only the selected modules.') }}</p>
                <ModulesCheckboxGroup v-model="form.modules" :options="moduleOptions" />
            </FormSection>

            <Separator />

            <FormSection :label="t('Visibility')">
                <SwitchRow v-model="form.is_active" :label="t('Active')" />
                <SwitchRow v-model="form.is_public" :label="t('Public')" />
            </FormSection>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="plan-form" :disabled="form.processing">
                {{ isEdit ? t('Save changes') : t('Create plan') }}
            </Button>
        </template>
    </SidePanel>
</template>
