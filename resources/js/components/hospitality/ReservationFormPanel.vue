<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { TIME_SLOTS } from '@/lib/hospitality';
import { formatMoney } from '@/lib/money';
import type { AgencyOption, ProgramRow, ReservationDetail, ReservationQuote } from '@/types/hospitality';
import type { ComboboxOption } from '@/types/ui';

/**
 * New reservation / edit reservation (the old app's reservation form and the detail page's edit
 * mode). The price is never typed: the server's ReservationPricing decides it, and the panel shows
 * what it will be as program, agency and head count change. On an edit the price is only taken
 * again when one of those three changes — a new date or note keeps the price the group booked at.
 */
const props = defineProps<{
    open: boolean;
    /** Null for a new reservation. */
    reservation: ReservationDetail | null;
    programs: ProgramRow[];
    agencies: AgencyOption[];
}>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const isEdit = computed(() => props.reservation !== null);

const form = useForm({
    program_id: null as string | null,
    customer_id: null as string | null,
    day: '',
    time: '11:00',
    pax: '' as string | number,
    guest_name: '',
    guest_email: '',
    guest_phone: '',
    allergies: '',
    notes: '',
});

watch(
    () => [props.open, props.reservation?.id],
    () => {
        if (!props.open) return;

        const r = props.reservation;
        const [day, time] = (r?.date ?? '').split(' ');

        form.defaults({
            program_id: r?.program?.id ?? null,
            customer_id: r?.customer?.id ?? null,
            day: day ?? '',
            time: time ?? '11:00',
            pax: r?.pax ?? '',
            guest_name: r?.guest_name ?? '',
            guest_email: r?.guest_email ?? '',
            guest_phone: r?.guest_phone ?? '',
            allergies: r?.allergies ?? '',
            notes: r?.notes ?? '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

/*
  A new booking takes active programs and active agencies only, as the old form did. An edit also
  keeps whatever the booking is already on, even if it has since been retired.
*/
const programOptions = computed<ComboboxOption[]>(() =>
    props.programs
        .filter((p) => p.is_active || p.id === props.reservation?.program?.id)
        .map((p) => ({
            value: p.id,
            label: p.name,
            description: formatMoney(p.base_price.minor, p.base_price.currency),
        })),
);

const agencyOptions = computed<ComboboxOption[]>(() =>
    props.agencies
        .filter((a) => a.is_active || a.id === props.reservation?.customer?.id)
        .map((a) => ({ value: a.id, label: a.name })),
);

/** Legacy bookings can sit off the quarter-hour grid; keep their time selectable. */
const timeOptions = computed(() => {
    const slots = TIME_SLOTS.includes(form.time) || form.time === '' ? TIME_SLOTS : [...TIME_SLOTS, form.time].sort();

    return slots.map((slot) => ({ value: slot, label: slot }));
});

const selectedProgram = computed(() => props.programs.find((p) => p.id === form.program_id) ?? null);

const paxNumber = computed(() => {
    const n = Number(form.pax);

    return Number.isInteger(n) && n > 0 ? n : null;
});

const overMaxPax = computed(
    () => selectedProgram.value?.max_pax != null && paxNumber.value !== null && paxNumber.value > selectedProgram.value.max_pax,
);

/** True when this edit will re-price the booking (or always, for a new one). */
const reprices = computed(() => {
    const r = props.reservation;

    if (r === null) return true;

    return form.program_id !== (r.program?.id ?? null) || form.customer_id !== (r.customer?.id ?? null) || paxNumber.value !== r.pax;
});

/* ---- live quote ----------------------------------------------------------- */

const quote = ref<ReservationQuote | null>(null);
const quoteFailed = ref(false);
let quoteTimer: ReturnType<typeof setTimeout> | undefined;
let quoteSeq = 0;

watch(
    () => [props.open, form.program_id, form.customer_id, paxNumber.value, reprices.value],
    () => {
        clearTimeout(quoteTimer);

        if (!props.open || !reprices.value || form.program_id === null || paxNumber.value === null) {
            quote.value = null;

            return;
        }

        quoteTimer = setTimeout(fetchQuote, 250);
    },
    { immediate: true },
);

async function fetchQuote(): Promise<void> {
    const seq = ++quoteSeq;
    const params = new URLSearchParams({ program_id: form.program_id ?? '', pax: String(paxNumber.value ?? '') });

    if (form.customer_id) params.set('customer_id', form.customer_id);

    try {
        const response = await fetch(`/hospitality/reservations/quote?${params.toString()}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (seq !== quoteSeq) return;

        quoteFailed.value = !response.ok;
        quote.value = response.ok ? ((await response.json()) as ReservationQuote) : null;
    } catch {
        if (seq === quoteSeq) {
            quoteFailed.value = true;
            quote.value = null;
        }
    }
}

const sourceLabel = computed(() => {
    switch (quote.value?.source) {
        case 'agency_price':
            return t('Agency price');
        case 'customer_rebate':
            return t('Customer rebate');
        default:
            return t('Base price');
    }
});

/* ---- submit --------------------------------------------------------------- */

const canSubmit = computed(
    () => !form.processing && form.program_id !== null && form.day !== '' && form.time !== '' && paxNumber.value !== null && form.guest_name.trim() !== '',
);

function blank(value: string): string | null {
    return value.trim() === '' ? null : value.trim();
}

function submit(): void {
    form
        .transform((data) => ({
            program_id: data.program_id,
            customer_id: data.customer_id,
            date: `${data.day} ${data.time}`,
            pax: Number(data.pax),
            guest_name: data.guest_name.trim(),
            guest_email: blank(data.guest_email),
            guest_phone: blank(data.guest_phone),
            allergies: blank(data.allergies),
            notes: blank(data.notes),
        }))
        .submit(isEdit.value ? 'patch' : 'post', isEdit.value ? `/hospitality/reservations/${props.reservation!.id}` : '/hospitality/reservations', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}

/** The date error is reported on `date`; show it under the day field. */
const dateError = computed(() => form.errors['date' as keyof typeof form.errors]);
</script>

<template>
    <SidePanel :open="open" :title="isEdit ? t('Edit reservation') : t('New reservation')" @close="emit('close')">
        <form id="reservation-form" class="flex flex-col gap-5" @submit.prevent="submit">
            <FormField v-slot="{ id, invalid }" :label="t('Program')" required :error="form.errors.program_id">
                <Combobox
                    :id="id"
                    v-model="form.program_id"
                    :options="programOptions"
                    :invalid="invalid"
                    :placeholder="t('Choose a program…')"
                    :empty-text="t('No active programs. Add one on the Programs tab.')"
                />
            </FormField>

            <FormField
                v-slot="{ id, invalid }"
                :label="t('Agency')"
                :hint="t('Leave empty for a walk-in.')"
                :error="form.errors.customer_id"
            >
                <Combobox
                    :id="id"
                    v-model="form.customer_id"
                    :options="agencyOptions"
                    :invalid="invalid"
                    clearable
                    :placeholder="t('Walk-in')"
                    :empty-text="t('No agencies. Mark a customer as an agency first.')"
                />
            </FormField>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_8rem]">
                <FormField v-slot="{ id, invalid }" :label="t('Date')" required :error="dateError">
                    <Input :id="id" v-model="form.day" type="date" :invalid="invalid" />
                </FormField>
                <FormField v-slot="{ id }" :label="t('Time')" required>
                    <Select :id="id" v-model="form.time" :options="timeOptions" />
                </FormField>
            </div>

            <FormField
                v-slot="{ id, invalid }"
                :label="t('Guests')"
                required
                :hint="selectedProgram?.max_pax ? t('Program maximum: :max', { max: selectedProgram.max_pax }) : undefined"
                :error="form.errors.pax"
            >
                <Input :id="id" v-model="form.pax" type="number" min="1" step="1" inputmode="numeric" :invalid="invalid" />
            </FormField>
            <p v-if="overMaxPax" class="-mt-3 text-xs text-destructive" role="status">
                {{ t('More guests than the program maximum. The booking can still be saved.') }}
            </p>

            <!-- What the group will pay. Shown as soon as there is a program and a head count. -->
            <div class="border border-border bg-muted/40 p-3 text-xs">
                <template v-if="!reprices && reservation">
                    <p class="text-muted-foreground">{{ t('Price stays as booked') }}</p>
                    <p class="mt-1 text-sm font-semibold tabular-nums">
                        {{ formatMoney(reservation.price_per_pax.minor, reservation.price_per_pax.currency) }} × {{ reservation.pax }} =
                        {{ formatMoney(reservation.total_amount.minor, reservation.total_amount.currency) }}
                    </p>
                </template>
                <template v-else-if="quote">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-muted-foreground">{{ sourceLabel }}</span>
                        <span v-if="quote.rebate_percent" class="text-muted-foreground tabular-nums">
                            {{ t(':percent% off :base', { percent: quote.rebate_percent, base: formatMoney(quote.base_price.minor, quote.base_price.currency) }) }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm font-semibold tabular-nums">
                        {{ formatMoney(quote.price_per_pax.minor, quote.price_per_pax.currency) }} × {{ paxNumber }} =
                        {{ formatMoney(quote.total.minor, quote.total.currency) }}
                    </p>
                    <p v-if="isEdit" class="mt-1 text-muted-foreground">{{ t('Saving will re-price this reservation.') }}</p>
                </template>
                <p v-else-if="quoteFailed" class="text-destructive">{{ t('Could not work out the price.') }}</p>
                <p v-else class="text-muted-foreground">{{ t('Choose a program and the number of guests to see the price.') }}</p>
            </div>

            <FormField v-slot="{ id, invalid }" :label="t('Guest name')" required :error="form.errors.guest_name">
                <Input :id="id" v-model="form.guest_name" :invalid="invalid" maxlength="255" autocomplete="off" />
            </FormField>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField v-slot="{ id, invalid }" :label="t('Email')" :error="form.errors.guest_email">
                    <Input :id="id" v-model="form.guest_email" type="email" :invalid="invalid" maxlength="255" autocomplete="off" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Phone')" :error="form.errors.guest_phone">
                    <Input :id="id" v-model="form.guest_phone" type="tel" :invalid="invalid" maxlength="64" autocomplete="off" />
                </FormField>
            </div>

            <FormField v-if="isEdit" v-slot="{ id }" :label="t('Allergies')" :error="form.errors.allergies">
                <Textarea :id="id" v-model="form.allergies" :rows="2" :placeholder="t('Optional')" />
            </FormField>

            <FormField v-slot="{ id }" :label="t('Notes')" :error="form.errors.notes">
                <Textarea :id="id" v-model="form.notes" :rows="3" :placeholder="t('Optional')" />
            </FormField>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="reservation-form" :disabled="!canSubmit">
                {{ isEdit ? t('Save changes') : t('Create reservation') }}
            </Button>
        </template>
    </SidePanel>
</template>
