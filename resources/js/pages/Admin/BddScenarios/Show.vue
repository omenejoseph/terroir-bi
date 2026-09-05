<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, LockOpen, PencilLine, Play } from 'lucide-vue-next';

import AdminLayout from '@/layouts/AdminLayout.vue';
import BddScenarioFormPanel from '@/components/admin/BddScenarioFormPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import Disclosure from '@/components/ui/Disclosure.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ADMIN_BASE } from '@/lib/adminNavigation';
import type { AdminBddScenarioDetail } from '@/types/admin';

/**
 * BDD Scenario — Show. Port of App\Filament\Resources\BddScenarios\Schemas\BddScenarioInfolist.
 * While a run is in flight, polls the `status` JSON endpoint every 2s (same
 * precedent as NotificationsPanel.vue) and replaces the local scenario state
 * wholesale — cheap, and correct whether still running or just finished.
 */
const props = defineProps<{ scenario: AdminBddScenarioDetail }>();

const { t } = useTranslations();

const scenario = ref<AdminBddScenarioDetail>(props.scenario);
watch(() => props.scenario, (value) => (scenario.value = value));

let poll: ReturnType<typeof setInterval> | undefined;

function stopPolling(): void {
    clearInterval(poll);
    poll = undefined;
}

async function tick(): Promise<void> {
    try {
        const response = await fetch(`${ADMIN_BASE}/bdd-scenarios/${scenario.value.id}/status`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) return;

        scenario.value = await response.json();
    } catch {
        // A transient failure just waits for the next tick.
    } finally {
        if (!scenario.value.in_flight) stopPolling();
    }
}

watch(
    () => scenario.value.in_flight,
    (inFlight) => {
        if (inFlight && poll === undefined) {
            poll = setInterval(() => void tick(), 2_000);
        } else if (!inFlight) {
            stopPolling();
        }
    },
    { immediate: true },
);

onBeforeUnmount(stopPolling);

const formOpen = ref(false);

function run(): void {
    if (!confirm(t('Queue a background run: an AI agent executes the Gherkin live against a throwaway sandbox (always rolled back). Costs one AI call.'))) {
        return;
    }

    router.post(`${ADMIN_BASE}/bdd-scenarios/${scenario.value.id}/run`, {}, { preserveScroll: true, only: ['scenario'] });
}

function grantAccess(): void {
    const list = scenario.value.denied_operations.join(', ');

    if (!confirm(t('Grant: :operations — the next run picks the grants up automatically.', { operations: list }))) return;

    router.post(`${ADMIN_BASE}/bdd-scenarios/${scenario.value.id}/grant-access`, {}, { preserveScroll: true });
}

const STATUS_VARIANT: Record<string, 'success' | 'warning' | 'destructive' | 'neutral'> = {
    READY: 'success',
    NEEDS_ACCESS: 'warning',
    COMPILE_FAILED: 'destructive',
};

const RUN_STATUS_VARIANT: Record<string, 'success' | 'warning' | 'destructive' | 'neutral'> = {
    PASS: 'success',
    FAIL: 'destructive',
    ERROR: 'destructive',
    NEEDS_ACCESS: 'warning',
    QUEUED: 'neutral',
    RUNNING: 'neutral',
};

const statusVariant = computed(() => STATUS_VARIANT[scenario.value.status] ?? 'neutral');
const runStatusVariant = computed(() =>
    scenario.value.last_run_status ? (RUN_STATUS_VARIANT[scenario.value.last_run_status] ?? 'neutral') : 'neutral',
);
</script>

<template>
    <AdminLayout :title="scenario.title">
        <div class="space-y-5">
            <PageHeader :title="scenario.title">
                <template #actions>
                    <Link
                        :href="`${ADMIN_BASE}/bdd-scenarios`"
                        class="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft class="size-3.5" :stroke-width="1.5" />
                        {{ t('Back to scenarios') }}
                    </Link>
                    <Button
                        v-if="scenario.is_runnable"
                        variant="outline"
                        size="sm"
                        :disabled="scenario.in_flight"
                        @click="run"
                    >
                        <Play class="size-3.5" :stroke-width="1.5" />
                        {{ t('Run') }}
                    </Button>
                    <Button
                        v-if="!scenario.in_flight && scenario.denied_operations.length > 0"
                        variant="outline"
                        size="sm"
                        @click="grantAccess"
                    >
                        <LockOpen class="size-3.5" :stroke-width="1.5" />
                        {{ t('Grant requested access') }}
                    </Button>
                    <Button size="sm" @click="formOpen = true">
                        <PencilLine class="size-3.5" :stroke-width="1.5" />
                        {{ t('Edit') }}
                    </Button>
                </template>
            </PageHeader>

            <Card class="p-6">
                <div class="mb-4 grid grid-cols-3 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Status') }}</dt>
                        <dd class="mt-0.5"><Badge :variant="statusVariant">{{ scenario.status }}</Badge></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Last run') }}</dt>
                        <dd class="mt-0.5">
                            <Badge v-if="scenario.last_run_status" :variant="runStatusVariant">{{ scenario.last_run_status }}</Badge>
                            <span v-else class="text-muted-foreground">{{ t('— never —') }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('Last run at') }}</dt>
                        <dd class="mt-0.5 text-foreground">
                            {{ scenario.last_run_at ? new Date(scenario.last_run_at).toLocaleString() : '—' }}
                        </dd>
                    </div>
                </div>
                <pre class="overflow-x-auto whitespace-pre-wrap rounded-lg bg-muted/40 p-3 font-mono text-sm text-foreground">{{ scenario.gherkin }}</pre>
            </Card>

            <Card v-if="scenario.in_flight" class="p-6">
                <h3 class="mb-1 text-sm font-semibold text-foreground">{{ t('Run in progress') }}</h3>
                <p class="mb-3 text-xs text-muted-foreground">{{ t('Live log — this page refreshes itself every 2 seconds while the run executes.') }}</p>
                <pre class="overflow-x-auto whitespace-pre-wrap rounded-lg bg-muted/40 p-3 font-mono text-xs text-foreground">{{ scenario.live_log.join('\n') }}</pre>
            </Card>

            <Card v-if="!scenario.in_flight && scenario.denied_operations.length > 0" class="p-6">
                <h3 class="mb-1 text-sm font-semibold text-foreground">{{ t('Access needed') }}</h3>
                <p class="mb-3 text-xs text-muted-foreground">
                    {{ t('The latest run hit operations that are not granted — grant access above and run again.') }}
                </p>
                <ul class="list-inside list-disc font-mono text-sm text-foreground">
                    <li v-for="op in scenario.denied_operations" :key="op">{{ op }}</li>
                </ul>
            </Card>

            <Card v-if="!scenario.in_flight && scenario.last_run_steps.length > 0" class="p-6">
                <h3 class="mb-3 text-sm font-semibold text-foreground">{{ t('Last run detail') }}</h3>
                <pre class="overflow-x-auto whitespace-pre-wrap font-mono text-sm text-foreground">{{ scenario.last_run_steps.join('\n') }}</pre>
            </Card>

            <Card v-if="!scenario.in_flight && scenario.run_log.length > 0" class="p-6">
                <Disclosure :title="t('Run log')" :summary="t('The progress log the last run streamed while it executed.')">
                    <pre class="overflow-x-auto whitespace-pre-wrap font-mono text-xs text-foreground">{{ scenario.run_log.join('\n') }}</pre>
                </Disclosure>
            </Card>

            <Card v-if="!scenario.in_flight && scenario.transcript" class="p-6">
                <Disclosure :title="t('Last run transcript')" :summary="t('Every tool call the AI made — the audit trail behind the verdict.')">
                    <pre class="overflow-x-auto whitespace-pre-wrap font-mono text-xs text-foreground">{{ scenario.transcript }}</pre>
                </Disclosure>
            </Card>
        </div>

        <BddScenarioFormPanel :open="formOpen" :scenario="scenario" @close="formOpen = false" />
    </AdminLayout>
</template>
