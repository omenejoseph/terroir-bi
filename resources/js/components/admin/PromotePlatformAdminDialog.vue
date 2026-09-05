<script setup lang="ts">
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Dialog from '@/components/ui/Dialog.vue';
import FormField from '@/components/ui/FormField.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { ComboboxOption } from '@/types/ui';

/**
 * "Promote existing user" — port of the modal Action on
 * App\Filament\Resources\PlatformAdmins\Pages\ListPlatformAdmins.
 */
const props = defineProps<{ open: boolean; candidates: ComboboxOption[] }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const userId = ref<string | null>(null);
const form = useForm({ user_id: '' });

watch(
    () => props.open,
    (open) => {
        if (!open) return;

        userId.value = null;
        form.reset();
        form.clearErrors();
    },
);

function submit(): void {
    form.transform((data) => ({ ...data, user_id: userId.value ?? '' })).post(`${ADMIN_BASE}/platform-admins/promote`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Dialog :open="open" :title="t('Promote existing user')" @close="emit('close')">
        <p class="mb-4 text-sm text-muted-foreground">{{ t('Grant an existing user back-office access.') }}</p>

        <form id="promote-admin-form" @submit.prevent="submit">
            <FormField :label="t('User')" required :error="form.errors.user_id">
                <template #default="{ id }">
                    <Combobox
                        :id="id"
                        v-model="userId"
                        :placeholder="t('Search by name or email…')"
                        :empty-text="t('No matching users.')"
                        :options="candidates"
                    />
                </template>
            </FormField>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="promote-admin-form" :disabled="form.processing || userId === null">
                {{ t('Grant access') }}
            </Button>
        </template>
    </Dialog>
</template>
