<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowRight, X } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { OnboardingSummary } from '@/lib/onboarding';

/**
 * The dashboard's "Finish setting up" card. The server only sends a summary
 * to members who can use the /setup hub, and stops once it is dismissed (see
 * Web\DashboardController), so this renders whatever it is given.
 */
const props = defineProps<{ summary: OnboardingSummary }>();

const { t } = useTranslations();

const percent = computed(() => (props.summary.total === 0 ? 0 : Math.round((props.summary.done / props.summary.total) * 100)));

function dismiss(): void {
    router.post('/setup/dismiss', { dismissed: true }, { preserveScroll: true });
}
</script>

<template>
    <Card class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center">
        <div class="min-w-0 flex-1 space-y-2">
            <p class="text-sm font-medium text-foreground">
                {{ summary.complete ? t('You\'re set up — time for your first order.') : t('Finish setting up your account') }}
            </p>
            <p class="text-xs text-muted-foreground">
                {{
                    summary.complete
                        ? t('Every go-live step is done. Products, prices and customers can keep growing from here.')
                        : t(':done of :total steps complete. Products, prices and customers are what orders need.', { done: summary.done, total: summary.total })
                }}
            </p>
            <ProgressBar :value="percent" :label="t('Setup progress')" class="max-w-sm" />
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <Button size="sm" :href="summary.complete ? '/orders' : '/setup'">
                {{ summary.complete ? t('Go to orders') : t('Continue setup') }}
                <ArrowRight class="size-3.5" :stroke-width="1.5" />
            </Button>
            <Button size="icon" variant="ghost" :aria-label="t('Hide the reminder from the dashboard')" @click="dismiss">
                <X class="size-4" :stroke-width="1.5" />
            </Button>
        </div>
    </Card>
</template>
