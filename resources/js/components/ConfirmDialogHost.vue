<script setup lang="ts">
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import { settleConfirmDialog, useConfirmDialogState } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';

/**
 * The one confirmation dialog for the whole app — mounted once at the app
 * root (app.ts), driven by useConfirm's shared state so any component can
 * open it via `confirmDialog(...)` without mounting its own instance.
 */
const state = useConfirmDialogState();
const { t } = useTranslations();
</script>

<template>
    <Dialog :open="state.open" :title="state.title" @close="settleConfirmDialog(false)">
        <p v-if="state.description" class="text-sm text-muted-foreground">{{ state.description }}</p>

        <template #footer>
            <Button variant="outline" @click="settleConfirmDialog(false)">
                {{ state.cancelLabel || t('Cancel') }}
            </Button>
            <Button :variant="state.tone === 'danger' ? 'destructive' : 'primary'" @click="settleConfirmDialog(true)">
                {{ state.confirmLabel || t('Confirm') }}
            </Button>
        </template>
    </Dialog>
</template>
