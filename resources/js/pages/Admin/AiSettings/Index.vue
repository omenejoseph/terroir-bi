<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Bolt, Check, Cog, X } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import ConfigureModelsDialog from '@/components/admin/ConfigureModelsDialog.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminAiCapabilityRow, AdminAiRequiredKeys } from '@/types/admin';

/** AI Settings — port of App\Filament\Pages\AiSettings. */
const props = defineProps<{
    aiEnabled: boolean;
    gatewayConfigured: boolean;
    accountConfigured: boolean;
    tokenConfigured: boolean;
    requiredKeys: AdminAiRequiredKeys[];
    capabilities: Record<string, AdminAiCapabilityRow>;
    modelOptions: Record<string, string[]>;
}>();

const { t } = useTranslations();

const configureOpen = ref(false);

function testAll(): void {
    router.post(`${ADMIN_BASE}/ai-settings/test-all`, {}, { preserveScroll: true });
}

function test(key: string): void {
    router.post(`${ADMIN_BASE}/ai-settings/${key}/test`, {}, { preserveScroll: true });
}

function enable(key: string): void {
    router.post(`${ADMIN_BASE}/ai-settings/${key}/enable`, {}, { preserveScroll: true });
}

function disable(key: string): void {
    router.post(`${ADMIN_BASE}/ai-settings/${key}/disable`, {}, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="t('AI')">
        <div class="space-y-5">
            <PageHeader :title="t('AI')">
                <template #actions>
                    <Button variant="outline" size="sm" @click="testAll">
                        <Bolt class="size-3.5" :stroke-width="1.5" />
                        {{ t('Test all') }}
                    </Button>
                    <Button size="sm" @click="configureOpen = true">
                        <Cog class="size-3.5" :stroke-width="1.5" />
                        {{ t('Configure models') }}
                    </Button>
                </template>
            </PageHeader>

            <Card class="p-6">
                <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('Gateway status') }}</h3>
                <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('AI enabled') }}</dt>
                        <dd class="mt-0.5"><Badge :variant="aiEnabled ? 'success' : 'neutral'">{{ aiEnabled ? t('Yes') : t('No') }}</Badge></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Gateway') }}</dt>
                        <dd class="mt-0.5"><Badge :variant="gatewayConfigured ? 'success' : 'neutral'">{{ gatewayConfigured ? t('Configured') : t('Not configured') }}</Badge></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Account') }}</dt>
                        <dd class="mt-0.5"><Badge :variant="accountConfigured ? 'success' : 'neutral'">{{ accountConfigured ? t('Configured') : t('Not configured') }}</Badge></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Token') }}</dt>
                        <dd class="mt-0.5"><Badge :variant="tokenConfigured ? 'success' : 'neutral'">{{ tokenConfigured ? t('Configured') : t('Not configured') }}</Badge></dd>
                    </div>
                </dl>
            </Card>

            <Card class="p-6">
                <h3 class="mb-1 text-sm font-semibold text-foreground">{{ t('Required keys') }}</h3>
                <p class="mb-4 text-xs text-muted-foreground">
                    {{ t('Provider keys are stored in the Cloudflare dashboard (BYOK), not in this app — the live test below confirms a key actually reaches the provider.') }}
                </p>
                <div class="space-y-3">
                    <div v-for="row in requiredKeys" :key="row.provider" class="flex items-start justify-between gap-4 border-b border-border pb-3 last:border-b-0 last:pb-0">
                        <div>
                            <p class="text-sm font-medium text-foreground">{{ row.label }}</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">{{ row.capabilities.join(', ') }}</p>
                            <p class="mt-0.5 font-mono text-xs text-muted-foreground">{{ row.models.join(', ') }}</p>
                        </div>
                        <Badge :variant="row.byok ? 'warning' : 'neutral'">{{ row.byok ? t('BYOK') : t('Built-in') }}</Badge>
                    </div>
                    <p v-if="requiredKeys.length === 0" class="text-sm text-muted-foreground">{{ t('No models configured yet.') }}</p>
                </div>
            </Card>

            <Card class="p-6">
                <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('Capabilities') }}</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="border-b border-border text-left text-muted-foreground">
                            <tr>
                                <th class="py-2 font-medium">{{ t('Capability') }}</th>
                                <th class="py-2 font-medium">{{ t('Model') }}</th>
                                <th class="py-2 font-medium">{{ t('Enabled') }}</th>
                                <th class="py-2"><span class="sr-only">{{ t('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, key) in capabilities" :key="key" class="border-b border-border last:border-b-0">
                                <td class="py-2.5 text-foreground">{{ row.label }}</td>
                                <td class="py-2.5 font-mono text-muted-foreground">{{ row.model }}</td>
                                <td class="py-2.5">
                                    <Badge :variant="row.enabled ? 'success' : 'neutral'">{{ row.enabled ? t('Enabled') : t('Disabled') }}</Badge>
                                </td>
                                <td class="py-2.5">
                                    <div class="flex justify-end gap-1">
                                        <Button variant="ghost" size="sm" @click="test(String(key))">
                                            <Bolt class="size-3.5" :stroke-width="1.5" />
                                            {{ t('Test') }}
                                        </Button>
                                        <Button v-if="row.enabled" variant="ghost" size="sm" @click="disable(String(key))">
                                            <X class="size-3.5" :stroke-width="1.5" />
                                            {{ t('Disable') }}
                                        </Button>
                                        <Button v-else variant="ghost" size="sm" @click="enable(String(key))">
                                            <Check class="size-3.5" :stroke-width="1.5" />
                                            {{ t('Enable') }}
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <ConfigureModelsDialog
            :open="configureOpen"
            :capabilities="capabilities"
            :model-options="modelOptions"
            @close="configureOpen = false"
        />
    </AdminLayout>
</template>
