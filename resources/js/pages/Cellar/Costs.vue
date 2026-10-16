<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Select from '@/components/ui/Select.vue';
import StackedBar from '@/components/ui/StackedBar.vue';
import StatCard from '@/components/ui/StatCard.vue';
import { useTranslations } from '@/composables/useTranslations';
import { LOT_STATUS_LABELS, LOT_STATUS_TONES, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';
import type { LotCostRow } from '@/types/cellar';
import type { SharedProps } from '@/types';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * Cellar › Costs (finance) — cost of goods per wine lot: grapes, additions,
 * linked costs and cost carried in by blends; per litre and per bottle; and
 * the margin against the bottled item's selling price.
 */
const props = defineProps<{ lots: LotCostRow[] }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const currency = computed(() => page.props.org?.default_currency ?? 'EUR');
const money = (m: number | null | undefined) => (m === null || m === undefined ? '—' : formatMoney(m, currency.value));

const vintage = ref('');
const status = ref('');
const shown = computed(() => props.lots.filter((l) => (vintage.value === '' || l.vintage === vintage.value) && (status.value === '' || l.status === status.value)));
const vintages = computed(() => [...new Set(props.lots.map((l) => l.vintage).filter(Boolean))] as string[]);

/** Cost of one bottle of the size it was (or will be) bottled in. */
const perBottle = (l: LotCostRow) => Math.round(l.cost.per_liter * ((l.bottle_volume_ml ?? 750) / 1000));
const margin = (l: LotCostRow) => (l.selling_price ? Math.round(((l.selling_price - perBottle(l)) / l.selling_price) * 1000) / 10 : null);

const totals = computed(() => {
    const list = shown.value;
    const sum = (f: (l: LotCostRow) => number) => list.reduce((n, l) => n + f(l), 0);
    const priced = list.filter((l) => l.cost.total > 0);
    const byBottle = [...priced].sort((a, b) => perBottle(a) - perBottle(b));

    return {
        grape: sum((l) => l.cost.grape),
        additions: sum((l) => l.cost.additions),
        other: sum((l) => l.cost.linked + l.cost.carried),
        total: sum((l) => l.cost.total),
        avgBottle: priced.length ? Math.round(priced.reduce((n, l) => n + perBottle(l), 0) / priced.length) : null,
        cheapest: byBottle[0] ?? null,
        dearest: byBottle[byBottle.length - 1] ?? null,
    };
});

const open = ref<string[]>([]);
const toggle = (id: string) => (open.value = open.value.includes(id) ? open.value.filter((x) => x !== id) : [...open.value, id]);
</script>

<template>
    <Head :title="t('Wine production costs')" />
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Wine production costs')" :description="t('Cost of goods per wine lot')" />
            <CellarTabs current="costs" />

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard :label="t('Total COGS')" :value="money(totals.total)" />
                <StatCard :label="t('Avg cost / bottle')" :value="money(totals.avgBottle)" />
                <StatCard :label="t('Most expensive')" :value="totals.dearest ? money(perBottle(totals.dearest)) : '—'" :hint="totals.dearest?.name" />
                <StatCard :label="t('Cheapest')" :value="totals.cheapest ? money(perBottle(totals.cheapest)) : '—'" :hint="totals.cheapest?.name" />
            </div>
            <Card class="p-4">
                <StackedBar
                    :segments="[
                        { label: t('Grape'), value: totals.grape, caption: money(totals.grape) },
                        { label: t('Additions'), value: totals.additions, caption: money(totals.additions) },
                        { label: t('Other'), value: totals.other, caption: money(totals.other) },
                    ]"
                />
            </Card>

            <div class="flex flex-wrap gap-2">
                <Select v-model="vintage" :options="[{ value: '', label: t('All vintages') }, ...vintages.map((v) => ({ value: v, label: v }))]" class="w-36" />
                <Select v-model="status" :options="[{ value: '', label: t('All statuses') }, ...Object.entries(LOT_STATUS_LABELS).map(([value, label]) => ({ value, label: t(label) }))]" class="w-40" />
            </div>

            <Card class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="w-6" />
                            <th class="px-2 py-2 text-left font-medium">{{ t('Lot') }}</th>
                            <th class="hidden px-2 py-2 text-left font-medium md:table-cell">{{ t('Variety') }}</th>
                            <th class="px-2 py-2 text-left font-medium">{{ t('Status') }}</th>
                            <th class="px-2 py-2 text-right font-medium">{{ t('Volume') }}</th>
                            <th class="px-2 py-2 text-right font-medium">{{ t('Total') }}</th>
                            <th class="px-2 py-2 text-right font-medium">{{ t('Cost / L') }}</th>
                            <th class="px-2 py-2 text-right font-medium">{{ t('Cost / bottle') }}</th>
                            <th class="px-2 py-2 text-right font-medium">{{ t('Price') }}</th>
                            <th class="px-2 py-2 text-right font-medium">{{ t('Margin') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="l in shown" :key="l.id">
                            <tr class="cursor-pointer border-t border-border hover:bg-accent/40" @click="toggle(l.id)">
                                <td class="px-2 text-muted-foreground"><ChevronDown v-if="open.includes(l.id)" class="size-3.5" /><ChevronRight v-else class="size-3.5" /></td>
                                <td class="px-2 py-2"><Link :href="`/cellar/lots/${l.id}`" class="font-medium hover:underline" @click.stop>{{ l.name }}</Link> <span class="text-muted-foreground">{{ l.vintage }}</span></td>
                                <td class="hidden px-2 py-2 md:table-cell">{{ l.grape_variety }}</td>
                                <td class="px-2 py-2"><span v-if="l.status" :class="cn('px-1.5 py-0.5 text-[10px]', LOT_STATUS_TONES[l.status])">{{ t(LOT_STATUS_LABELS[l.status] ?? l.status) }}</span></td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ qty(l.initial_volume ?? l.current_volume) }} L</td>
                                <td class="px-2 py-2 text-right font-semibold tabular-nums">{{ money(l.cost.total) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ money(l.cost.per_liter) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ money(perBottle(l)) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ money(l.selling_price) }}</td>
                                <td :class="cn('px-2 py-2 text-right tabular-nums', margin(l) !== null && margin(l)! < 0 && 'text-destructive')">{{ margin(l) === null ? '—' : `${margin(l)}%` }}</td>
                            </tr>
                            <tr v-if="open.includes(l.id)" class="bg-muted/40">
                                <td />
                                <td colspan="9" class="px-2 py-3">
                                    <div class="grid gap-4 md:grid-cols-3">
                                        <div>
                                            <p class="mb-1 font-medium">{{ t('Grapes') }} · {{ money(l.cost.grape) }}</p>
                                            <p v-for="(g, i) in l.grapes ?? []" :key="i" class="text-muted-foreground">{{ g.grape_variety }} · {{ qty(g.weight_kg) }} kg · {{ g.price_per_kg === null ? '—' : `${money(g.price_per_kg)}/kg` }}</p>
                                        </div>
                                        <div>
                                            <p class="mb-1 font-medium">{{ t('Additions') }} · {{ money(l.cost.additions) }}</p>
                                            <p v-for="(a, i) in l.additions ?? []" :key="i" class="text-muted-foreground">{{ a.name }} {{ qty(a.quantity) }} {{ a.unit }} · {{ money(a.total_cost) }}</p>
                                        </div>
                                        <div>
                                            <p class="mb-1 font-medium">{{ t('Linked costs') }} · {{ money(l.cost.linked) }}</p>
                                            <p v-for="(c, i) in l.linked ?? []" :key="i" class="text-muted-foreground">{{ c.description ?? '—' }} · {{ money(c.total) }}</p>
                                            <p v-if="l.cost.carried" class="mt-1">{{ t('Carried in by blends') }} · {{ money(l.cost.carried) }}</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="shown.length === 0"><td colspan="10" class="px-3 py-8 text-center text-muted-foreground">{{ t('No wine lots found. Create wine lots to see cost analysis.') }}</td></tr>
                    </tbody>
                </table>
            </Card>
        </div>
</template>
