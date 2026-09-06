<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';

import UploadDropzone from '@/components/ui/UploadDropzone.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { uploadFile } from '@/composables/useUpload';
import { useTranslations } from '@/composables/useTranslations';
import { formatFileSize } from '@/lib/stock';
import type { InventoryImageItem, InventoryItem } from '@/types/inventory';

/**
 * Product Detail · Images tab (Figma 449:1577): a gallery of the item's own
 * photos, backed by Web\InventoryMediaController — the same presigned-upload
 * verification and attach/delete Api\InventoryMediaController uses.
 */
const props = defineProps<{ item: InventoryItem; images: InventoryImageItem[] }>();

const { t } = useTranslations();

const uploading = ref(false);
const uploadError = ref<string | null>(null);

/** Files upload one at a time — attaching writes `sort_order` off the current
    max, so two in flight together could race onto the same value. */
async function onFiles(files: File[]): Promise<void> {
    uploading.value = true;
    uploadError.value = null;

    for (const file of files) {
        try {
            const { key, content_type } = await uploadFile(file, 'inventory_image');

            await new Promise<void>((resolve) => {
                useForm({ key, content_type, alt: null as string | null }).post(`/inventory/${props.item.id}/images`, {
                    preserveScroll: true,
                    only: ['images'],
                    onFinish: () => resolve(),
                });
            });
        } catch (error) {
            uploadError.value = error instanceof Error ? error.message : t('Upload failed.');
            break;
        }
    }

    uploading.value = false;
}

async function remove(image: InventoryImageItem): Promise<void> {
    const ok = await confirmDialog({
        title: t('Remove image'),
        description: t('Remove this image? This cannot be undone.'),
        tone: 'danger',
    });
    if (!ok) return;

    router.delete(`/inventory/${props.item.id}/images/${image.id}`, {
        preserveScroll: true,
        only: ['images'],
    });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div v-if="images.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <div v-for="image in images" :key="image.id" class="group relative aspect-square overflow-hidden border border-border bg-muted">
                <img :src="image.url" :alt="image.alt ?? ''" class="size-full object-cover" />
                <button
                    type="button"
                    class="absolute top-1.5 right-1.5 rounded-full bg-background/80 p-1.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 hover:text-destructive"
                    :aria-label="t('Remove image')"
                    @click="remove(image)"
                >
                    <Trash2 class="size-3.5" :stroke-width="1.5" />
                </button>
                <span class="absolute right-0 bottom-0 left-0 truncate bg-background/80 px-1.5 py-0.5 text-2xs text-muted-foreground">
                    {{ formatFileSize(image.size_bytes) }}
                </span>
            </div>
        </div>
        <p v-else class="text-xs text-muted-foreground">{{ t('No images added yet.') }}</p>

        <UploadDropzone
            accept="image/jpeg,image/png,image/webp,image/gif"
            :hint="t('JPEG, PNG, WebP or GIF · up to 5 MB each')"
            @files="onFiles"
        />

        <p v-if="uploading" class="text-xs text-muted-foreground">{{ t('Uploading…') }}</p>
        <p v-if="uploadError" class="text-xs text-destructive" role="alert">{{ uploadError }}</p>
    </div>
</template>
