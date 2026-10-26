<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRight, Check, Clock, ExternalLink } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import { useTranslations } from '@/composables/useTranslations';
import { onboardingStep, setupHref } from '@/lib/onboarding';
import type { OnboardingStepMeta, OnboardingStepState, OnboardingStepStatus, OnboardingSummary } from '@/lib/onboarding';
import type { SharedProps } from '@/types';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * The new-account setup hub (nav's "System · Setup", capability
 * `settings.manage`) — see docs/21-onboarding-plan.md.
 *
 * Each card is one "Go live" step. Its status comes from the tenant's real
 * data (App\Services\Onboarding\OnboardingStatus), so there is nothing to tick
 * off here: the primary button opens the existing screen that does the work,
 * in setup mode (SetupBanner), and the card turns done once the data is in.
 * The only writes this page makes are the ones data can't express — skipping
 * an optional step, confirming one by hand, and hiding the dashboard reminder.
 */
const props = defineProps<{
    steps: OnboardingStepState[];
    summary: OnboardingSummary;
    /** A customer with an order link, so the "Customer order links" step can show what they see. */
    portalPreview?: { customer: string; url: string } | null;
}>();

const page = usePage<SharedProps>();
const { t } = useTranslations();

interface Row {
    meta: OnboardingStepMeta;
    state: OnboardingStepState;
}

/** Server order, joined to the copy; a key the client doesn't know (a newer server) is left out. */
const rows = computed<Row[]>(() =>
    props.steps.flatMap((state) => {
        const meta = onboardingStep(state.key);

        return meta ? [{ meta, state }] : [];
    }),
);

/** "Go live" first (what the progress bar counts), then "Sell better". Empty sections are dropped. */
const sections = computed(() =>
    [
        { key: 'go_live', title: t('Go live'), description: t('What the account needs before its first order.') },
        { key: 'sell_better', title: t('Sell better'), description: t('Once you\'re taking orders: trustworthy margins, sharper prices and less typing.') },
        { key: 'deepen', title: t('Go further'), description: t('Optional depth for the parts of Terroir you use — skip anything that doesn\'t apply.') },
    ]
        .map((section) => ({ ...section, rows: rows.value.filter((row) => row.state.section === section.key) }))
        .filter((section) => section.rows.length > 0),
);

const percent = computed(() => (props.summary.total === 0 ? 0 : Math.round((props.summary.done / props.summary.total) * 100)));

const STATUS_LABEL: Record<OnboardingStepStatus, string> = {
    not_started: 'Not started',
    in_progress: 'In progress',
    done: 'Done',
    skipped: 'Skipped',
};

function statusVariant(status: OnboardingStepStatus): 'success' | 'outline' | 'neutral' {
    if (status === 'done') return 'success';
    if (status === 'in_progress') return 'outline';

    return 'neutral';
}

/** "3 of 5" where the step is measured across rows, otherwise a plain count where it has one. */
function detail(state: OnboardingStepState): string | null {
    if (state.progress) {
        return t(':done of :total', { done: state.progress.done, total: state.progress.total });
    }

    return state.count !== null && state.count > 0 ? t(':count added', { count: state.count }) : null;
}

const settled = (status: OnboardingStepStatus): boolean => status === 'done' || status === 'skipped';

function update(step: OnboardingStepState, data: { skipped: boolean } | { done: boolean }): void {
    router.patch(`/setup/steps/${step.key}`, data, { preserveScroll: true });
}

function dismiss(dismissed: boolean): void {
    router.post('/setup/dismiss', { dismissed }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Setup')" />
    <div class="max-w-3xl space-y-6">
        <PageHeader
            :title="t('Set up :name', { name: page.props.org?.name ?? '' })"
            :description="t('Everything this account needs before it can take its first order. Each step opens the screen where the work happens.')"
        />

        <Card v-if="!summary.welcomed" class="flex flex-wrap items-center justify-between gap-3 border-primary/40 p-5">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-foreground">{{ t('Start with three quick questions') }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ t('Tell us what you sell and who to, and we\'ll prepare your categories and pricing tiers.') }}
                </p>
            </div>
            <Button size="sm" href="/setup/welcome">
                {{ t('Answer the questions') }}
                <ArrowRight class="size-3.5" :stroke-width="1.5" />
            </Button>
        </Card>

        <Card class="space-y-3 p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm font-medium text-foreground">
                    {{ summary.complete ? t('You\'re ready to take orders.') : t(':done of :total steps complete', { done: summary.done, total: summary.total }) }}
                </p>
                <p class="text-xs text-muted-foreground">{{ percent }}%</p>
            </div>
            <ProgressBar :value="percent" :label="t('Setup progress')" />
            <div v-if="summary.complete" class="flex flex-wrap items-center justify-between gap-3 pt-1">
                <p class="text-xs text-muted-foreground">
                    {{ t('Go-live is done. Create your first order, or keep refining products, prices and customers at your own pace.') }}
                </p>
                <Button size="sm" href="/orders">
                    {{ t('Go to orders') }}
                    <ArrowRight class="size-3.5" :stroke-width="1.5" />
                </Button>
            </div>
        </Card>

        <section v-for="section in sections" :key="section.key" class="space-y-3">
            <div>
                <h3 class="text-sm font-semibold text-foreground">{{ section.title }}</h3>
                <p class="text-xs text-muted-foreground">{{ section.description }}</p>
            </div>
        <ol class="space-y-3">
            <li v-for="(row, i) in section.rows" :key="row.meta.key">
                <Card class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start">
                    <!-- Step number, or a check once settled -->
                    <div
                        class="grid size-7 shrink-0 place-items-center rounded-full border text-xs font-semibold"
                        :class="
                            settled(row.state.status)
                                ? 'border-transparent bg-primary text-primary-foreground'
                                : 'border-border text-muted-foreground'
                        "
                        aria-hidden="true"
                    >
                        <Check v-if="settled(row.state.status)" class="size-3.5" :stroke-width="2" />
                        <span v-else>{{ i + 1 }}</span>
                    </div>

                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-foreground">{{ t(row.meta.title) }}</h3>
                            <Badge :variant="statusVariant(row.state.status)">{{ t(STATUS_LABEL[row.state.status]) }}</Badge>
                            <span v-if="detail(row.state)" class="text-xs text-muted-foreground">{{ detail(row.state) }}</span>
                        </div>
                        <p class="text-xs text-muted-foreground">{{ t(row.meta.why) }}</p>

                        <ul v-if="row.state.issues.length" class="space-y-1">
                            <li
                                v-for="issue in row.state.issues"
                                :key="issue.message"
                                class="flex items-start gap-1.5 text-xs text-destructive"
                            >
                                <AlertTriangle class="mt-px size-3.5 shrink-0" :stroke-width="1.5" />
                                <span>{{ t(issue.message, { count: issue.count }) }}</span>
                            </li>
                        </ul>

                        <a
                            v-if="row.meta.key === 'order_portal' && portalPreview"
                            :href="portalPreview.url"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 text-xs text-foreground underline underline-offset-2"
                        >
                            {{ t('See what :customer sees', { customer: portalPreview.customer }) }}
                            <ExternalLink class="size-3" :stroke-width="1.5" />
                        </a>

                        <p v-if="!settled(row.state.status)" class="flex items-center gap-1 text-2xs text-muted-foreground">
                            <Clock class="size-3" :stroke-width="1.5" />
                            {{ t('About :minutes min', { minutes: row.meta.minutes }) }}
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:items-end">
                        <Button
                            size="sm"
                            :variant="row.state.status === 'done' ? 'outline' : 'primary'"
                            :href="setupHref(row.meta)"
                        >
                            {{ row.state.status === 'done' ? t('Review') : row.state.status === 'in_progress' ? t('Continue') : t('Start') }}
                        </Button>
                        <Button
                            v-if="row.state.markable && row.state.status !== 'done'"
                            size="sm"
                            variant="ghost"
                            @click="update(row.state, { done: true })"
                        >
                            {{ t('Mark as done') }}
                        </Button>
                        <Button
                            v-else-if="row.state.skippable && row.state.status === 'skipped'"
                            size="sm"
                            variant="ghost"
                            @click="update(row.state, { skipped: false })"
                        >
                            {{ t('Undo skip') }}
                        </Button>
                        <Button
                            v-else-if="row.state.skippable && row.state.status !== 'done'"
                            size="sm"
                            variant="ghost"
                            @click="update(row.state, { skipped: true })"
                        >
                            {{ t('Skip') }}
                        </Button>
                    </div>
                </Card>
            </li>
        </ol>
        </section>

        <p class="text-xs text-muted-foreground">
            <template v-if="summary.dismissed">
                {{ t('The dashboard reminder is hidden.') }}
                <button type="button" class="underline underline-offset-2 hover:text-foreground" @click="dismiss(false)">
                    {{ t('Show it again') }}
                </button>
            </template>
            <template v-else>
                {{ t('This page stays under System · Setup.') }}
                <button type="button" class="underline underline-offset-2 hover:text-foreground" @click="dismiss(true)">
                    {{ t('Hide the reminder from the dashboard') }}
                </button>
            </template>
        </p>
    </div>
</template>
