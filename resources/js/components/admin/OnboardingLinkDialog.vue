<script setup lang="ts">
import { ref, watch } from 'vue';

import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import { csrfHeader } from '@/lib/csrf';

/**
 * "Generate subscription link" — port of Tenants\Actions\TenantBillingActions::generateOnboardingLink().
 * The link is generated as a side effect of opening the modal (Filament's
 * `fillForm()`), same here via a plain JSON fetch — it isn't a page prop
 * since nothing about it is worth reloading the page over.
 */
const props = defineProps<{ open: boolean; tenantId: string | null }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const url = ref('');
const loading = ref(false);
const error = ref<string | null>(null);

watch(
    () => [props.open, props.tenantId],
    async ([open, tenantId]) => {
        if (!open || typeof tenantId !== 'string') return;

        url.value = '';
        error.value = null;
        loading.value = true;

        try {
            const response = await fetch(`${ADMIN_BASE}/tenants/${tenantId}/onboarding-link`, {
                method: 'POST',
                headers: { Accept: 'application/json', ...csrfHeader() },
                credentials: 'same-origin',
            });
            const body = await response.json();

            if (!response.ok) {
                error.value = typeof body.message === 'string' ? body.message : t('Could not generate the link.');

                return;
            }

            url.value = typeof body.url === 'string' ? body.url : '';
        } catch {
            error.value = t('Could not reach the server.');
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);
</script>

<template>
    <Dialog :open="open" :title="t('Subscription link')" @close="emit('close')">
        <p class="mb-4 text-sm text-muted-foreground">
            {{ t('Send this Stripe Checkout link to the tenant so they can subscribe.') }}
        </p>

        <p v-if="loading" class="text-sm text-muted-foreground">{{ t('Generating…') }}</p>
        <p v-else-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
        <Input v-else readonly :model-value="url" @focus="($event.target as HTMLInputElement).select()" />

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Close') }}</Button>
        </template>
    </Dialog>
</template>
