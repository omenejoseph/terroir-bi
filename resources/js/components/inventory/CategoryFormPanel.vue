<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { ProductCategory } from '@/types/inventory';

/**
 * New / edit a category or subcategory. One panel for all three: a new top-level category picks its
 * type, a new subcategory is opened with its `parent` (and takes that type), an edit changes the name
 * and whether the category carries vintages — the type is fixed once created, since items are filed
 * under a type.
 */
const props = defineProps<{
    open: boolean;
    /** The category being edited; null when creating. */
    category: ProductCategory | null;
    /** When creating: the top-level category the new one goes under; null for a top-level category. */
    parent: ProductCategory | null;
    /** The type a new top-level category starts with (the section it was added from). */
    defaultType?: string;
}>();
const emit = defineEmits<{ close: [] }>();
const { t } = useTranslations();

const isEdit = computed(() => props.category !== null);

const form = useForm({ name: '', type: 'FINISHED', parent_id: '' as string, has_vintage: false });

const TYPES = [
    { value: 'FINISHED', label: t('Finished') },
    { value: 'SEMI_FINISHED', label: t('Semi-Finished') },
    { value: 'RAW_MATERIAL', label: t('Raw Materials') },
];

/** Repopulate fresh on every open — a cancelled edit must not linger into the next one. */
watch(
    () => [props.open, props.category?.id, props.parent?.id],
    () => {
        if (!props.open) return;

        form.defaults({
            name: props.category?.name ?? '',
            type: props.category?.type ?? props.parent?.type ?? props.defaultType ?? 'FINISHED',
            parent_id: props.parent?.id ?? '',
            // A subcategory starts out like its parent.
            has_vintage: props.category?.has_vintage ?? props.parent?.has_vintage ?? false,
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    if (isEdit.value) {
        form.transform((data) => ({ name: data.name, has_vintage: data.has_vintage })).patch(`/inventory/categories/${props.category!.id}`, options);

        return;
    }

    form
        .transform((data) => ({
            name: data.name,
            has_vintage: data.has_vintage,
            ...(data.parent_id === '' ? { type: data.type } : { parent_id: data.parent_id }),
        }))
        .post('/inventory/categories', options);
}
</script>

<template>
    <SidePanel
        :open="open"
        :title="isEdit ? t('Edit category') : parent ? t('New subcategory') : t('New category')"
        @close="emit('close')"
    >
        <form id="category-form" class="flex flex-col gap-4" @submit.prevent="submit">
            <p v-if="parent" class="text-xs text-muted-foreground">
                {{ t('Goes under :category.', { category: parent.name }) }}
            </p>

            <FormField v-slot="{ id, invalid }" :label="t('Name')" required :error="form.errors.name">
                <Input :id="id" v-model="form.name" :invalid="invalid" :placeholder="parent ? t('e.g. Red') : t('e.g. Wine')" />
            </FormField>

            <FormField v-if="!isEdit && !parent" v-slot="{ id, invalid }" :label="t('Type')" required :error="form.errors.type">
                <Select :id="id" v-model="form.type" :invalid="invalid" :options="TYPES" />
            </FormField>

            <SwitchRow
                v-model="form.has_vintage"
                :label="t('Carries vintages')"
                :hint="t('Products here have a vintage, and each new vintage can follow the previous one. Only the latest shows on the list, with the older ones under it.')"
            />
            <p v-if="form.errors.has_vintage" class="text-xs text-destructive">{{ form.errors.has_vintage }}</p>
            <p v-if="isEdit && category?.has_vintage && !form.has_vintage" class="text-xs text-muted-foreground">
                {{ t('Turning this off unlinks the vintages in it: every product stands on its own row again.') }}
            </p>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <button
                type="submit"
                form="category-form"
                class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50"
                :disabled="form.processing || form.name.trim() === ''"
            >
                {{ isEdit ? (form.processing ? t('Saving…') : t('Save changes')) : form.processing ? t('Creating…') : t('Create category') }}
            </button>
        </template>
    </SidePanel>
</template>
