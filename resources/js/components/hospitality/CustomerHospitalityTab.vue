<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { PencilLine, Trash2 } from 'lucide-vue-next';

import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { formatReservationDate, RESERVATION_STATUS_LABEL, RESERVATION_STATUS_VARIANT } from '@/lib/hospitality';
import { formatMoney } from '@/lib/money';
import type { SharedProps } from '@/types';
import type { CustomerHospitality, CustomerProgramPrice } from '@/types/hospitality';

/**
 * Customer — Show · Hospitality: what this agency pays per program (the old app's agency Pricing
 * table, edited in place) and the customer's reservations. Prices go through the same endpoint and
 * rules as the Programs tab: a flat price, or a rebate % that follows the base price.
 */
const props = defineProps<{
    customerId: string;
    isAgency: boolean;
    hospitality: CustomerHospitality | null | undefined;
    canManage: boolean;
}>();

const { t } = useTranslations();
const locale = computed(() => usePage<SharedProps>().props.locale);

const editing = ref<string | null>(null);
const mode = ref<'price' | 'rebate'>('price');
const amount = ref('');
const saving = ref(false);
const error = ref<string | null>(null);

const MODES = computed(() => [
    { value: 'price', label: t('Price per person') },
    { value: 'rebate', label: t('Rebate %') },
]);

function start(row: CustomerProgramPrice): void {
    editing.value = row.program_id;
    mode.value = row.rebate_percent !== null ? 'rebate' : 'price';
    amount.value = row.rebate_percent !== null ? String(row.rebate_percent) : row.price_per_pax ? String(row.price_per_pax.minor / 100) : '';
    error.value = null;
}

const parsed = computed(() => {
    const n = Number.parseFloat(amount.value.replace(',', '.'));

    if (amount.value.trim() === '' || !Number.isFinite(n) || n < 0) return null;
    if (mode.value === 'rebate' && (n <= 0 || n > 100)) return null;

    return n;
});

function save(row: CustomerProgramPrice): void {
    if (parsed.value === null) return;

    saving.value = true;
    router.put(
        `/hospitality/programs/${row.program_id}/agency-prices/${props.customerId}`,
        mode.value === 'rebate' ? { rebate_percent: parsed.value } : { price_per_pax: Math.round(parsed.value * 100) },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['hospitality'],
            onSuccess: () => (editing.value = null),
            onError: (errors) => (error.value = Object.values(errors)[0] ?? t('Could not save the price.')),
            onFinish: () => (saving.value = false),
        },
    );
}

async function remove(row: CustomerProgramPrice): Promise<void> {
    const confirmed = await confirmDialog({
        title: t('Remove agency price?'),
        description: t('This agency will pay the base price for :program again.', { program: row.program_name }),
        confirmLabel: t('Remove'),
        tone: 'danger',
    });

    if (!confirmed) return;

    router.delete(`/hospitality/programs/${row.program_id}/agency-prices/${props.customerId}`, {
        preserveScroll: true,
        preserveState: true,
        only: ['hospitality'],
    });
}
</script>

<template>
    <div class="space-y-5">
        <p v-if="hospitality === undefined" class="text-xs text-muted-foreground">{{ t('Loading…') }}</p>

        <template v-else-if="hospitality">
            <!-- Agency price book -->
            <section v-if="isAgency" class="border border-border bg-card">
                <h3 class="border-b border-border px-4 py-3 text-sm font-semibold">{{ t('Agency prices') }}</h3>
                <p v-if="hospitality.prices.length === 0" class="px-4 py-8 text-center text-xs text-muted-foreground">
                    {{ t('No programs yet. Add the tastings and tours you sell to groups.') }}
                </p>
                <ul v-else class="divide-y divide-border">
                    <li v-for="row in hospitality.prices" :key="row.program_id" class="px-4 py-3 text-xs">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                            <div class="min-w-0 flex-1 basis-48">
                                <p class="font-medium text-foreground">
                                    {{ row.program_name }}
                                    <Badge v-if="!row.is_active" variant="outline" class="ml-1">{{ t('Inactive') }}</Badge>
                                </p>
                                <p class="text-muted-foreground">
                                    {{ t('Base price :price per person', { price: formatMoney(row.base_price.minor, row.base_price.currency) }) }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold tabular-nums">
                                    {{ row.price_per_pax ? formatMoney(row.price_per_pax.minor, row.price_per_pax.currency) : t('Base price') }}
                                </p>
                                <p v-if="row.rebate_percent !== null" class="text-muted-foreground">
                                    {{ t(':percent% rebate, follows the base price', { percent: row.rebate_percent }) }}
                                </p>
                                <p v-else-if="row.effective_rebate_percent" class="text-muted-foreground">
                                    {{ t(':percent% below base', { percent: row.effective_rebate_percent }) }}
                                </p>
                            </div>
                            <div v-if="canManage" class="flex shrink-0 items-center gap-1">
                                <button type="button" class="p-1 text-muted-foreground hover:text-foreground" :aria-label="t('Edit')" @click="start(row)">
                                    <PencilLine class="size-3.5" :stroke-width="1.5" />
                                </button>
                                <button
                                    v-if="row.price_per_pax"
                                    type="button"
                                    class="p-1 text-muted-foreground hover:text-destructive"
                                    :aria-label="t('Remove')"
                                    @click="remove(row)"
                                >
                                    <Trash2 class="size-3.5" :stroke-width="1.5" />
                                </button>
                            </div>
                        </div>

                        <form v-if="editing === row.program_id" class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="save(row)">
                            <Tabs :items="MODES" :current="mode" @select="(v) => (mode = v as 'price' | 'rebate')" />
                            <div class="w-32">
                                <label :for="`agency-price-${row.program_id}`" class="sr-only">{{ mode === 'rebate' ? t('Rebate %') : t('Price per person') }}</label>
                                <Input
                                    :id="`agency-price-${row.program_id}`"
                                    v-model="amount"
                                    type="number"
                                    step="0.01"
                                    inputmode="decimal"
                                    :invalid="error !== null"
                                />
                            </div>
                            <Button variant="outline" size="sm" @click="editing = null">{{ t('Cancel') }}</Button>
                            <Button type="submit" size="sm" :disabled="saving || parsed === null">{{ t('Save price') }}</Button>
                            <p v-if="error" class="w-full text-destructive">{{ error }}</p>
                        </form>
                    </li>
                </ul>
            </section>

            <!-- Reservations -->
            <section class="border border-border bg-card">
                <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-border px-4 py-3">
                    <h3 class="text-sm font-semibold">{{ t('Reservations') }}</h3>
                    <p class="text-xs text-muted-foreground">
                        {{ t(':count reservations', { count: hospitality.reservation_count }) }} ·
                        {{ formatMoney(hospitality.revenue.minor, hospitality.revenue.currency) }}
                    </p>
                </div>
                <p v-if="hospitality.reservations.length === 0" class="px-4 py-8 text-center text-xs text-muted-foreground">
                    {{ t('No reservations yet.') }}
                </p>
                <ul v-else class="divide-y divide-border">
                    <li v-for="row in hospitality.reservations" :key="row.id">
                        <Link :href="`/hospitality/reservations/${row.id}`" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-xs hover:bg-muted/40">
                            <span class="w-40 shrink-0 tabular-nums">{{ formatReservationDate(row.date, locale, true) }}</span>
                            <span class="min-w-0 flex-1 truncate">
                                <span class="font-medium text-foreground">{{ row.guest_name ?? '—' }}</span>
                                <span class="text-muted-foreground"> · {{ row.program?.name ?? '—' }} · {{ t(':count guests', { count: row.pax }) }}</span>
                            </span>
                            <span class="font-semibold tabular-nums">{{ formatMoney(row.total_amount.minor, row.total_amount.currency) }}</span>
                            <Badge :variant="RESERVATION_STATUS_VARIANT[row.status]">{{ t(RESERVATION_STATUS_LABEL[row.status]) }}</Badge>
                        </Link>
                    </li>
                </ul>
                <p v-if="hospitality.reservation_count > hospitality.reservations.length" class="border-t border-border px-4 py-3 text-xs text-muted-foreground">
                    <Link :href="`/hospitality?customer_id=${customerId}&period=all`" class="hover:underline">{{ t('See all :count reservations', { count: hospitality.reservation_count }) }}</Link>
                </p>
            </section>
        </template>
    </div>
</template>
