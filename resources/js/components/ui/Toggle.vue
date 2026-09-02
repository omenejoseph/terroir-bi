<script setup lang="ts">
import { cn } from '@/lib/cn';

/** The pill switch from the design's "Available for sale" row (Figma 322:704). */
const props = defineProps<{ modelValue: boolean; id?: string; label?: string }>();

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <button
        :id="id"
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :aria-label="label"
        :class="
            cn(
                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
                props.modelValue ? 'bg-primary' : 'bg-muted-foreground/35',
            )
        "
        @click="emit('update:modelValue', !modelValue)"
    >
        <span
            :class="
                cn(
                    'inline-block size-5 rounded-full bg-card shadow-sm transition-transform',
                    props.modelValue ? 'translate-x-[22px]' : 'translate-x-0.5',
                )
            "
        />
    </button>
</template>
