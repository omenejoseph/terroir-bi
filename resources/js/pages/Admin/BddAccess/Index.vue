<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Lock, LockOpen } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import Disclosure from '@/components/ui/Disclosure.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminBddOperationSpec } from '@/types/admin';

/**
 * BDD Access — port of App\Filament\Pages\BddAccess. The fail-closed
 * allowlist manager: built-ins are always available (reference only), every
 * discoverable action class needs an explicit grant.
 */
const props = defineProps<{ builtIns: AdminBddOperationSpec[]; actions: AdminBddOperationSpec[] }>();

const { t } = useTranslations();

function grant(spec: AdminBddOperationSpec): void {
    router.post(`${ADMIN_BASE}/bdd-access/grant`, { key: spec.key }, { preserveScroll: true });
}

function revoke(spec: AdminBddOperationSpec): void {
    if (!confirm(t('Revoke :key? Scenarios using it will park as "needs access" on their next run.', { key: spec.key }))) {
        return;
    }

    router.post(`${ADMIN_BASE}/bdd-access/revoke`, { key: spec.key }, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="t('BDD Access')">
        <div class="space-y-5">
            <PageHeader :title="t('BDD test access')" />

            <Card class="p-6">
                <h3 class="mb-1 text-sm font-semibold text-foreground">{{ t('Discoverable actions') }}</h3>
                <p class="mb-4 text-xs text-muted-foreground">
                    {{ t('Every action class an AI-run scenario could invoke — grant only what a scenario legitimately needs.') }}
                </p>
                <ul class="divide-y divide-border">
                    <li v-for="spec in props.actions" :key="spec.key" class="flex items-start justify-between gap-4 py-3">
                        <div class="min-w-0">
                            <p class="truncate font-mono text-sm text-foreground">{{ spec.key }}</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">{{ spec.summary }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <Badge v-if="spec.granted" variant="success">{{ t('Granted') }}</Badge>
                            <Button
                                v-if="spec.granted"
                                variant="ghost"
                                size="sm"
                                class="text-destructive hover:bg-destructive/10"
                                @click="revoke(spec)"
                            >
                                <Lock class="size-3.5" :stroke-width="1.5" />
                                {{ t('Revoke') }}
                            </Button>
                            <Button v-else variant="outline" size="sm" @click="grant(spec)">
                                <LockOpen class="size-3.5" :stroke-width="1.5" />
                                {{ t('Grant') }}
                            </Button>
                        </div>
                    </li>

                    <li v-if="props.actions.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                        {{ t('No discoverable actions found.') }}
                    </li>
                </ul>
            </Card>

            <Card class="p-6">
                <Disclosure
                    :title="t('Built-in seeds & probes')"
                    :summary="t('Always available — shown for reference, no grant needed.')"
                >
                    <ul class="divide-y divide-border">
                        <li v-for="spec in props.builtIns" :key="spec.key" class="py-3">
                            <p class="font-mono text-sm text-foreground">{{ spec.key }}</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">{{ spec.summary }}</p>
                        </li>
                    </ul>
                </Disclosure>
            </Card>
        </div>
    </AdminLayout>
</template>
