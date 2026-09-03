<script setup lang="ts">
import { WalletCards } from 'lucide-vue-next';

import ProgressBar from '@/components/ui/ProgressBar.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney } from '@/lib/money';
import type { NetCashFlow } from '@/types/dashboard';

/**
 * "Net cash flow" (Figma `208:5852`): cash actually received minus cash
 * actually spent in the window, then what it went to — App\Services\Dashboard
 * \DashboardSummary::netCashFlow() reuses the same cost base keyRatios()
 * already computes, so the two cards cannot disagree about what "spend"
 * means here.
 */
const props = defineProps<{ flow: NetCashFlow; currency: string }>();

const { t } = useTranslations();

const money = (minor: number) => formatMoney(minor, props.currency);
</script>

<template>
    <div class="flex h-full flex-col border border-border bg-card p-4">
        <div class="flex items-center gap-1.5 text-sm font-semibold">
            <WalletCards class="size-4 text-muted-foreground" :stroke-width="1.5" />
            {{ t('Net cash flow') }}
        </div>

        <p
            class="mt-3 text-2xl font-semibold tabular-nums"
            :class="flow.net.minor < 0 && 'text-destructive'"
        >
            {{ money(flow.net.minor) }}
        </p>

        <ul class="mt-4 flex-1 space-y-3">
            <li v-for="category in flow.by_category" :key="category.label" class="space-y-1">
                <div class="flex items-baseline justify-between gap-3 text-xs">
                    <span class="text-foreground">{{ category.label }}</span>
                    <span class="tabular-nums text-muted-foreground">{{ category.percent }}%</span>
                </div>
                <ProgressBar :value="category.percent" :label="category.label" />
            </li>
        </ul>
    </div>
</template>
