<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Hourglass } from 'lucide-vue-next';

import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber } from '@/lib/money';
import type { Runway } from '@/types/dashboard';
import type { SharedProps } from '@/types';

/**
 * "Runway" (Figma 208:5808): months of cash left at the current burn rate.
 * `runway` is null entirely until a settings.manage member sets a
 * cash-on-hand figure on the Settings page — see
 * App\Services\Dashboard\DashboardSummary::runway(). The payables-aging line
 * the design also draws (`Order` has no due-date field yet) is not built
 * here; this card ships only the part it can back with a real number.
 */
const props = defineProps<{ runway: Runway | null; currency: string }>();

const { t } = useTranslations();
const { can } = useAuth();
const page = usePage<SharedProps>();

const money = (minor: number) => formatMoney(minor, props.currency);
/** Locale-safe: a raw JS float like 4.2 would print with a period in every
 *  locale, wrong wherever the decimal separator is a comma. */
const months = computed(() => (props.runway?.months !== null && props.runway?.months !== undefined ? formatNumber(props.runway.months, page.props.locale) : null));
</script>

<template>
    <div class="flex h-full flex-col border border-border bg-card p-4">
        <h3 class="flex items-center gap-1.5 text-sm font-semibold">
            <Hourglass class="size-4 text-muted-foreground" :stroke-width="1.5" />
            {{ t('Runway') }}
        </h3>

        <template v-if="runway">
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-2xl font-semibold tabular-nums">
                    {{ months !== null ? t(':count months', { count: months }) : '—' }}
                </span>
            </div>

            <p v-if="runway.months === null" class="mt-1 text-2xs text-muted-foreground">
                {{ t('No burn in the trailing quarter — nothing to divide the cash on hand by.') }}
            </p>

            <dl class="mt-4 flex-1 space-y-2 text-xs">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-muted-foreground">{{ t('Cash on hand') }}</dt>
                    <dd class="tabular-nums text-foreground">{{ money(runway.cash_on_hand.minor) }}</dd>
                </div>
                <div v-if="runway.monthly_burn" class="flex items-baseline justify-between gap-3">
                    <dt class="text-muted-foreground">{{ t('Avg. monthly burn') }}</dt>
                    <dd class="tabular-nums text-foreground">{{ money(runway.monthly_burn.minor) }}</dd>
                </div>
                <div v-if="runway.cash_on_hand_as_of" class="flex items-baseline justify-between gap-3">
                    <dt class="text-muted-foreground">{{ t('As of') }}</dt>
                    <dd class="text-foreground">{{ runway.cash_on_hand_as_of }}</dd>
                </div>
            </dl>
        </template>

        <p v-else class="mt-4 text-xs text-muted-foreground">
            {{ t("No cash balance is on file, so runway can't be calculated yet.") }}
            <a v-if="can('settings.manage')" href="/settings" class="text-foreground underline">{{ t('Set one in Settings.') }}</a>
        </p>
    </div>
</template>
