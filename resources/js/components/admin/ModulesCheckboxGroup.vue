<script setup lang="ts">
import Checkbox from '@/components/ui/Checkbox.vue';
import type { AdminOption } from '@/types/admin';

/**
 * Port of Filament's `CheckboxList::make('modules')` (2 columns) — there's no
 * CheckboxList primitive in resources/js/components/ui, so this composes the
 * existing Checkbox over a 2-column grid instead.
 */
const props = defineProps<{ modelValue: string[]; options: AdminOption[] }>();
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

function toggle(value: string, checked: boolean): void {
    emit(
        'update:modelValue',
        checked ? [...props.modelValue, value] : props.modelValue.filter((m) => m !== value),
    );
}
</script>

<template>
    <div class="grid grid-cols-2 gap-x-4 gap-y-2">
        <Checkbox
            v-for="option in options"
            :key="option.value"
            :model-value="modelValue.includes(option.value)"
            :label="option.label"
            @update:model-value="toggle(option.value, $event)"
        />
    </div>
</template>
