<script setup lang="ts">
/**
 * A printable sheet: invisible on screen, and the only thing on paper while
 * `print()` runs (see the `print-sheet-active` rule in app.css). Replaces the
 * prototype's `window.open()` + hand-written HTML.
 */
defineProps<{ title: string }>();

function print(): void {
    document.body.classList.add('print-sheet-active');
    const done = () => {
        document.body.classList.remove('print-sheet-active');
        window.removeEventListener('afterprint', done);
    };
    window.addEventListener('afterprint', done);
    window.print();
}

defineExpose({ print });
</script>

<template>
    <Teleport to="body">
        <div class="print-sheet hidden bg-white p-6 text-[13px] text-black print:block">
            <h1 class="mb-1 text-lg font-bold">{{ title }}</h1>
            <slot />
        </div>
    </Teleport>
</template>
