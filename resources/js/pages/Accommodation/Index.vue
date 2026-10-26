<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { PencilLine, Plus, Search, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import BookingDetailPanel from '@/components/accommodation/BookingDetailPanel.vue';
import BookingFormPanel from '@/components/accommodation/BookingFormPanel.vue';
import MenuItemFormPanel from '@/components/accommodation/MenuItemFormPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { useUrlTab } from '@/composables/useUrlTab';
import { formatMoney } from '@/lib/money';
import type { SharedProps } from '@/types';
import type { AccommodationOptions, AccommodationOverview, BookingRow, MenuItemRow, VillaOrderRow, VillaRow } from '@/types/accommodation';
import type { MoneyValue } from '@/types/inventory';
import type { TabItem } from '@/types/ui';

defineOptions({ layout: AppLayout });

/**
 * Accommodation (the old app's /dashboard/accommodation) — back office only: overview, bookings,
 * villas, the in-villa menu, and guest orders. The guest portal itself is a later step (docs/21, 3b).
 */
const props = defineProps<{
    tab: string;
    options: AccommodationOptions;
    villas: VillaRow[];
    overview: AccommodationOverview;
    filters: { villa_id: string | null; status: string | null; search: string | null };
    bookings: BookingRow[];
    booking?: BookingRow | null;
    menu: MenuItemRow[];
    orders: VillaOrderRow[];
}>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { can } = useAuth();
const { t } = useTranslations();
const canManage = computed(() => can('hospitality.manage'));

const TABS = ['overview', 'bookings', 'villas', 'menu', 'orders'] as const;
const { tab, select: selectTab } = useUrlTab(TABS, 'overview');
const TAB_ITEMS = computed<TabItem[]>(() => [
    { value: 'overview', label: t('Overview') },
    { value: 'bookings', label: t('Bookings') },
    { value: 'villas', label: t('Villas') },
    { value: 'menu', label: t('Menu') },
    { value: 'orders', label: t('Orders') },
]);

const currency = computed(() => props.overview.deposits_owed.currency);
const money = (m: MoneyValue | null | undefined): string => (m ? formatMoney(m.minor, m.currency) : '—');
const label = (list: { value: string; label: string }[], v: string): string => list.find((o) => o.value === v)?.label ?? v;
const day = (d: string): string => new Date(`${d}T00:00:00`).toLocaleDateString(locale.value, { weekday: 'short', day: '2-digit', month: '2-digit' });

const STATUS_VARIANT: Record<string, 'outline' | 'neutral' | 'solid' | 'warning'> = { UPCOMING: 'outline', CHECKED_IN: 'solid', CHECKED_OUT: 'neutral', CANCELLED: 'warning' };

/* ---- overview timeline ------------------------------------------------------------- */

const DAYS = 30;
const dayIndex = (d: string): number => Math.round((Date.parse(`${d}T00:00:00Z`) - Date.parse(`${props.overview.today}T00:00:00Z`)) / 86_400_000);

function bar(b: BookingRow): { left: string; width: string } {
    const start = Math.max(0, dayIndex(b.check_in));
    const end = Math.min(DAYS, dayIndex(b.check_out));

    return { left: `${(start / DAYS) * 100}%`, width: `${(Math.max(0, end - start) / DAYS) * 100}%` };
}

const timelineRows = computed(() =>
    props.overview.occupancy.map((o) => ({ ...o, bookings: props.overview.timeline.filter((b) => b.villa_id === o.villa_id) })),
);

/* ---- bookings --------------------------------------------------------------------- */

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, (v) => {
    clearTimeout(timer);
    timer = setTimeout(() => reloadBookings({ search: v.trim() || undefined }), 300);
});

function reloadBookings(overrides: Record<string, unknown>): void {
    router.get(
        '/accommodation',
        { tab: 'bookings', villa_id: props.filters.villa_id ?? undefined, status: props.filters.status ?? undefined, search: props.filters.search ?? undefined, ...overrides },
        { preserveState: true, preserveScroll: true, replace: true, only: ['bookings', 'filters'] },
    );
}

const villaFilter = computed(() => [{ value: '', label: t('All villas') }, ...props.villas.map((v) => ({ value: v.id, label: v.name }))]);
const statusFilter = computed(() => [{ value: '', label: t('Any status') }, ...props.options.booking_statuses]);

const bookingFormOpen = ref(false);
const editingBooking = ref<BookingRow | null>(null);
const openBookingId = ref<string | null>(null);

function newBooking(): void {
    editingBooking.value = null;
    bookingFormOpen.value = true;
}

function openBooking(b: BookingRow): void {
    openBookingId.value = b.id;
    router.reload({ data: { booking: b.id }, only: ['booking'], replace: true });
}

function closeBooking(): void {
    openBookingId.value = null;
    router.reload({ data: { booking: undefined }, only: ['booking'], replace: true });
}

function editBooking(b: BookingRow): void {
    editingBooking.value = b;
    bookingFormOpen.value = true;
}

onMounted(() => {
    const id = new URLSearchParams(window.location.search).get('booking');
    if (id) {
        openBookingId.value = id;
        router.reload({ data: { booking: id }, only: ['booking'], replace: true });
    }
});

const shownBooking = computed(() => (props.booking && props.booking.id === openBookingId.value ? props.booking : undefined));

/* ---- villas ----------------------------------------------------------------------- */

const villaFormOpen = ref(false);
const villaForm = useForm({ name: '', address: '' });

function createVilla(): void {
    villaForm
        .transform((d) => ({ name: d.name.trim(), address: d.address.trim() || null }))
        .post('/accommodation/villas', { onSuccess: () => (villaFormOpen.value = false) });
}

/* ---- menu ------------------------------------------------------------------------- */

const menuFormOpen = ref(false);
const editingMenuItem = ref<MenuItemRow | null>(null);
const menuCategories = computed(() => [...new Set(props.menu.map((m) => m.category))].sort());

const menuByVilla = computed(() =>
    props.villas
        .map((v) => ({
            villa: v,
            categories: menuCategories.value
                .map((c) => ({ category: c, items: props.menu.filter((m) => m.villa_id === v.id && m.category === c) }))
                .filter((g) => g.items.length > 0),
        }))
        .filter((g) => g.categories.length > 0),
);

function newMenuItem(): void {
    editingMenuItem.value = null;
    menuFormOpen.value = true;
}

function editMenuItem(item: MenuItemRow): void {
    editingMenuItem.value = item;
    menuFormOpen.value = true;
}

async function deleteMenuItem(item: MenuItemRow): Promise<void> {
    const ok = await confirmDialog({ title: t('Remove :name?', { name: item.name }), confirmLabel: t('Remove'), tone: 'danger' });
    if (ok) router.delete(`/accommodation/menu/${item.id}`, { preserveScroll: true });
}

/* ---- orders ----------------------------------------------------------------------- */

function updateOrder(order: VillaOrderRow, data: { status?: string; paid?: boolean }): void {
    router.patch(`/accommodation/orders/${order.id}`, data, { preserveScroll: true, only: ['orders'] });
}
</script>

<template>
    <Head :title="t('Accommodation')" />
    <div class="space-y-5">
        <PageHeader :title="t('Accommodation')" :description="t('Villas, bookings, the in-villa menu and guest orders')">
            <template v-if="canManage" #actions>
                <Button v-if="tab === 'overview' || tab === 'bookings'" size="sm" @click="newBooking">
                    <Plus class="size-3.5" :stroke-width="1.5" />
                    {{ t('New booking') }}
                </Button>
                <Button v-else-if="tab === 'villas'" size="sm" @click="villaFormOpen = true">
                    <Plus class="size-3.5" :stroke-width="1.5" />
                    {{ t('New villa') }}
                </Button>
                <Button v-else-if="tab === 'menu'" size="sm" @click="newMenuItem">
                    <Plus class="size-3.5" :stroke-width="1.5" />
                    {{ t('New menu item') }}
                </Button>
            </template>
        </PageHeader>

        <Tabs :items="TAB_ITEMS" :current="tab" @select="selectTab" />

        <!-- ================= Overview ================= -->
        <template v-if="tab === 'overview'">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard :label="t('Deposits owed')" :value="money(overview.deposits_owed)" :hint="t('Unpaid deposits on upcoming stays')" />
                <StatCard :label="t('Arriving today')" :value="String(overview.arrivals_today.length)" />
                <StatCard :label="t('Leaving today')" :value="String(overview.departures_today.length)" />
                <StatCard :label="t('Arriving this week')" :value="String(overview.arrivals_week.length)" />
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <section v-for="list in [
                    { key: 'in', title: t('Check-ins'), today: overview.arrivals_today, week: overview.arrivals_week },
                    { key: 'out', title: t('Check-outs'), today: overview.departures_today, week: overview.departures_week },
                ]" :key="list.key" class="border border-border bg-card">
                    <h3 class="border-b border-border px-4 py-3 text-sm font-semibold">{{ list.title }}</h3>
                    <p v-if="list.today.length + list.week.length === 0" class="px-4 py-6 text-center text-xs text-muted-foreground">{{ t('Nothing in the next 7 days.') }}</p>
                    <ul v-else class="divide-y divide-border text-xs">
                        <li v-for="b in [...list.today, ...list.week]" :key="b.id">
                            <button type="button" class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left hover:bg-muted/40" @click="openBooking(b)">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{ b.guest_name }}</span>
                                    <span class="text-muted-foreground">{{ b.villa?.name }} · {{ t(':count guests', { count: b.guests }) }}</span>
                                </span>
                                <span class="shrink-0 tabular-nums" :class="list.today.includes(b) ? 'font-semibold' : 'text-muted-foreground'">
                                    {{ list.today.includes(b) ? t('Today') : day(list.key === 'in' ? b.check_in : b.check_out) }}
                                </span>
                            </button>
                        </li>
                    </ul>
                </section>
            </div>

            <section class="border border-border bg-card p-4">
                <h3 class="text-sm font-semibold">{{ t('Next 30 days') }}</h3>
                <p v-if="timelineRows.length === 0" class="mt-3 text-xs text-muted-foreground">{{ t('No active villas.') }}</p>
                <div v-else class="mt-3 space-y-3">
                    <div v-for="row in timelineRows" :key="row.villa_id" class="text-xs">
                        <div class="mb-1 flex justify-between gap-2">
                            <span class="font-medium">{{ row.name }}</span>
                            <span class="text-muted-foreground tabular-nums">{{ t(':percent% booked', { percent: row.percent }) }}</span>
                        </div>
                        <div class="relative h-6 bg-muted">
                            <button
                                v-for="b in row.bookings"
                                :key="b.id"
                                type="button"
                                class="absolute top-0 h-full truncate border-x border-card bg-foreground px-1 text-left text-3xs text-background"
                                :style="bar(b)"
                                :title="`${b.guest_name} · ${b.check_in} → ${b.check_out}`"
                                @click="openBooking(b)"
                            >
                                {{ b.guest_name }}
                            </button>
                        </div>
                    </div>
                    <p class="flex justify-between text-3xs text-muted-foreground"><span>{{ day(overview.today) }}</span><span>{{ day(overview.window_end) }}</span></p>
                </div>
            </section>
        </template>

        <!-- ================= Bookings ================= -->
        <template v-else-if="tab === 'bookings'">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative w-full sm:max-w-[240px]">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground" :stroke-width="1.5" />
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="t('Search guest…')"
                        :aria-label="t('Search bookings')"
                        class="h-[30px] w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                </div>
                <div class="w-full sm:w-44">
                    <label for="bk-villa" class="sr-only">{{ t('Villa') }}</label>
                    <Select id="bk-villa" :model-value="filters.villa_id ?? ''" :options="villaFilter" class="h-[30px] text-xs" @update:model-value="(v) => reloadBookings({ villa_id: v || undefined })" />
                </div>
                <div class="w-full sm:w-44">
                    <label for="bk-status" class="sr-only">{{ t('Status') }}</label>
                    <Select id="bk-status" :model-value="filters.status ?? ''" :options="statusFilter" class="h-[30px] text-xs" @update:model-value="(v) => reloadBookings({ status: v || undefined })" />
                </div>
            </div>

            <div class="border border-border bg-card">
                <p v-if="bookings.length === 0" class="px-4 py-12 text-center text-xs text-muted-foreground">{{ t('No bookings.') }}</p>
                <ul v-else class="divide-y divide-border">
                    <li v-for="b in bookings" :key="b.id">
                        <button type="button" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-left text-xs hover:bg-muted/40" @click="openBooking(b)">
                            <span class="w-44 shrink-0 tabular-nums">{{ day(b.check_in) }} → {{ day(b.check_out) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ b.guest_name }}</span>
                                <span class="text-muted-foreground">{{ b.villa?.name }} · {{ t(':count nights', { count: b.nights }) }} · {{ t(':count guests', { count: b.guests }) }}</span>
                            </span>
                            <span class="text-right">
                                <span class="block font-semibold tabular-nums">{{ money(b.total_amount) }}</span>
                                <span v-if="b.deposit_amount" :class="b.deposit_paid ? 'text-muted-foreground' : 'text-destructive'">
                                    {{ t('Deposit :amount', { amount: money(b.deposit_amount) }) }} · {{ b.deposit_paid ? t('Paid') : t('Unpaid') }}
                                </span>
                            </span>
                            <Badge :variant="STATUS_VARIANT[b.status]">{{ label(options.booking_statuses, b.status) }}</Badge>
                        </button>
                    </li>
                </ul>
            </div>
        </template>

        <!-- ================= Villas ================= -->
        <template v-else-if="tab === 'villas'">
            <div class="border border-border bg-card">
                <p v-if="villas.length === 0" class="px-4 py-12 text-center text-xs text-muted-foreground">{{ t('No villas yet.') }}</p>
                <ul v-else class="divide-y divide-border">
                    <li v-for="v in villas" :key="v.id">
                        <Link :href="`/accommodation/villas/${v.id}`" class="flex items-center justify-between gap-3 px-4 py-3 text-xs hover:bg-muted/40">
                            <span class="min-w-0">
                                <span class="block font-medium" :class="v.is_active ? '' : 'text-muted-foreground line-through'">{{ v.name }}</span>
                                <span class="text-muted-foreground">{{ v.address ?? '—' }}</span>
                            </span>
                            <Badge v-if="!v.is_active" variant="outline">{{ t('Inactive') }}</Badge>
                        </Link>
                    </li>
                </ul>
            </div>
        </template>

        <!-- ================= Menu ================= -->
        <template v-else-if="tab === 'menu'">
            <p v-if="menuByVilla.length === 0" class="border border-border bg-card px-4 py-12 text-center text-xs text-muted-foreground">{{ t('No menu items yet.') }}</p>
            <section v-for="g in menuByVilla" :key="g.villa.id" class="border border-border bg-card">
                <h3 class="border-b border-border px-4 py-3 text-sm font-semibold">{{ g.villa.name }}</h3>
                <div v-for="c in g.categories" :key="c.category" class="border-b border-border last:border-b-0">
                    <p class="bg-muted/40 px-4 py-1.5 text-3xs tracking-[0.08em] text-muted-foreground uppercase">{{ c.category }}</p>
                    <ul class="divide-y divide-border">
                        <li v-for="m in c.items" :key="m.id" class="flex items-center gap-3 px-4 py-2.5 text-xs">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium" :class="m.is_active ? '' : 'text-muted-foreground line-through'">{{ m.name }}</span>
                                <span v-if="m.description" class="line-clamp-1 text-muted-foreground">{{ m.description }}</span>
                            </span>
                            <span class="text-right tabular-nums">
                                <span class="block font-semibold">{{ money(m.price) }}</span>
                                <span class="text-muted-foreground">{{ m.vat_included ? t('incl. VAT') : t('excl. VAT') }}</span>
                            </span>
                            <span v-if="canManage" class="flex shrink-0 gap-1">
                                <button type="button" class="p-1 text-muted-foreground hover:text-foreground" :aria-label="t('Edit')" @click="editMenuItem(m)">
                                    <PencilLine class="size-3.5" :stroke-width="1.5" />
                                </button>
                                <button type="button" class="p-1 text-muted-foreground hover:text-destructive" :aria-label="t('Remove')" @click="deleteMenuItem(m)">
                                    <Trash2 class="size-3.5" :stroke-width="1.5" />
                                </button>
                            </span>
                        </li>
                    </ul>
                </div>
            </section>
        </template>

        <!-- ================= Orders ================= -->
        <template v-else>
            <div class="border border-border bg-card">
                <p v-if="orders.length === 0" class="px-4 py-12 text-center text-xs text-muted-foreground">{{ t('No guest orders yet.') }}</p>
                <ul v-else class="divide-y divide-border">
                    <li v-for="o in orders" :key="o.id" class="flex flex-wrap items-start gap-x-4 gap-y-2 px-4 py-3 text-xs">
                        <span class="min-w-0 flex-1 basis-56">
                            <span class="block font-medium">{{ o.booking?.guest_name ?? '—' }} <span class="font-normal text-muted-foreground">· {{ o.booking?.villa_name }}</span></span>
                            <span class="block text-muted-foreground">{{ o.items.map((i) => `${i.quantity}× ${i.name}`).join(', ') }}</span>
                            <span v-if="o.delivery_date" class="text-muted-foreground">{{ t('Deliver :when', { when: o.delivery_date }) }}</span>
                        </span>
                        <span class="font-semibold tabular-nums">{{ money(o.total) }}</span>
                        <div v-if="canManage" class="w-36">
                            <label :for="`order-status-${o.id}`" class="sr-only">{{ t('Status') }}</label>
                            <Select :id="`order-status-${o.id}`" :model-value="o.status" :options="options.order_statuses" class="h-[30px] text-xs" @update:model-value="(v) => updateOrder(o, { status: v })" />
                        </div>
                        <label v-if="canManage" class="inline-flex items-center gap-2">
                            <input type="checkbox" :checked="o.paid_at !== null" @change="updateOrder(o, { paid: ($event.target as HTMLInputElement).checked })" />
                            {{ t('Paid') }}
                        </label>
                        <Badge v-else :variant="o.paid_at ? 'success' : 'outline'">{{ o.paid_at ? t('Paid') : t('Unpaid') }}</Badge>
                    </li>
                </ul>
            </div>
        </template>
    </div>

    <BookingFormPanel v-if="canManage" :open="bookingFormOpen" :booking="editingBooking" :villas="villas" :currency="currency" @close="bookingFormOpen = false" />
    <BookingDetailPanel
        :open="openBookingId !== null"
        :booking="shownBooking"
        :options="options"
        :can-manage="canManage"
        @close="closeBooking"
        @edit="(b) => { closeBooking(); editBooking(b); }"
    />
    <MenuItemFormPanel v-if="canManage" :open="menuFormOpen" :item="editingMenuItem" :villas="villas" :categories="menuCategories" @close="menuFormOpen = false" />

    <SidePanel v-if="canManage" :open="villaFormOpen" :title="t('New villa')" @close="villaFormOpen = false">
        <form id="villa-create-form" class="flex flex-col gap-5" @submit.prevent="createVilla">
            <FormField v-slot="{ id, invalid }" :label="t('Name')" required :error="villaForm.errors.name">
                <Input :id="id" v-model="villaForm.name" :invalid="invalid" maxlength="255" />
            </FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Address')" :error="villaForm.errors.address">
                <Input :id="id" v-model="villaForm.address" :invalid="invalid" maxlength="255" :placeholder="t('Optional')" />
            </FormField>
            <p class="text-xs text-muted-foreground">{{ t('Rates, portal texts and sections are added on the villa’s own page next.') }}</p>
        </form>
        <template #footer>
            <Button variant="outline" @click="villaFormOpen = false">{{ t('Cancel') }}</Button>
            <Button type="submit" form="villa-create-form" :disabled="villaForm.processing || villaForm.name.trim() === ''">{{ t('Add villa') }}</Button>
        </template>
    </SidePanel>
</template>
