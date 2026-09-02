<script setup lang="ts">
defineProps<{ label: string; value: string; hint?: string }>();
</script>

<template>
    <div class="rounded-xl border border-border bg-card p-5">
        <p class="text-sm font-medium text-muted-foreground">{{ label }}</p>
        <p class="mt-2 text-2xl font-semibold tracking-tight tabular-nums">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
