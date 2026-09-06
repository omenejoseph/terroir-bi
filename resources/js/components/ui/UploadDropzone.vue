<script setup lang="ts">
import { ref } from 'vue';
import { Upload } from 'lucide-vue-next';

import { useTranslations } from '@/composables/useTranslations';

/**
 * A click-to-browse-or-drop file picker — the shared surface behind Product
 * Detail's Images and Docs tabs (Figma 449:1577), and any later screen that
 * needs one. Emits the raw `File[]`; the caller owns what happens next
 * (uploadFile() + the purpose's own attach request).
 */
const props = withDefaults(defineProps<{ accept?: string; multiple?: boolean; hint?: string }>(), {
    accept: undefined,
    multiple: true,
    hint: undefined,
});

const emit = defineEmits<{ files: [File[]] }>();
const { t } = useTranslations();

const input = ref<HTMLInputElement | null>(null);
const dragging = ref(false);

function open(): void {
    input.value?.click();
}

function onChange(event: Event): void {
    const files = (event.target as HTMLInputElement).files;
    if (files && files.length > 0) emit('files', Array.from(files));
    (event.target as HTMLInputElement).value = '';
}

function onDrop(event: DragEvent): void {
    dragging.value = false;
    const files = event.dataTransfer?.files;
    if (!files || files.length === 0) return;

    emit('files', props.multiple ? Array.from(files) : [files.item(0) as File]);
}
</script>

<template>
    <div
        role="button"
        tabindex="0"
        class="flex cursor-pointer flex-col items-center gap-2 border border-dashed border-border px-6 py-8 text-center transition-colors hover:border-foreground/40"
        :class="dragging && 'border-foreground/60 bg-muted/40'"
        @click="open"
        @keydown.enter="open"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
    >
        <Upload class="size-5 text-muted-foreground" :stroke-width="1.5" />
        <p class="text-sm text-foreground">{{ t('Click to upload or drag and drop') }}</p>
        <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
        <input ref="input" type="file" :accept="accept" :multiple="multiple" class="hidden" @change="onChange" />
    </div>
</template>
