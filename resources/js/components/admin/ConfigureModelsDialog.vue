<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminAiCapabilityRow } from '@/types/admin';

/** "Configure models" — port of AiSettings::configureAction(). */
const props = defineProps<{
    open: boolean;
    capabilities: Record<string, AdminAiCapabilityRow>;
    modelOptions: Record<string, string[]>;
}>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const form = useForm<Record<string, string>>({});

watch(
    () => props.open,
    (open) => {
        if (!open) return;

        const defaults: Record<string, string> = {};
        for (const [key, row] of Object.entries(props.capabilities)) {
            defaults[key] = row.model;
        }
        form.defaults(defaults);
        form.reset();
        form.clearErrors();
    },
);

function submit(): void {
    form.post(`${ADMIN_BASE}/ai-settings/configure`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Dialog :open="open" :title="t('Configure models')" @close="emit('close')">
        <form id="configure-models-form" class="flex flex-col gap-4" @submit.prevent="submit">
            <FormField
                v-for="(row, key) in capabilities"
                :key="key"
                :label="row.label"
                required
                :hint="t('Pick a suggestion or paste a model id, then use Test to confirm it works.')"
                :error="form.errors[key]"
            >
                <template #default="{ id, invalid }">
                    <Input :id="id" v-model="form[key]" :invalid="invalid" :list="`${key}-suggestions`" />
                    <datalist :id="`${key}-suggestions`">
                        <option v-for="option in modelOptions[key] ?? []" :key="option" :value="option" />
                    </datalist>
                </template>
            </FormField>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="configure-models-form" :disabled="form.processing">{{ t('Save') }}</Button>
        </template>
    </Dialog>
</template>
