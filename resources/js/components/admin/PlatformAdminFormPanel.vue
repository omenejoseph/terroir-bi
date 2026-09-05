<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';

/**
 * "New platform admin" — port of App\Filament\Resources\PlatformAdmins\PlatformAdminResource::form().
 * Create only; there is no edit form here (matches the Filament resource).
 */
const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    password: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;

        form.reset();
        form.clearErrors();
    },
);

function submit(): void {
    form.post(`${ADMIN_BASE}/platform-admins`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <SidePanel :open="open" :title="t('New platform admin')" @close="emit('close')">
        <form id="platform-admin-form" class="flex flex-col gap-6" @submit.prevent="submit">
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
                :hint="t('They sign in to /admin with this.')"
                :error="form.errors.password"
            >
                <template #default="{ id, invalid }">
                    <Input :id="id" v-model="form.password" type="password" :invalid="invalid" />
                </template>
            </FormField>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="platform-admin-form" :disabled="form.processing">
                {{ t('Create admin') }}
            </Button>
        </template>
    </SidePanel>
</template>
