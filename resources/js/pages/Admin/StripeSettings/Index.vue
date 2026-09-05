<script setup lang="ts">
import { ref } from 'vue';
import { Bolt } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import { csrfHeader } from '@/lib/csrf';
import type { AdminStripeAccount } from '@/types/admin';

/**
 * Stripe Settings — port of App\Filament\Pages\StripeSettings. Read-only
 * diagnostics (secrets live in the environment); "Test connection" is a live
 * on-demand check, not a page prop.
 */
defineProps<{
    secretConfigured: boolean;
    webhookConfigured: boolean;
    successUrl: string;
    cancelUrl: string;
}>();

const { t } = useTranslations();

const testing = ref(false);
const account = ref<AdminStripeAccount | null>(null);
const testError = ref<string | null>(null);

async function testConnection(): Promise<void> {
    testing.value = true;
    account.value = null;
    testError.value = null;

    try {
        const response = await fetch(`${ADMIN_BASE}/stripe-settings/test-connection`, {
            method: 'POST',
            headers: { Accept: 'application/json', ...csrfHeader() },
            credentials: 'same-origin',
        });
        const body = await response.json();

        if (!response.ok) {
            testError.value = typeof body.message === 'string' ? body.message : t('Could not reach Stripe.');

            return;
        }

        account.value = body;
    } catch {
        testError.value = t('Could not reach the server.');
    } finally {
        testing.value = false;
    }
}
</script>

<template>
    <AdminLayout :title="t('Stripe')">
        <div class="space-y-5">
            <PageHeader :title="t('Stripe')">
                <template #actions>
                    <Button size="sm" :disabled="testing" @click="testConnection">
                        <Bolt class="size-3.5" :stroke-width="1.5" />
                        {{ testing ? t('Testing…') : t('Test connection') }}
                    </Button>
                </template>
            </PageHeader>

            <Card class="p-6">
                <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('Configuration') }}</h3>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Secret key') }}</dt>
                        <dd class="mt-0.5"><Badge :variant="secretConfigured ? 'success' : 'destructive'">{{ secretConfigured ? t('Configured') : t('Not configured') }}</Badge></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Webhook secret') }}</dt>
                        <dd class="mt-0.5"><Badge :variant="webhookConfigured ? 'success' : 'warning'">{{ webhookConfigured ? t('Configured') : t('Not configured') }}</Badge></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Success URL') }}</dt>
                        <dd class="mt-0.5 font-mono text-foreground">{{ successUrl || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Cancel URL') }}</dt>
                        <dd class="mt-0.5 font-mono text-foreground">{{ cancelUrl || '—' }}</dd>
                    </div>
                </dl>
            </Card>

            <Card v-if="testError" class="border-destructive/50 p-6">
                <h3 class="mb-1 text-sm font-semibold text-destructive">{{ t('Could not reach Stripe') }}</h3>
                <p class="text-sm text-muted-foreground">{{ testError }}</p>
            </Card>

            <Card v-if="account" class="p-6">
                <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('Connected account') }}</h3>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Account ID') }}</dt>
                        <dd class="mt-0.5 font-mono text-foreground">{{ account.id }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Business name') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ account.business_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Country') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ account.country ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Default currency') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ account.default_currency ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Charges enabled') }}</dt>
                        <dd class="mt-0.5 text-foreground">{{ account.charges_enabled ? t('Yes') : t('No') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Mode') }}</dt>
                        <dd class="mt-0.5"><Badge :variant="account.livemode ? 'solid' : 'neutral'">{{ account.livemode ? t('Live') : t('Test') }}</Badge></dd>
                    </div>
                </dl>
            </Card>
        </div>
    </AdminLayout>
</template>
