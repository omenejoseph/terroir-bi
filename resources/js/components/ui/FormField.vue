<script setup lang="ts">
import { useId } from 'vue';

/**
 * One labelled control in a form panel.
 *
 * Geometry from Figma `317:468`: a field is 62px tall — a 20px label, a 6px
 * gap, then a 36px control — and 84px when it carries a hint line. Fields sit
 * 16px apart. Those numbers live here so every form inherits the same rhythm.
 */
defineProps<{ label: string; required?: boolean; hint?: string; error?: string }>();

const id = useId();
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <label :for="id" class="text-sm leading-5 font-medium text-foreground">
            {{ label }}<span v-if="required" class="ml-1 text-destructive" aria-hidden="true">*</span>
        </label>

        <slot :id="id" :invalid="Boolean(error)" />

        <p v-if="error" class="text-xs text-destructive" role="alert">{{ error }}</p>
        <p v-else-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
