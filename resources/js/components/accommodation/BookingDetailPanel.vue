<script setup lang="ts">
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney } from '@/lib/money';
import type { SharedProps } from '@/types';
import type { AccommodationOptions, BookingRow } from '@/types/accommodation';
import type { MoneyValue } from '@/types/inventory';

/** One stay: guest, dates, money, its guest orders, and the status / edit / delete controls. */
const props = defineProps<{ open: boolean; booking: BookingRow | null | undefined; options: AccommodationOptions; canManage: boolean }>();
const emit = defineEmits<{ close: []; edit: [booking: BookingRow] }>();

const { t } = useTranslations();
const locale = computed(() => usePage<SharedProps>().props.locale);
const money = (m: MoneyValue | null | undefined): string => (m ? formatMoney(m.minor, m.currency) : '—');
const label = (list: { value: string; label: string }[], v: string): string => list.find((o) => o.value === v)?.label ?? v;
const day = (d: string): string => new Date(`${d}T00:00:00`).toLocaleDateString(locale.value, { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });

function setStatus(status: string): void {
    if (!props.booking) return;
    router.patch(`/accommodation/bookings/${props.booking.id}/status`, { status }, { preserveScroll: true, only: ['booking', 'bookings', 'overview'] });
}

async function destroy(): Promise<void> {
    if (!props.booking) return;
    const ok = await confirmDialog({
        title: t('Delete this booking?'),
        description: t('The booking and its guest orders are removed for good. To keep them, cancel it instead.'),
        confirmLabel: t('Delete'),
        tone: 'danger',
    });
    if (ok) router.delete(`/accommodation/bookings/${props.booking.id}`);
}

function setOrderPaid(orderId: string, paid: boolean): void {
    router.patch(`/accommodation/orders/${orderId}`, { paid }, { preserveScroll: true, only: ['booking', 'orders'] });
}
</script>

<template>
    <SidePanel :open="open" :title="booking?.guest_name ?? t('Booking')" :subtitle="booking?.villa?.name" @close="emit('close')">
        <p v-if="!booking" class="text-xs text-muted-foreground">{{ t('Loading…') }}</p>
        <div v-else class="flex flex-col gap-5 text-xs">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3">
                <div>
                    <dt class="text-muted-foreground">{{ t('Check-in') }}</dt>
                    <dd class="mt-0.5">{{ day(booking.check_in) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('Check-out') }}</dt>
                    <dd class="mt-0.5">{{ day(booking.check_out) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('Nights') }}</dt>
                    <dd class="mt-0.5 tabular-nums">{{ booking.nights }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('Guests') }}</dt>
                    <dd class="mt-0.5 tabular-nums">{{ booking.guests }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('Agreed total') }}</dt>
                    <dd class="mt-0.5 font-semibold tabular-nums">{{ money(booking.total_amount) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('From the rates') }}</dt>
                    <dd class="mt-0.5 tabular-nums">{{ money(booking.auto_calculated_amount) }}<template v-if="booking.rebate_percent > 0"> ({{ booking.rebate_percent }}% {{ t('rebate') }})</template></dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('Deposit') }}</dt>
                    <dd class="mt-0.5 tabular-nums">
                        {{ money(booking.deposit_amount) }}
                        <Badge v-if="booking.deposit_amount" :variant="booking.deposit_paid ? 'success' : 'warning'" class="ml-1">{{ booking.deposit_paid ? t('Paid') : t('Unpaid') }}</Badge>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('Contact') }}</dt>
                    <dd class="mt-0.5 break-all">{{ [booking.guest_email, booking.guest_phone].filter(Boolean).join(' · ') || '—' }}</dd>
                </div>
                <div v-if="booking.notes" class="col-span-2">
                    <dt class="text-muted-foreground">{{ t('Notes') }}</dt>
                    <dd class="mt-0.5 whitespace-pre-line">{{ booking.notes }}</dd>
                </div>
            </dl>

            <div v-if="canManage" class="flex flex-wrap items-center gap-2">
                <div class="w-40">
                    <label for="booking-status" class="sr-only">{{ t('Status') }}</label>
                    <Select id="booking-status" :model-value="booking.status" :options="options.booking_statuses" class="h-[30px] text-xs" @update:model-value="setStatus" />
                </div>
                <Button variant="outline" size="sm" @click="emit('edit', booking)">{{ t('Edit') }}</Button>
                <Button variant="outline" size="sm" class="border-destructive/40 text-destructive hover:bg-destructive/10" @click="destroy">{{ t('Delete') }}</Button>
            </div>
            <Badge v-else :variant="booking.status === 'CANCELLED' ? 'warning' : 'neutral'">{{ label(options.booking_statuses, booking.status) }}</Badge>

            <section>
                <h3 class="mb-2 text-sm font-semibold">{{ t('Guest orders') }}</h3>
                <p v-if="!booking.orders || booking.orders.length === 0" class="text-muted-foreground">{{ t('No orders from this stay.') }}</p>
                <ul v-else class="divide-y divide-border border border-border">
                    <li v-for="o in booking.orders" :key="o.id" class="space-y-1 px-3 py-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-medium">{{ label(options.order_statuses, o.status) }}<template v-if="o.delivery_date"> · {{ o.delivery_date }}</template></span>
                            <span class="font-semibold tabular-nums">{{ money(o.total) }}</span>
                        </div>
                        <p class="text-muted-foreground">{{ o.items.map((i) => `${i.quantity}× ${i.name}`).join(', ') }}</p>
                        <label v-if="canManage" class="inline-flex items-center gap-2">
                            <input type="checkbox" :checked="o.paid_at !== null" @change="setOrderPaid(o.id, ($event.target as HTMLInputElement).checked)" />
                            {{ t('Paid') }}
                        </label>
                    </li>
                </ul>
            </section>
        </div>
    </SidePanel>
</template>
