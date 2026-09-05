<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import ModulesCheckboxGroup from '@/components/admin/ModulesCheckboxGroup.vue';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminOption, AdminTenantMember } from '@/types/admin';

/**
 * Add / edit a tenant member — port of
 * App\Filament\Resources\Tenants\RelationManagers\MembersRelationManager.
 * "Add member" provisions a brand-new user account; editing only ever
 * touches roles + status (the Filament relation manager's EditAction did the
 * same — a member's identity isn't editable there either).
 */
const props = defineProps<{
    open: boolean;
    tenantId: string;
    member: AdminTenantMember | null;
    roleOptions: AdminOption[];
}>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const STATUS_OPTIONS = [
    { value: 'active', label: t('Active') },
    { value: 'suspended', label: t('Suspended') },
];

const isEdit = computed(() => props.member !== null);

const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    roles: [] as string[],
    status: 'active',
});

watch(
    () => [props.open, props.member?.id],
    () => {
        if (!props.open) return;

        const m = props.member;

        form.defaults({
            first_name: '',
            last_name: '',
            email: '',
            password: '',
            roles: m?.roles ?? [],
            status: m?.status ?? 'active',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    if (isEdit.value) {
        form.transform(({ roles, status }) => ({ roles, status })).patch(
            `${ADMIN_BASE}/tenants/${props.tenantId}/members/${props.member!.id}`,
            options,
        );
    } else {
        form.post(`${ADMIN_BASE}/tenants/${props.tenantId}/members`, options);
    }
}
</script>

<template>
    <SidePanel :open="open" :title="isEdit ? t('Edit member') : t('Add a member')" @close="emit('close')">
        <form id="tenant-member-form" class="flex flex-col gap-6" @submit.prevent="submit">
            <template v-if="!isEdit">
                <FormField :label="t('First name')" required :error="form.errors.first_name">
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.first_name" :invalid="invalid" />
                    </template>
                </FormField>
                <FormField :label="t('Last name')" required :error="form.errors.last_name">
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.last_name" :invalid="invalid" />
                    </template>
                </FormField>
                <FormField :label="t('Email')" required :error="form.errors.email">
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.email" type="email" :invalid="invalid" />
                    </template>
                </FormField>
                <FormField
                    :label="t('Password')"
                    required
                    :hint="t('The new user signs in to the tenant app with this.')"
                    :error="form.errors.password"
                >
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.password" type="password" :invalid="invalid" />
                    </template>
                </FormField>
            </template>

            <FormField :label="t('Roles')" :error="form.errors.roles">
                <template #default>
                    <ModulesCheckboxGroup v-model="form.roles" :options="roleOptions" />
                </template>
            </FormField>

            <FormField :label="t('Status')" required :error="form.errors.status">
                <template #default="{ id }">
                    <Select :id="id" v-model="form.status" :options="STATUS_OPTIONS" />
                </template>
            </FormField>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="tenant-member-form" :disabled="form.processing">
                {{ isEdit ? t('Save changes') : t('Add member') }}
            </Button>
        </template>
    </SidePanel>
</template>
