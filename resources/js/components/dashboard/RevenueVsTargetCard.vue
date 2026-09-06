<script setup lang="ts">
import { Target } from 'lucide-vue-next';

import ProgressBar from '@/components/ui/ProgressBar.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney } from '@/lib/money';
import type { RevenueVsTarget } from '@/types/dashboard';

/**
 * "Revenue vs. target" (Figma 208:5577 / 286:781), folding in "Target by
 * channel" — one card rather than two, since both are blocked on the same
 * input. `annual_target` and each `channels` row are null/absent until a
 * settings.manage member sets a real figure on the Settings page (see
 * App\Services\Dashboard\DashboardSummary::revenueVsTarget()); nothing here
 * is a guess.
 */
const props = defineProps<{ target: RevenueVsTarget; currency: string }>();

const { t } = useTranslations();
const { can } = useAuth();

const money = (minor: number) => formatMoney(minor, props.currency);
</script>

<template>
    <div class="flex h-full flex-col border border-border bg-card p-4">
        <h3 class="flex items-center gap-1.5 text-sm font-semibold">
            <Target class="size-4 text-muted-foreground" :stroke-width="1.5" />
            {{ t('Revenue vs. target') }}
        </h3>

        <template v-if="target.annual_target">
            <div class="mt-3 flex items-baseline justify-between gap-3">
                <span class="text-2xl font-semibold tabular-nums">{{ target.progress_pct }}%</span>
                <span class="text-xs text-muted-foreground tabular-nums">
                    {{ t(':current of :target', { current: money(target.ytd_revenue.minor), target: money(target.annual_target.minor) }) }}
                </span>
            </div>
            <ProgressBar :value="Math.min(100, target.progress_pct ?? 0)" :label="t('Revenue vs. target')" class="mt-2" />

            <ul v-if="target.channels.length" class="mt-4 flex-1 space-y-3">
                <li v-for="row in target.channels" :key="row.key" class="space-y-1">
                    <div class="flex items-baseline justify-between gap-3 text-xs">
                        <span class="text-foreground">{{ row.label }}</span>
                        <span class="tabular-nums text-muted-foreground">
                            {{ row.pace_pct !== null ? t(':pct% of pace', { pct: row.pace_pct }) : '—' }}
                        </span>
                    </div>
                    <ProgressBar :value="Math.min(100, row.pace_pct ?? 0)" :label="row.label" />
                </li>
            </ul>
            <p v-else class="mt-4 flex-1 text-xs text-muted-foreground">
                {{ t('No per-channel target is set yet.') }}
            </p>
        </template>

        <p v-else class="mt-4 text-xs text-muted-foreground">
            {{ t("No annual target is set, so progress can't be calculated yet.") }}
            <a v-if="can('settings.manage')" href="/settings" class="text-foreground underline">{{ t('Set one in Settings.') }}</a>
        </p>
    </div>
</template>
