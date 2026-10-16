<script setup lang="ts">
/**
 * The small uppercase label above each block of the product page's Stock tab
 * (STOCK POSITION, EXITS, ECONOMICS, MOVEMENTS), with optional controls at the right.
 */
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h3 class="text-3xs font-medium tracking-[0.08em] text-muted-foreground uppercase"><slot /></h3>
        <div v-if="$slots.actions" class="flex items-center gap-2"><slot name="actions" /></div>
    </div>
</template>
