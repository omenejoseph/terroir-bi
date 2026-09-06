<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { FileDown } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import UploadDropzone from '@/components/ui/UploadDropzone.vue';
import { useTranslations } from '@/composables/useTranslations';

/**
 * Bulk Import (Inventory / Analytics / Spend's shared "Bulk Import" button,
 * Figma 382:1592 / 386:1673): one CSV, matched against the catalog by SKU.
 *
 * Unlike the presigned-upload flow (ImagesGallery/DocsPanel), the file goes
 * straight to Web\InventoryController::bulkImport() as a normal multipart
 * upload — it's parsed once and discarded, never stored in the bucket, so
 * there's no reason to route it through PresignedUploadService at all.
 *
 * The result (created/updated/skipped counts, and up to 5 skip reasons) comes
 * back as the redirect's flash message — see FlashMessages.vue — rather than
 * a bespoke result view here, matching every other write on these pages.
 */
const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const form = useForm<{ file: File | null }>({ file: null });
const fileName = ref<string | null>(null);

function onFiles(files: File[]): void {
    const file = files[0];
    if (!file) return;

    form.file = file;
    fileName.value = file.name;
}

const canSubmit = computed(() => form.file !== null);

function submit(): void {
    if (!canSubmit.value) return;

    form.post('/inventory/bulk-import', {
        onSuccess: () => {
            form.reset();
            fileName.value = null;
            emit('close');
        },
    });
}

function close(): void {
    form.reset();
    form.clearErrors();
    fileName.value = null;
    emit('close');
}
</script>

<template>
    <Dialog :open="props.open" :title="t('Bulk Import')" @close="close">
        <div class="flex flex-col gap-4">
            <p class="text-sm text-muted-foreground">
                {{ t('One row per item, matched by SKU — a SKU already in the catalog updates that item; any other SKU creates one.') }}
            </p>

            <Button variant="outline" size="sm" class="self-start" href="/inventory/bulk-import/template" download>
                <FileDown class="size-3.5" :stroke-width="1.5" />
                {{ t('Download template') }}
            </Button>

            <UploadDropzone accept=".csv,text/csv,text/plain" :multiple="false" :hint="t('CSV · up to 5 MB')" @files="onFiles" />

            <p v-if="fileName" class="text-sm text-foreground">{{ fileName }}</p>
            <p v-if="form.errors.file" class="text-xs text-destructive" role="alert">{{ form.errors.file }}</p>
        </div>

        <template #footer>
            <Button variant="outline" @click="close">{{ t('Cancel') }}</Button>
            <Button :disabled="form.processing || !canSubmit" @click="submit">
                {{ form.processing ? t('Importing…') : t('Import') }}
            </Button>
        </template>
    </Dialog>
</template>
