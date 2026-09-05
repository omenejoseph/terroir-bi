<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import FormSection from '@/components/ui/FormSection.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Separator from '@/components/ui/Separator.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminOption } from '@/types/admin';

/**
 * "New tenant" — port of App\Filament\Resources\Tenants\Schemas\TenantForm's
 * create-only shape (name/slug/plan + the "First admin" section). Status/plan
 * changes after creation happen on the Show page instead of a separate Edit
 * form — Filament's Edit form had only those two fields, so folding them into
 * Show avoids a near-empty panel of their own.
 */
const props = defineProps<{
    open: boolean;
    planOptions: AdminOption[];
    currencyOptions: AdminOption[];
    localeOptions: AdminOption[];
}>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const form = useForm({
    name: '',
    slug: '',
    plan_id: '',
    admin_first_name: '',
    admin_last_name: '',
    admin_email: '',
    admin_password: '',
    currency: 'EUR',
    locale: 'hr',
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;

        form.defaults({
            name: '',
            slug: '',
            plan_id: '',
            admin_first_name: '',
            admin_last_name: '',
            admin_email: '',
            admin_password: '',
            currency: props.currencyOptions[0]?.value ?? 'EUR',
            locale: props.localeOptions[0]?.value ?? 'hr',
        });
        form.reset();
        form.clearErrors();
    },
);

function submit(): void {
    form
        .transform((data) => ({ ...data, plan_id: data.plan_id === '' ? null : data.plan_id }))
        .post(`${ADMIN_BASE}/tenants`, {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}
</script>

<template>
    <SidePanel :open="open" :title="t('New tenant')" @close="emit('close')">
        <form id="tenant-form" class="flex flex-col gap-6" @submit.prevent="submit">
            <FormSection :label="t('Tenant')">
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
                <FormField :label="t('Plan')" :error="form.errors.plan_id">
                    <template #default="{ id }">
                        <Select
                            :id="id"
                            v-model="form.plan_id"
                            :placeholder="t('No plan (unrestricted)')"
                            :options="planOptions"
                        />
                    </template>
                </FormField>
            </FormSection>

            <Separator />

            <FormSection :label="t('First admin')">
                <FieldRow>
                    <FormField :label="t('First name')" required :error="form.errors.admin_first_name">
                        <template #default="{ id, invalid }">
                            <Input :id="id" v-model="form.admin_first_name" :invalid="invalid" />
                        </template>
                    </FormField>
                    <FormField :label="t('Last name')" required :error="form.errors.admin_last_name">
                        <template #default="{ id, invalid }">
                            <Input :id="id" v-model="form.admin_last_name" :invalid="invalid" />
                        </template>
                    </FormField>
                </FieldRow>

                <FormField :label="t('Email')" required :error="form.errors.admin_email">
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.admin_email" type="email" :invalid="invalid" />
                    </template>
                </FormField>

                <FormField :label="t('Password')" required :error="form.errors.admin_password">
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.admin_password" type="password" :invalid="invalid" />
                    </template>
                </FormField>

                <FieldRow>
                    <FormField :label="t('Currency')" required :error="form.errors.currency">
                        <template #default="{ id }">
                            <Select :id="id" v-model="form.currency" :options="currencyOptions" />
                        </template>
                    </FormField>
                    <FormField :label="t('Locale')" required :error="form.errors.locale">
                        <template #default="{ id }">
                            <Select :id="id" v-model="form.locale" :options="localeOptions" />
                        </template>
                    </FormField>
                </FieldRow>
            </FormSection>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="tenant-form" :disabled="form.processing">{{ t('Create tenant') }}</Button>
        </template>
    </SidePanel>
</template>
