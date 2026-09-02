<script setup lang="ts">
/**
 * Figma component `FormSection` — 29 instances across 3 sections. A labelled
 * group of fields inside a form or card; the label is a small uppercase eyebrow
 * (e.g. "COST BASIS", "SELLING" on `449:1577`).
 */
defineProps<{ label?: string }>();
</script>

<template>
    <section class="flex flex-col gap-3">
        <h4 v-if="label" class="text-3xs font-medium tracking-[0.08em] text-muted-foreground uppercase">
            {{ label }}
        </h4>
        <div class="flex flex-col gap-4"><slot /></div>
    </section>
</template>
