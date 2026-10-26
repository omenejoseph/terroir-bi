<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, PencilLine, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import ReservationFormPanel from '@/components/hospitality/ReservationFormPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useAuth } from '@/composables/useAuth';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { formatReservationDate, RESERVATION_STATUS_VARIANT } from '@/lib/hospitality';
import { listReturnUrl } from '@/lib/listReturn';
import { formatMoney } from '@/lib/money';
import type { SharedProps } from '@/types';
import type { AgencyOption, ProgramRow, ReservationDetail, ReservationStatus, StatusOption } from '@/types/hospitality';

defineOptions({ layout: AppLayout });

/**
 * One reservation (the old app's reservation detail): who, when, what they pay and why, the
 * status card that walks the booking along its flow, and the timeline every change and note
 * lands on. Editing opens the same drawer as "New reservation" rather than editing in place.
 */
const props = defineProps<{
    reservation: ReservationDetail;
    statuses: StatusOption[];
    programs?: ProgramRow[];
    agencies?: AgencyOption[];
}>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { can } = useAuth();
const { t } = useTranslations();

const canManage = computed(() => can('hospitality.manage'));
const r = computed(() => props.reservation);

const statusLabel = (value: string): string => props.statuses.find((s) => s.value === value)?.label ?? value;

const money = (value: { minor: number; currency: string } | null): string => (value ? formatMoney(value.minor, value.currency) : '—');

const title = computed(() => `${r.value.guest_name ?? t('Reservation')} · ${t(':count guests', { count: r.value.pax })}`);

/* ---- edit ------------------------------------------------------------------- */

const editOpen = ref(false);

function openEdit(): void {
    editOpen.value = true;

    if (props.programs === undefined || props.agencies === undefined) {
        router.reload({ only: ['programs', 'agencies'] });
    }
}

/* ---- status + notes ----------------------------------------------------------- */

const statusForm = useForm({ status: '' as ReservationStatus | '', note: '' });

function changeStatus(status: ReservationStatus): void {
    statusForm
        .transform((data) => ({ status, note: data.note.trim() === '' ? null : data.note.trim() }))
        .post(`/hospitality/reservations/${r.value.id}/status`, {
            preserveScroll: true,
            onSuccess: () => statusForm.reset(),
        });
}

async function cancelReservation(): Promise<void> {
    const confirmed = await confirmDialog({
        title: t('Cancel this reservation?'),
        description: statusForm.note.trim() === '' ? t('Add a note first if you want to record why.') : t('The note is kept as the cancel reason.'),
        confirmLabel: t('Cancel reservation'),
        cancelLabel: t('Keep it'),
        tone: 'danger',
    });

    if (confirmed) changeStatus('CANCELLED');
}

const noteForm = useForm({ note: '' });

function addNote(): void {
    noteForm.post(`/hospitality/reservations/${r.value.id}/notes`, {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
}

/* ---- delete ------------------------------------------------------------------- */

async function destroy(): Promise<void> {
    const confirmed = await confirmDialog({
        title: t('Delete this reservation?'),
        description: t('The reservation and its timeline are removed for good.'),
        confirmLabel: t('Delete'),
        tone: 'danger',
    });

    if (confirmed) router.delete(`/hospitality/reservations/${r.value.id}`);
}

function historyTime(at: string | null): string {
    return at === null ? '' : new Date(at).toLocaleString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const revenuePerPerson = computed(() => (r.value.pax > 0 ? Math.round(r.value.total_amount.minor / r.value.pax) : 0));
</script>

<template>
    <Head :title="title" />
    <div class="space-y-5">
        <Link
            :href="listReturnUrl('hospitality', '/hospitality')"
            class="inline-flex items-center gap-2 text-xs text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-3.5" :stroke-width="1.5" />
            {{ t('Reservations') }}
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="flex flex-wrap items-center gap-2 text-xl font-semibold text-foreground">
                    <span class="truncate">{{ title }}</span>
                    <Badge :variant="RESERVATION_STATUS_VARIANT[r.status]">{{ statusLabel(r.status) }}</Badge>
                </h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ formatReservationDate(r.date, locale, true) }} · {{ r.program?.name ?? '—' }}
                </p>
            </div>
            <div v-if="canManage" class="flex shrink-0 items-center gap-2">
                <Button variant="outline" size="sm" @click="openEdit">
                    <PencilLine class="size-3.5" :stroke-width="1.5" />
                    {{ t('Edit') }}
                </Button>
                <Button
                    v-if="r.deletable"
                    variant="outline"
                    size="sm"
                    class="border-destructive/40 text-destructive hover:bg-destructive/10"
                    @click="destroy"
                >
                    <Trash2 class="size-3.5" :stroke-width="1.5" />
                    {{ t('Delete') }}
                </Button>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard :label="t('Guests')" :value="String(r.pax)" :hint="r.program?.max_pax ? t('Program maximum: :max', { max: r.program.max_pax }) : undefined" />
            <StatCard :label="t('Price per person')" :value="money(r.price_per_pax)" />
            <StatCard :label="t('Total')" :value="money(r.total_amount)" />
            <StatCard :label="t('Agency')" :value="r.customer?.name ?? t('Walk-in')" />
        </div>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-5">
                <!-- Details -->
                <section class="border border-border bg-card">
                    <h3 class="border-b border-border px-4 py-3 text-sm font-semibold">{{ t('Details') }}</h3>
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 px-4 py-4 text-xs sm:grid-cols-2">
                        <div>
                            <dt class="text-muted-foreground">{{ t('Date') }}</dt>
                            <dd class="mt-0.5 tabular-nums">{{ formatReservationDate(r.date, locale, true) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('Program') }}</dt>
                            <dd class="mt-0.5">
                                {{ r.program?.name ?? '—' }}
                                <span v-if="r.program && !r.program.is_active" class="text-muted-foreground">({{ t('Inactive') }})</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('Guest') }}</dt>
                            <dd class="mt-0.5">{{ r.guest_name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('Agency') }}</dt>
                            <dd class="mt-0.5">
                                <Link v-if="r.customer && can('customers.view')" :href="`/customers/${r.customer.id}`" class="hover:underline">{{ r.customer.name }}</Link>
                                <template v-else>{{ r.customer?.name ?? t('Walk-in') }}</template>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('Email') }}</dt>
                            <dd class="mt-0.5 break-all">
                                <a v-if="r.guest_email" :href="`mailto:${r.guest_email}`" class="hover:underline">{{ r.guest_email }}</a>
                                <template v-else>—</template>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('Phone') }}</dt>
                            <dd class="mt-0.5">
                                <a v-if="r.guest_phone" :href="`tel:${r.guest_phone}`" class="hover:underline">{{ r.guest_phone }}</a>
                                <template v-else>—</template>
                            </dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-muted-foreground">{{ t('Allergies') }}</dt>
                            <dd class="mt-0.5 whitespace-pre-line">{{ r.allergies ?? '—' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-muted-foreground">{{ t('Notes') }}</dt>
                            <dd class="mt-0.5 whitespace-pre-line">{{ r.notes ?? '—' }}</dd>
                        </div>
                        <div v-if="r.cancel_reason" class="sm:col-span-2">
                            <dt class="text-muted-foreground">{{ t('Cancel reason') }}</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-destructive">{{ r.cancel_reason }}</dd>
                        </div>
                        <div v-if="r.created_by">
                            <dt class="text-muted-foreground">{{ t('Created by') }}</dt>
                            <dd class="mt-0.5">{{ r.created_by }}</dd>
                        </div>
                    </dl>
                </section>

                <!-- Profitability -->
                <section class="border border-border bg-card">
                    <h3 class="border-b border-border px-4 py-3 text-sm font-semibold">{{ t('Profitability') }}</h3>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-3 px-4 py-4 text-xs sm:grid-cols-4">
                        <div>
                            <dt class="text-muted-foreground">{{ t('At base price') }}</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums">{{ money(r.profitability.list_total) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('Rebate') }}</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums">
                                {{ r.profitability.rebate_percent === null ? '—' : `${r.profitability.rebate_percent}%` }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('Revenue') }}</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums">{{ money(r.total_amount) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('Revenue per person') }}</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums">{{ formatMoney(revenuePerPerson, r.total_amount.currency) }}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <div class="space-y-5">
                <!-- Status -->
                <section v-if="canManage" class="border border-border bg-card">
                    <h3 class="border-b border-border px-4 py-3 text-sm font-semibold">{{ t('Change status') }}</h3>
                    <div class="space-y-3 px-4 py-4">
                        <label for="status-note" class="sr-only">{{ t('Note') }}</label>
                        <Textarea
                            id="status-note"
                            v-model="statusForm.note"
                            :rows="2"
                            :placeholder="t('Note (optional)')"
                        />
                        <p v-if="statusForm.errors.status" class="text-xs text-destructive">{{ statusForm.errors.status }}</p>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="r.next_status"
                                size="sm"
                                :disabled="statusForm.processing"
                                @click="changeStatus(r.next_status)"
                            >
                                {{ t('Mark as :status', { status: statusLabel(r.next_status) }) }}
                            </Button>
                            <Button
                                v-if="r.status !== 'CANCELLED'"
                                variant="outline"
                                size="sm"
                                class="border-destructive/40 text-destructive hover:bg-destructive/10"
                                :disabled="statusForm.processing"
                                @click="cancelReservation"
                            >
                                {{ t('Cancel reservation') }}
                            </Button>
                            <p v-if="!r.next_status && r.status === 'CANCELLED'" class="text-xs text-muted-foreground">
                                {{ t('This reservation is cancelled.') }}
                            </p>
                        </div>
                    </div>
                </section>

                <!-- Timeline -->
                <section class="border border-border bg-card">
                    <h3 class="border-b border-border px-4 py-3 text-sm font-semibold">{{ t('Timeline') }}</h3>
                    <ol class="space-y-4 px-4 py-4 text-xs">
                        <li v-for="line in [...r.history].reverse()" :key="line.id" class="relative pl-4">
                            <span class="absolute top-1 left-0 size-2 bg-foreground" aria-hidden="true" />
                            <p class="flex flex-wrap items-center gap-2">
                                <Badge :variant="RESERVATION_STATUS_VARIANT[line.status]">{{ statusLabel(line.status) }}</Badge>
                                <span class="text-muted-foreground tabular-nums">{{ historyTime(line.at) }}</span>
                            </p>
                            <p v-if="line.note" class="mt-1 whitespace-pre-line text-foreground">{{ line.note }}</p>
                            <p v-if="line.by" class="mt-0.5 text-muted-foreground">{{ line.by }}</p>
                        </li>
                    </ol>
                    <form v-if="canManage" class="space-y-2 border-t border-border px-4 py-3" @submit.prevent="addNote">
                        <label for="timeline-note" class="sr-only">{{ t('Add note') }}</label>
                        <Textarea id="timeline-note" v-model="noteForm.note" :rows="2" :placeholder="t('Add a note…')" />
                        <p v-if="noteForm.errors.note" class="text-xs text-destructive">{{ noteForm.errors.note }}</p>
                        <div class="flex justify-end">
                            <Button type="submit" size="sm" variant="outline" :disabled="noteForm.processing || noteForm.note.trim() === ''">
                                {{ t('Add note') }}
                            </Button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>

    <ReservationFormPanel
        v-if="canManage"
        :open="editOpen"
        :reservation="reservation"
        :programs="programs ?? []"
        :agencies="agencies ?? []"
        @close="editOpen = false"
    />
</template>
