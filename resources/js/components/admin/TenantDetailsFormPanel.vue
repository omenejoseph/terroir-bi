<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import FormSection from '@/components/ui/FormSection.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminOption, AdminTenant } from '@/types/admin';

/**
 * Editing name/slug/locale after creation — a separate panel rather than
 * folding these into the inline status/plan selects on Show.vue, since those
 * two are Filament's original edit-form shape (see TenantFormPanel.vue's
 * docblock) and three more fields would crowd that grid.
 */
const props = defineProps<{
    open: boolean;
    tenant: AdminTenant;
    localeOptions: AdminOption[];
}>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const form = useForm({
    name: '',
    slug: '',
    default_locale: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;

        form.defaults({
            name: props.tenant.name,
            slug: props.tenant.slug,
            default_locale: props.tenant.default_locale,
        });
        form.reset();
        form.clearErrors();
    },
);

function submit(): void {
    form.patch(`${ADMIN_BASE}/tenants/${props.tenant.id}/details`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <SidePanel :open="open" :title="t('Edit tenant details')" @close="emit('close')">
        <form id="tenant-details-form" class="flex flex-col gap-6" @submit.prevent="submit">
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
                <FormField :label="t('Locale')" required :error="form.errors.default_locale">
                    <template #default="{ id }">
                        <Select :id="id" v-model="form.default_locale" :options="localeOptions" />
                    </template>
                </FormField>
            </FormSection>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="tenant-details-form" :disabled="form.processing">{{ t('Save') }}</Button>
        </template>
    </SidePanel>
</template>
