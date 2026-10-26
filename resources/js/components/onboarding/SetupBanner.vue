<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight } from 'lucide-vue-next';

import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { onboardingStepsFor, sectionOf, setupHref } from '@/lib/onboarding';
import type { SharedProps } from '@/types';

/**
 * "Setup mode" for an existing screen: when the /setup hub sends an admin to
 * a page with `?setup=<step>`, this strip sits on top of that page's normal
 * content, saying which step they are on and why, with a way back to the hub
 * and on to the next step. The page underneath is unchanged — the checklist
 * reuses the real screens rather than duplicating them.
 *
 * Renders nothing without the query parameter, for an unknown step, or for a
 * member who can't use the hub.
 */
const page = usePage<SharedProps>();
const { can, hasModule } = useAuth();
const { t } = useTranslations();

const current = computed(() => {
    const key = new URLSearchParams(page.url.split('?', 2)[1] ?? '').get('setup');

    return onboardingStepsFor(hasModule).find((step) => step.key === key);
});

/** "Step n of m" and "Next" stay within the current step's section (Go live / Sell better). */
const steps = computed(() =>
    current.value ? onboardingStepsFor(hasModule).filter((step) => sectionOf(step.key) === sectionOf(current.value!.key)) : [],
);

const index = computed(() => (current.value ? steps.value.findIndex((step) => step.key === current.value!.key) : -1));

const step = computed(() => (index.value >= 0 ? steps.value[index.value] : undefined));
const next = computed(() => (index.value >= 0 ? steps.value[index.value + 1] : undefined));
</script>

<template>
    <div
        v-if="step && can('settings.manage')"
        class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3"
        role="region"
        :aria-label="t('Setup')"
    >
        <div class="min-w-0 flex-1">
            <p class="text-2xs font-medium tracking-wide text-muted-foreground uppercase">
                {{ t('Setup · step :n of :total', { n: index + 1, total: steps.length }) }}
            </p>
            <p class="text-sm font-medium text-foreground">{{ t(step.title) }}</p>
            <p class="mt-0.5 text-xs text-muted-foreground">
                {{ t(step.why) }}
                <Link v-if="step.help" :href="`/help/${step.help}`" class="ml-1 whitespace-nowrap text-foreground underline underline-offset-2">
                    {{ t('How to do this') }}
                </Link>
            </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <Link
                href="/setup"
                class="inline-flex h-7 items-center gap-1 rounded-lg px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            >
                <ArrowLeft class="size-3.5" :stroke-width="1.5" />
                {{ t('Back to setup') }}
            </Link>
            <Link
                :href="next ? setupHref(next) : '/setup'"
                class="inline-flex h-7 items-center gap-1 rounded-lg bg-primary px-3 text-xs font-medium text-primary-foreground transition-colors hover:bg-primary/90"
            >
                {{ next ? t('Next: :step', { step: t(next.title) }) : t('Finish') }}
                <ArrowRight class="size-3.5" :stroke-width="1.5" />
            </Link>
        </div>
    </div>
</template>
