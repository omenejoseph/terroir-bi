<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { TranslationOverride } from '@/types/admin';

/**
 * Create/edit — port of App\Filament\Resources\TranslationOverrides\Schemas\TranslationOverrideForm.
 * One component for both, same as CustomerFormPanel.vue: only the title,
 * verb and starting values differ.
 *
 * `prefill` is the catalog's "Override" action: a bundled key/value with no
 * override yet, so this still POSTs a new row (isEdit stays false) but opens
 * pre-filled rather than blank, and locks the key to the one being overridden
 * — same as editing an existing override, where the key can't change either.
 */
const props = defineProps<{
    open: boolean;
    override: TranslationOverride | null;
    prefill?: { locale: string; key: string; value: string } | null;
}>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const isEdit = computed(() => props.override !== null);
const keyLocked = computed(() => props.override !== null || !!props.prefill);

const LOCALE_OPTIONS = [
    { value: 'hr', label: 'Hrvatski' },
    { value: 'en', label: 'English' },
];

const form = useForm({
    locale: 'hr',
    key: '',
    value: '',
});

watch(
    () => [props.open, props.override?.id, props.prefill?.key],
    () => {
        if (!props.open) return;

        const o = props.override;
        const p = props.prefill;

        form.defaults({
            locale: o?.locale ?? p?.locale ?? 'hr',
            key: o?.key ?? p?.key ?? '',
            value: o?.value ?? p?.value ?? '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    if (isEdit.value) {
        form.patch(`${ADMIN_BASE}/translation-overrides/${props.override!.id}`, options);
    } else {
        form.post(`${ADMIN_BASE}/translation-overrides`, options);
    }
}
</script>

<template>
    <SidePanel
        :open="open"
        :title="isEdit ? t('Edit translation override') : prefill ? t('Override this string') : t('New translation override')"
        @close="emit('close')"
    >
        <form id="translation-override-form" class="flex flex-col gap-6" @submit.prevent="submit">
            <FormField :label="t('Locale')" required :error="form.errors.locale">
                <template #default="{ id }">
                    <Select :id="id" v-model="form.locale" :options="LOCALE_OPTIONS" :disabled="keyLocked" />
                </template>
            </FormField>

            <FormField
                :label="t('Key')"
                required
                :hint="
                    keyLocked
                        ? t('The bundled string being overridden — not editable here.')
                        : t('The bundled string to override (e.g. dashboard.welcome, or a JSON source string).')
                "
                :error="form.errors.key"
            >
                <template #default="{ id, invalid }">
                    <Input :id="id" v-model="form.key" :invalid="invalid" :disabled="keyLocked" />
                </template>
            </FormField>

            <FormField :label="t('Value')" required :error="form.errors.value">
                <template #default="{ id, invalid }">
                    <Textarea :id="id" v-model="form.value" :rows="3" :invalid="invalid" />
                </template>
            </FormField>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="translation-override-form" :disabled="form.processing">
                {{ isEdit ? t('Save changes') : t('Create override') }}
            </Button>
        </template>
    </SidePanel>
</template>
