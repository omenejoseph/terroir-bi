<script setup lang="ts">
import Switch from '@/components/ui/Switch.vue';

/**
 * Figma component `SwitchRow` — 16 instances across 4 sections. A labelled
 * switch with an explanatory line, the switch pinned to the right; e.g.
 * "Available for sale / Shows in the customer order form." in `322:704`.
 */
defineProps<{ modelValue: boolean; label: string; hint?: string }>();

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <div class="flex items-start justify-between gap-4">
        <span class="min-w-0">
            <span class="block text-sm leading-5 font-medium text-foreground">{{ label }}</span>
            <span v-if="hint" class="mt-0.5 block text-xs text-muted-foreground">{{ hint }}</span>
        </span>
        <Switch :model-value="modelValue" :label="label" @update:model-value="emit('update:modelValue', $event)" />
    </div>
</template>
