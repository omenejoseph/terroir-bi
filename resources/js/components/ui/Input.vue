<script setup lang="ts">
import { cn } from '@/lib/cn';

const props = defineProps<{
    /** v-model value. */
    modelValue?: string | number | null;
    id?: string;
    type?: string;
    /** Renders the error ring and links the message for assistive tech. */
    invalid?: boolean;
    class?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

function onInput(event: Event): void {
    emit('update:modelValue', (event.target as HTMLInputElement).value);
}
</script>

<template>
    <input
        :id="id"
        :type="type ?? 'text'"
        :value="modelValue ?? ''"
        :aria-invalid="invalid || undefined"
        :class="
            cn(
                'flex h-10 w-full rounded-lg border bg-card px-3 py-2 text-sm transition-colors',
                'placeholder:text-muted-foreground',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                'disabled:cursor-not-allowed disabled:opacity-50',
                invalid ? 'border-destructive focus-visible:ring-destructive' : 'border-input',
                props.class,
            )
        "
        @input="onInput"
    />
</template>
