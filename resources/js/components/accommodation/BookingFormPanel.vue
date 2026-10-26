<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney } from '@/lib/money';
import type { BookingQuote, BookingRow, VillaRow } from '@/types/accommodation';

/**
 * New / edit booking. The price comes from the villa's nightly rates (BookingPricing on the server),
 * shown live with any nights no rate covers; the agreed total can be typed over it. Editing keeps the
 * saved figures unless "Recalculate from rates" is ticked, as the old app never re-priced on edit.
 */
const props = defineProps<{ open: boolean; booking: BookingRow | null; villas: VillaRow[]; currency: string }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();
const isEdit = computed(() => props.booking !== null);

const form = useForm({
    villa_id: '',
    guest_name: '',
    guest_email: '',
    guest_phone: '',
    check_in: '',
    check_out: '',
    guests: '2',
    notes: '',
    rebate_percent: '',
    total_amount: '',
    deposit_amount: '',
    deposit_paid: false,
    recalculate: false,
});

watch(
    () => [props.open, props.booking?.id],
    () => {
        if (!props.open) return;

        const b = props.booking;
        form.defaults({
            villa_id: b?.villa_id ?? props.villas.find((v) => v.is_active)?.id ?? '',
            guest_name: b?.guest_name ?? '',
            guest_email: b?.guest_email ?? '',
            guest_phone: b?.guest_phone ?? '',
            check_in: b?.check_in ?? '',
            check_out: b?.check_out ?? '',
            guests: String(b?.guests ?? 2),
            notes: b?.notes ?? '',
            rebate_percent: b && b.rebate_percent > 0 ? String(b.rebate_percent) : '',
            total_amount: '',
            deposit_amount: b?.deposit_amount ? String(b.deposit_amount.minor / 100) : '',
            deposit_paid: b?.deposit_paid ?? false,
            recalculate: false,
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const villaOptions = computed(() =>
    props.villas.filter((v) => v.is_active || v.id === props.booking?.villa_id).map((v) => ({ value: v.id, label: v.name })),
);

/* ---- live quote --------------------------------------------------------------- */

const quote = ref<BookingQuote | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let seq = 0;

const datesValid = computed(() => form.check_in !== '' && form.check_out !== '' && form.check_out > form.check_in);

watch(
    () => [props.open, form.villa_id, form.check_in, form.check_out, form.rebate_percent],
    () => {
        clearTimeout(timer);
        if (!props.open || form.villa_id === '' || !datesValid.value) {
            quote.value = null;

            return;
        }
        timer = setTimeout(fetchQuote, 250);
    },
    { immediate: true },
);

async function fetchQuote(): Promise<void> {
    const mine = ++seq;
    const params = new URLSearchParams({ villa_id: form.villa_id, check_in: form.check_in, check_out: form.check_out });
    if (form.rebate_percent.trim() !== '') params.set('rebate_percent', form.rebate_percent);

    try {
        const res = await fetch(`/accommodation/bookings/quote?${params.toString()}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (mine === seq) quote.value = res.ok ? ((await res.json()) as BookingQuote) : null;
    } catch {
        if (mine === seq) quote.value = null;
    }
}

const money = (minor: number): string => formatMoney(minor, props.currency);

/* ---- submit ------------------------------------------------------------------- */

function minor(value: string): number | null {
    const n = Number.parseFloat(value.replace(',', '.'));

    return value.trim() === '' || !Number.isFinite(n) ? null : Math.round(n * 100);
}

function blank(value: string): string | null {
    return value.trim() === '' ? null : value.trim();
}

function submit(): void {
    form
        .transform((d) => ({
            villa_id: d.villa_id,
            guest_name: d.guest_name.trim(),
            guest_email: blank(d.guest_email),
            guest_phone: blank(d.guest_phone),
            check_in: d.check_in,
            check_out: d.check_out,
            guests: Number(d.guests),
            notes: blank(d.notes),
            rebate_percent: d.rebate_percent.trim() === '' ? 0 : Number(d.rebate_percent),
            total_amount: minor(d.total_amount),
            deposit_amount: minor(d.deposit_amount),
            deposit_paid: d.deposit_paid,
            recalculate: d.recalculate,
        }))
        .submit(isEdit.value ? 'patch' : 'post', isEdit.value ? `/accommodation/bookings/${props.booking!.id}` : '/accommodation/bookings', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}

const canSubmit = computed(() => !form.processing && form.villa_id !== '' && form.guest_name.trim() !== '' && datesValid.value);
</script>

<template>
    <SidePanel :open="open" :title="isEdit ? t('Edit booking') : t('New booking')" @close="emit('close')">
        <form id="booking-form" class="flex flex-col gap-5" @submit.prevent="submit">
            <FormField v-slot="{ id }" :label="t('Villa')" required :error="form.errors.villa_id">
                <Select :id="id" v-model="form.villa_id" :options="villaOptions" :placeholder="t('Choose a villa…')" />
            </FormField>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField v-slot="{ id, invalid }" :label="t('Check-in')" required :error="form.errors.check_in">
                    <Input :id="id" v-model="form.check_in" type="date" :invalid="invalid" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Check-out')" required :error="form.errors.check_out">
                    <Input :id="id" v-model="form.check_out" type="date" :invalid="invalid" :min="form.check_in || undefined" />
                </FormField>
            </div>
            <p v-if="form.check_in && form.check_out && !datesValid" class="-mt-3 text-xs text-destructive">{{ t('Check-out must be after check-in.') }}</p>

            <FormField v-slot="{ id, invalid }" :label="t('Guest name')" required :error="form.errors.guest_name">
                <Input :id="id" v-model="form.guest_name" :invalid="invalid" maxlength="255" />
            </FormField>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <FormField v-slot="{ id, invalid }" :label="t('Email')" :error="form.errors.guest_email">
                    <Input :id="id" v-model="form.guest_email" type="email" :invalid="invalid" maxlength="255" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Phone')" :error="form.errors.guest_phone">
                    <Input :id="id" v-model="form.guest_phone" type="tel" :invalid="invalid" maxlength="64" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Guests')" required :error="form.errors.guests">
                    <Input :id="id" v-model="form.guests" type="number" min="1" step="1" :invalid="invalid" />
                </FormField>
            </div>

            <FormField v-slot="{ id, invalid }" :label="t('Rebate %')" :error="form.errors.rebate_percent">
                <Input :id="id" v-model="form.rebate_percent" type="number" min="0" max="100" step="0.01" :invalid="invalid" placeholder="0" />
            </FormField>

            <!-- The price from the rates. -->
            <div class="border border-border bg-muted/40 p-3 text-xs">
                <template v-if="quote">
                    <p class="text-muted-foreground">{{ t(':count nights', { count: quote.nights }) }}</p>
                    <p class="mt-1 tabular-nums">
                        {{ money(quote.subtotal) }}
                        <template v-if="quote.rebate > 0"> − {{ money(quote.rebate) }}</template>
                        = <span class="text-sm font-semibold">{{ money(quote.total) }}</span>
                    </p>
                    <p v-if="quote.missing_nights.length > 0" class="mt-1 text-destructive">
                        {{ t('No rate covers :count nights (:dates); they are priced at 0.', { count: quote.missing_nights.length, dates: quote.missing_nights.join(', ') }) }}
                    </p>
                    <p v-if="isEdit && booking?.total_amount" class="mt-1 text-muted-foreground">
                        {{ t('Saved total: :amount', { amount: formatMoney(booking.total_amount.minor, booking.total_amount.currency) }) }}
                    </p>
                </template>
                <p v-else class="text-muted-foreground">{{ t('Choose a villa and dates to see the price.') }}</p>
            </div>

            <Checkbox v-if="isEdit" v-model="form.recalculate" :label="t('Recalculate from rates')" :hint="t('Otherwise the saved figures are kept, as before.')" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField
                    v-slot="{ id, invalid }"
                    :label="t('Agreed total')"
                    :hint="isEdit ? t('Leave empty to keep the saved total.') : t('Leave empty to use the price from the rates.')"
                    :error="form.errors.total_amount"
                >
                    <Input :id="id" v-model="form.total_amount" type="number" min="0" step="0.01" inputmode="decimal" :invalid="invalid" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Deposit')" :error="form.errors.deposit_amount">
                    <Input :id="id" v-model="form.deposit_amount" type="number" min="0" step="0.01" inputmode="decimal" :invalid="invalid" />
                </FormField>
            </div>
            <Checkbox v-model="form.deposit_paid" :label="t('Deposit paid')" />

            <FormField v-slot="{ id }" :label="t('Notes')" :error="form.errors.notes">
                <Textarea :id="id" v-model="form.notes" :rows="3" :placeholder="t('Optional')" />
            </FormField>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="booking-form" :disabled="!canSubmit">{{ isEdit ? t('Save changes') : t('Add booking') }}</Button>
        </template>
    </SidePanel>
</template>
