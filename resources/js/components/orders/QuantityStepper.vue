<script setup lang="ts">
import { useTranslations } from '@/composables/useTranslations';

/**
 * The − / n / + control on each order line (Figma 335:4233): three 27px cells
 * inside one bordered box, the number editable in place.
 *
 * Quantity is a whole count of sales units, so it is an integer here rather
 * than one of the decimal strings inventory quantities use.
 */
const props = withDefaults(defineProps<{ modelValue: number; min?: number; label?: string }>(), {
    min: 1,
    label: 'Quantity',
});

const emit = defineEmits<{ 'update:modelValue': [value: number] }>();

const { t } = useTranslations();

function set(value: number): void {
    emit('update:modelValue', Math.max(props.min, Math.round(value) || props.min));
}
</script>

<template>
    <div class="inline-flex h-7 items-stretch border border-border">
        <button
            type="button"
            class="w-7 shrink-0 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
            :disabled="modelValue <= min"
            :aria-label="t('Decrease :label', { label: t(label).toLowerCase() })"
            @click="set(modelValue - 1)"
        >
            −
        </button>
        <input
            :value="modelValue"
            type="number"
            inputmode="numeric"
            :min="min"
            :aria-label="t(label)"
            class="w-10 border-x border-border bg-transparent text-center text-xs tabular-nums focus-visible:outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
            @input="set(Number.parseInt(($event.target as HTMLInputElement).value, 10))"
        />
        <button
            type="button"
            class="w-7 shrink-0 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            :aria-label="t('Increase :label', { label: t(label).toLowerCase() })"
            @click="set(modelValue + 1)"
        >
            +
        </button>
    </div>
</template>
