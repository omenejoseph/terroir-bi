<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Download, Trash2 } from 'lucide-vue-next';

import UploadDropzone from '@/components/ui/UploadDropzone.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { uploadFile } from '@/composables/useUpload';
import { useTranslations } from '@/composables/useTranslations';
import { formatFileSize } from '@/lib/stock';
import type { InventoryFileItem, InventoryItem } from '@/types/inventory';

/**
 * Product Detail · Docs tab (Figma 449:1577): tech sheets (PDF spec sheets)
 * and general documents, backed by Web\InventoryMediaController — the same
 * two Api\InventoryMediaController pairs, one tab. The design draws these
 * together, so this one component holds both sections rather than splitting
 * into a Docs tab and a hidden third tab nothing links to.
 *
 * Every attachment is named after its own filename (no rename step) — the
 * same "no prompt beyond the file itself" choice ImagesGallery makes for alt
 * text; a rename affordance can follow later if it turns out to matter.
 */
const props = defineProps<{ item: InventoryItem; techSheets: InventoryFileItem[]; documents: InventoryFileItem[] }>();

const { t } = useTranslations();

function nameFrom(filename: string): string {
    const dot = filename.lastIndexOf('.');

    return dot > 0 ? filename.slice(0, dot) : filename;
}

function useUploader(purpose: 'inventory_tech_sheet' | 'inventory_document', endpoint: string, only: string) {
    const uploading = ref(false);
    const error = ref<string | null>(null);

    async function onFiles(files: File[]): Promise<void> {
        uploading.value = true;
        error.value = null;

        for (const file of files) {
            try {
                const { key, content_type } = await uploadFile(file, purpose);

                await new Promise<void>((resolve) => {
                    useForm({ key, content_type, name: nameFrom(file.name) }).post(endpoint, {
                        preserveScroll: true,
                        only: [only],
                        onFinish: () => resolve(),
                    });
                });
            } catch (uploadFailure) {
                error.value = uploadFailure instanceof Error ? uploadFailure.message : t('Upload failed.');
                break;
            }
        }

        uploading.value = false;
    }

    return { uploading, error, onFiles };
}

const techSheetUpload = useUploader('inventory_tech_sheet', `/inventory/${props.item.id}/tech-sheets`, 'techSheets');
const documentUpload = useUploader('inventory_document', `/inventory/${props.item.id}/documents`, 'documents');

async function removeTechSheet(sheet: InventoryFileItem): Promise<void> {
    const ok = await confirmDialog({
        title: t('Remove tech sheet'),
        description: t('Remove :name? This cannot be undone.', { name: sheet.name }),
        tone: 'danger',
    });
    if (!ok) return;

    router.delete(`/inventory/${props.item.id}/tech-sheets/${sheet.id}`, { preserveScroll: true, only: ['techSheets'] });
}

async function removeDocument(document: InventoryFileItem): Promise<void> {
    const ok = await confirmDialog({
        title: t('Remove document'),
        description: t('Remove :name? This cannot be undone.', { name: document.name }),
        tone: 'danger',
    });
    if (!ok) return;

    router.delete(`/inventory/${props.item.id}/documents/${document.id}`, { preserveScroll: true, only: ['documents'] });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <!-- Tech sheets -->
        <section class="flex flex-col gap-3">
            <h3 class="text-sm font-semibold text-foreground">{{ t('Tech sheets') }}</h3>

            <ul v-if="techSheets.length" class="flex flex-col divide-y divide-border border border-border">
                <li v-for="sheet in techSheets" :key="sheet.id" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                    <span class="min-w-0 truncate">{{ sheet.name }}</span>
                    <span class="flex shrink-0 items-center gap-2">
                        <span class="text-xs text-muted-foreground">{{ formatFileSize(sheet.size_bytes) }}</span>
                        <a
                            :href="sheet.url"
                            target="_blank"
                            rel="noopener"
                            class="p-1.5 text-muted-foreground transition-colors hover:text-foreground"
                            :aria-label="t('Download :name', { name: sheet.name })"
                        >
                            <Download class="size-3.5" :stroke-width="1.5" />
                        </a>
                        <button
                            type="button"
                            class="p-1.5 text-muted-foreground transition-colors hover:text-destructive"
                            :aria-label="t('Remove :name', { name: sheet.name })"
                            @click="removeTechSheet(sheet)"
                        >
                            <Trash2 class="size-3.5" :stroke-width="1.5" />
                        </button>
                    </span>
                </li>
            </ul>
            <p v-else class="text-xs text-muted-foreground">{{ t('No tech sheets added yet.') }}</p>

            <UploadDropzone accept="application/pdf" :hint="t('PDF · up to 20 MB')" @files="techSheetUpload.onFiles" />
            <p v-if="techSheetUpload.uploading.value" class="text-xs text-muted-foreground">{{ t('Uploading…') }}</p>
            <p v-if="techSheetUpload.error.value" class="text-xs text-destructive" role="alert">{{ techSheetUpload.error.value }}</p>
        </section>

        <!-- Documents -->
        <section class="flex flex-col gap-3">
            <h3 class="text-sm font-semibold text-foreground">{{ t('Documents') }}</h3>

            <ul v-if="documents.length" class="flex flex-col divide-y divide-border border border-border">
                <li v-for="document in documents" :key="document.id" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                    <span class="min-w-0 truncate">{{ document.name }}</span>
                    <span class="flex shrink-0 items-center gap-2">
                        <span class="text-xs text-muted-foreground">{{ formatFileSize(document.size_bytes) }}</span>
                        <a
                            :href="document.url"
                            target="_blank"
                            rel="noopener"
                            class="p-1.5 text-muted-foreground transition-colors hover:text-foreground"
                            :aria-label="t('Download :name', { name: document.name })"
                        >
                            <Download class="size-3.5" :stroke-width="1.5" />
                        </a>
                        <button
                            type="button"
                            class="p-1.5 text-muted-foreground transition-colors hover:text-destructive"
                            :aria-label="t('Remove :name', { name: document.name })"
                            @click="removeDocument(document)"
                        >
                            <Trash2 class="size-3.5" :stroke-width="1.5" />
                        </button>
                    </span>
                </li>
            </ul>
            <p v-else class="text-xs text-muted-foreground">{{ t('No documents added yet.') }}</p>

            <UploadDropzone :hint="t('PDF, image, Word, Excel or CSV · up to 20 MB')" @files="documentUpload.onFiles" />
            <p v-if="documentUpload.uploading.value" class="text-xs text-muted-foreground">{{ t('Uploading…') }}</p>
            <p v-if="documentUpload.error.value" class="text-xs text-destructive" role="alert">{{ documentUpload.error.value }}</p>
        </section>
    </div>
</template>
