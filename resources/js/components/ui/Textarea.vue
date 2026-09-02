<script setup lang="ts">
import { cn } from '@/lib/cn';

const props = defineProps<{
    modelValue: string | null;
    id?: string;
    invalid?: boolean;
    placeholder?: string;
    rows?: number;
    class?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <textarea
        :id="id"
        :value="modelValue ?? ''"
        :rows="rows ?? 3"
        :placeholder="placeholder"
        :aria-invalid="invalid || undefined"
        :class="
            cn(
                'w-full resize-y rounded-lg border bg-card px-3 py-2 text-sm transition-colors',
                'placeholder:text-muted-foreground',
                'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
                invalid ? 'border-destructive focus-visible:ring-destructive' : 'border-input',
                props.class,
            )
        "
        @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
    />
</template>
