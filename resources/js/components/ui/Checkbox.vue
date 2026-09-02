<script setup lang="ts">
import { useId } from 'vue';
import { Check } from 'lucide-vue-next';

/**
 * Figma component `CheckboxRoot` — 35 instances across 3 sections; e.g. the
 * "Inventory-count correction" row on Product Detail (`449:1577`).
 */
defineProps<{ modelValue: boolean; label: string; hint?: string }>();

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();

const id = useId();
</script>

<template>
    <div class="flex items-start gap-2.5">
        <button
            :id="id"
            type="button"
            role="checkbox"
            :aria-checked="modelValue"
            class="mt-0.5 grid size-4 shrink-0 place-items-center rounded border transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
            :class="modelValue ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-card'"
            @click="emit('update:modelValue', !modelValue)"
        >
            <Check v-if="modelValue" class="size-3" :stroke-width="3" />
        </button>
        <label :for="id" class="min-w-0 cursor-pointer select-none">
            <span class="block text-sm leading-5 text-foreground">{{ label }}</span>
            <span v-if="hint" class="mt-0.5 block text-xs text-muted-foreground">{{ hint }}</span>
        </label>
    </div>
</template>
