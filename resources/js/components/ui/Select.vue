<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next';

import { cn } from '@/lib/cn';

/** Native select styled to match Input, with the design's chevron affordance. */
const props = defineProps<{
    modelValue: string | number | null;
    id?: string;
    invalid?: boolean;
    placeholder?: string;
    options: { value: string; label: string }[];
    class?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div class="relative">
        <select
            :id="id"
            :value="modelValue ?? ''"
            :aria-invalid="invalid || undefined"
            :class="
                cn(
                    'h-9 w-full appearance-none rounded-lg border bg-card pr-9 pl-3 text-sm transition-colors',
                    'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    modelValue === null || modelValue === '' ? 'text-muted-foreground' : 'text-foreground',
                    invalid ? 'border-destructive focus-visible:ring-destructive' : 'border-input',
                    props.class,
                )
            "
            @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
        >
            <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
        <ChevronDown
            class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground"
            :stroke-width="1.5"
        />
    </div>
</template>
