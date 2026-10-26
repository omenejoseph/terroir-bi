<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Search } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import AnalyticsTab from '@/components/hospitality/AnalyticsTab.vue';
import ProgramFormPanel from '@/components/hospitality/ProgramFormPanel.vue';
import ProgramPricesPanel from '@/components/hospitality/ProgramPricesPanel.vue';
import ReservationFormPanel from '@/components/hospitality/ReservationFormPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import DateRangePicker from '@/components/ui/DateRangePicker.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Select from '@/components/ui/Select.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { useUrlTab } from '@/composables/useUrlTab';
import { useVisitPending } from '@/composables/useVisitPending';
import { formatReservationDate, RESERVATION_STATUS_VARIANT } from '@/lib/hospitality';
import { rememberListUrl } from '@/lib/listReturn';
import { formatMoney, formatNumber } from '@/lib/money';
import type { Paginated, SharedProps } from '@/types';
import type {
    AgencyOption,
    AgencyPerformance,
    AgencyPriceRow,
    HospitalityAnalytics,
    HospitalityStats,
    ProgramRow,
    ReservationFilters,
    ReservationRow,
    StatTile,
    StatusOption,
} from '@/types/hospitality';
import type { DateRange, TabItem } from '@/types/ui';

defineOptions({ layout: AppLayout });

/**
 * Hospitality — the old app's "Agencies" page (/dashboard/hospitality): the reservation list with
 * its tiles and filters, the Programs tab with each program's agency price book, and Analytics.
 */
const props = defineProps<{
    tab: 'reservations' | 'programs' | 'analytics';
    filters: ReservationFilters;
    reservations: Paginated<ReservationRow>;
    stats: HospitalityStats;
    statuses: StatusOption[];
    programs: ProgramRow[];
    agencies: AgencyOption[];
    programPrices?: AgencyPriceRow[];
    /** The Analytics tab's two optional props, fetched when it opens. */
    analytics?: HospitalityAnalytics;
    agencyPerformance?: AgencyPerformance;
}>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { can } = useAuth();
const { t } = useTranslations();

const canManage = computed(() => can('hospitality.manage'));

const TABS = ['reservations', 'programs', 'analytics'] as const;
const { tab, select: selectUrlTab } = useUrlTab(TABS, 'reservations');
const TAB_ITEMS = computed<TabItem[]>(() => [
    { value: 'reservations', label: t('Reservations') },
    { value: 'programs', label: t('Programs') },
    { value: 'analytics', label: t('Analytics') },
]);

/* ---- analytics ----------------------------------------------------------------- */

/* Analytics is paid for only when its tab is open; the year and the agency range ride in the URL. */
const compare = ref(new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('perf_compare') === '1');

function loadAnalytics(): void {
    if (props.analytics === undefined) router.reload({ only: ['analytics', 'agencyPerformance'] });
}

function selectTab(value: string): void {
    selectUrlTab(value);
    if (value === 'analytics') loadAnalytics();
}

onMounted(() => {
    if (tab.value === 'analytics') loadAnalytics();
});

function selectYear(year: number): void {
    // A new year resets the agency table to that whole year.
    router.reload({ data: { year, perf_from: undefined, perf_to: undefined }, only: ['analytics', 'agencyPerformance'], replace: true });
}

function selectPerformance(range: { from: string | null; to: string | null; compare: boolean }): void {
    compare.value = range.compare;
    router.reload({
        data: { perf_from: range.from ?? undefined, perf_to: range.to ?? undefined, perf_compare: range.compare ? 1 : undefined },
        only: ['agencyPerformance'],
        replace: true,
    });
}

onMounted(() => rememberListUrl('hospitality'));

/* ---- tiles ------------------------------------------------------------------ */

function deltaHint(tile: StatTile): string {
    const guests = t(':count guests', { count: formatNumber(tile.pax, locale.value) });

    if (tile.delta_percent === null) return guests;

    const arrow = tile.delta_percent >= 0 ? '▲' : '▼';

    return `${guests} · ${arrow} ${t(':percent% vs last year', { percent: Math.abs(tile.delta_percent) })}`;
}

const TILES = computed(() => [
    { key: 'today', label: t('Today'), tile: props.stats.today },
    { key: 'month', label: t('Month to date'), tile: props.stats.month },
    { key: 'year', label: t('Year to date'), tile: props.stats.year },
]);

/* ---- filters ------------------------------------------------------------------ */

const search = ref(props.filters.search ?? '');
const pending = useVisitPending();
const chosenPeriod = ref<string | null>(null);

let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ search: value || undefined, page: 1 }), 300);
});

function reload(overrides: Record<string, unknown>): void {
    router.get(
        '/hospitality',
        {
            search: props.filters.search ?? undefined,
            status: props.filters.status ?? undefined,
            program_id: props.filters.program_id ?? undefined,
            customer_id: props.filters.customer_id ?? undefined,
            period: props.filters.period === 'month' ? undefined : props.filters.period,
            from: props.filters.from ?? undefined,
            to: props.filters.to ?? undefined,
            per_page: props.reservations.meta.per_page,
            ...overrides,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['reservations', 'filters'],
            onSuccess: () => rememberListUrl('hospitality'),
            onFinish: () => (chosenPeriod.value = null),
        },
    );
}

const PERIOD_TABS = computed<TabItem[]>(() => [
    { value: 'today', label: t('Today') },
    { value: 'month', label: t('This Month') },
    { value: 'year', label: t('This Year') },
    { value: 'upcoming', label: t('Upcoming') },
    { value: 'all', label: t('All') },
]);

const usingRange = computed(() => props.filters.from !== null || props.filters.to !== null);
const periodCurrent = computed(() => chosenPeriod.value ?? (usingRange.value ? '' : props.filters.period));
const customRange = computed<DateRange>(() => ({ from: props.filters.from, to: props.filters.to }));

function selectPeriod(value: string): void {
    chosenPeriod.value = value;
    reload({ period: value === 'month' ? undefined : value, from: undefined, to: undefined, page: 1 });
}

function selectRange(range: DateRange): void {
    reload({ period: undefined, from: range.from ?? undefined, to: range.to ?? undefined, page: 1 });
}

const statusOptions = computed(() => [{ value: '', label: t('Any status') }, ...props.statuses]);
const programOptions = computed(() => [{ value: '', label: t('Any program') }, ...props.programs.map((p) => ({ value: p.id, label: p.name }))]);
const agencyOptions = computed(() => [
    { value: '', label: t('Any agency') },
    { value: 'walk_in', label: t('Walk-in') },
    ...props.agencies.map((a) => ({ value: a.id, label: a.name })),
]);

function filterBy(key: 'status' | 'program_id' | 'customer_id', value: string): void {
    reload({ [key]: value === '' ? undefined : value, page: 1 });
}

const statusLabel = (value: string): string => props.statuses.find((s) => s.value === value)?.label ?? value;

const showing = computed(() => {
    const { current_page: current, per_page: per, total } = props.reservations.meta;
    const first = total === 0 ? 0 : (current - 1) * per + 1;

    return t('Showing :from–:to of :total reservations', {
        from: first,
        to: Math.min(current * per, total),
        total: formatNumber(total, locale.value),
    });
});

/* ---- drawers ------------------------------------------------------------------ */

const createOpen = ref(false);
const programFormOpen = ref(false);
const editingProgram = ref<ProgramRow | null>(null);
const pricesProgramId = ref<string | null>(null);
const pricesProgram = computed(() => props.programs.find((p) => p.id === pricesProgramId.value) ?? null);
/* Which program `programPrices` belongs to, so another program's list never shows while this one loads. */
const pricesLoadedFor = ref<string | null>(null);
const shownPrices = computed(() => (pricesLoadedFor.value !== null && pricesLoadedFor.value === pricesProgramId.value ? props.programPrices : undefined));

function newProgram(): void {
    editingProgram.value = null;
    programFormOpen.value = true;
}

function editProgram(program: ProgramRow): void {
    editingProgram.value = program;
    programFormOpen.value = true;
}

/* The price book loads with ?program=…, so a refresh while it is open reopens it. */
function openPrices(program: ProgramRow): void {
    pricesProgramId.value = program.id;
    pricesLoadedFor.value = null;
    router.reload({ data: { program: program.id }, only: ['programPrices'], replace: true, onSuccess: () => (pricesLoadedFor.value = program.id) });
}

function closePrices(): void {
    pricesProgramId.value = null;
    router.reload({ data: { program: undefined }, only: ['programPrices'], replace: true });
}

onMounted(() => {
    const id = new URLSearchParams(window.location.search).get('program');
    const program = props.programs.find((p) => p.id === id);

    if (program) openPrices(program);
});

function programMeta(program: ProgramRow): string {
    const parts: string[] = [];

    if (program.duration_mins) parts.push(t(':minutes min', { minutes: program.duration_mins }));
    if (program.max_pax) parts.push(t('max :count guests', { count: program.max_pax }));

    return parts.join(' · ');
}
</script>

<template>
    <Head :title="t('Agencies')" />
    <div class="space-y-5">
        <PageHeader :title="t('Agencies')" :description="t('Programs, agency prices and reservations')">
            <template #actions>
                <Button v-if="canManage && tab === 'reservations'" size="sm" @click="createOpen = true">
                    <Plus class="size-3.5" :stroke-width="1.5" />
                    {{ t('New reservation') }}
                </Button>
                <Button v-if="canManage && tab === 'programs'" size="sm" @click="newProgram">
                    <Plus class="size-3.5" :stroke-width="1.5" />
                    {{ t('New program') }}
                </Button>
            </template>
        </PageHeader>

        <Tabs :items="TAB_ITEMS" :current="tab" @select="selectTab" />

        <!-- ================= Reservations ================= -->
        <template v-if="tab === 'reservations'">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                <StatCard
                    v-for="item in TILES"
                    :key="item.key"
                    :label="item.label"
                    :value="formatMoney(item.tile.revenue.minor, item.tile.revenue.currency)"
                    :hint="t(':count reservations', { count: item.tile.count }) + ' · ' + deltaHint(item.tile)"
                />
                <StatCard :label="t('Average group')" :value="formatNumber(stats.avg_pax, locale)" :hint="t('guests per reservation this year')" />
                <StatCard
                    :label="t('Confirmed this month')"
                    :value="`${stats.confirmed} / ${stats.booked}`"
                    :hint="stats.booked > 0 ? t(':percent% of bookings', { percent: Math.round((stats.confirmed / stats.booked) * 100) }) : t('No bookings yet')"
                />
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Tabs :items="PERIOD_TABS" :current="periodCurrent" @select="selectPeriod" />
                <DateRangePicker :model-value="customRange" :label="t('Custom')" :max="null" @update:model-value="selectRange" />
            </div>

            <div class="overflow-hidden border border-border bg-card" :aria-busy="pending">
                <div class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                    <div class="relative w-full sm:max-w-[240px]">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                            :stroke-width="1.5"
                        />
                        <input
                            v-model="search"
                            type="search"
                            :placeholder="t('Search guest name…')"
                            :aria-label="t('Search reservations')"
                            class="h-[30px] w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                    </div>
                    <div class="w-full sm:w-40">
                        <label for="filter-status" class="sr-only">{{ t('Status') }}</label>
                        <Select
                            id="filter-status"
                            :model-value="filters.status ?? ''"
                            :options="statusOptions"
                            class="h-[30px] text-xs"
                            @update:model-value="(v) => filterBy('status', v)"
                        />
                    </div>
                    <div class="w-full sm:w-44">
                        <label for="filter-program_id" class="sr-only">{{ t('Program') }}</label>
                        <Select
                            id="filter-program_id"
                            :model-value="filters.program_id ?? ''"
                            :options="programOptions"
                            class="h-[30px] text-xs"
                            @update:model-value="(v) => filterBy('program_id', v)"
                        />
                    </div>
                    <div class="w-full sm:w-44">
                        <label for="filter-customer_id" class="sr-only">{{ t('Agency') }}</label>
                        <Select
                            id="filter-customer_id"
                            :model-value="filters.customer_id ?? ''"
                            :options="agencyOptions"
                            class="h-[30px] text-xs"
                            @update:model-value="(v) => filterBy('customer_id', v)"
                        />
                    </div>
                </div>

                <div class="transition-opacity duration-150" :class="pending ? 'opacity-50 delay-150' : ''">
                    <!-- Table from sm up; stacked cards below it so the page never scrolls sideways. -->
                    <table class="hidden w-full text-xs sm:table">
                        <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Date') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Guest') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Program') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium tracking-[0.08em] uppercase">{{ t('Guests') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium tracking-[0.08em] uppercase">{{ t('Total') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in reservations.data"
                                :key="row.id"
                                class="cursor-pointer border-b border-border align-top transition-colors last:border-b-0 hover:bg-muted/40"
                                @click="router.visit(`/hospitality/reservations/${row.id}`)"
                            >
                                <td class="px-4 py-3 whitespace-nowrap tabular-nums">
                                    <Link :href="`/hospitality/reservations/${row.id}`" class="font-medium text-foreground hover:underline" @click.stop>
                                        {{ formatReservationDate(row.date, locale, true) }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="block font-medium text-foreground">{{ row.guest_name ?? '—' }}</span>
                                    <span class="text-muted-foreground">{{ row.customer?.name ?? t('Walk-in') }}</span>
                                </td>
                                <td class="px-4 py-3">{{ row.program?.name ?? '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ row.pax }}</td>
                                <td class="px-4 py-3 text-right">
                                    <span class="block font-semibold tabular-nums">{{ formatMoney(row.total_amount.minor, row.total_amount.currency) }}</span>
                                    <span class="text-muted-foreground tabular-nums">
                                        {{ t(':price / person', { price: formatMoney(row.price_per_pax.minor, row.price_per_pax.currency) }) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <Badge :variant="RESERVATION_STATUS_VARIANT[row.status]">{{ statusLabel(row.status) }}</Badge>
                                    <span v-if="row.created_by" class="mt-1 block text-muted-foreground">{{ row.created_by }}</span>
                                </td>
                            </tr>
                            <tr v-if="reservations.data.length === 0">
                                <td colspan="6" class="px-4 py-12 text-center text-muted-foreground">{{ t('No reservations in this period.') }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <ul class="divide-y divide-border sm:hidden">
                        <li v-for="row in reservations.data" :key="row.id">
                            <Link :href="`/hospitality/reservations/${row.id}`" class="block px-4 py-3 text-xs">
                                <span class="flex items-start justify-between gap-2">
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-foreground">{{ row.guest_name ?? '—' }}</span>
                                        <span class="block text-muted-foreground tabular-nums">{{ formatReservationDate(row.date, locale, true) }}</span>
                                    </span>
                                    <Badge :variant="RESERVATION_STATUS_VARIANT[row.status]" class="shrink-0">{{ statusLabel(row.status) }}</Badge>
                                </span>
                                <span class="mt-1.5 flex items-baseline justify-between gap-2">
                                    <span class="min-w-0 truncate text-muted-foreground">
                                        {{ row.program?.name ?? '—' }} · {{ row.customer?.name ?? t('Walk-in') }} · {{ t(':count guests', { count: row.pax }) }}
                                    </span>
                                    <span class="shrink-0 font-semibold tabular-nums">{{ formatMoney(row.total_amount.minor, row.total_amount.currency) }}</span>
                                </span>
                            </Link>
                        </li>
                        <li v-if="reservations.data.length === 0" class="px-4 py-12 text-center text-xs text-muted-foreground">
                            {{ t('No reservations in this period.') }}
                        </li>
                    </ul>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                    <p class="text-xs text-muted-foreground">{{ showing }}</p>
                    <Pagination
                        :meta="reservations.meta"
                        @update:page="(n: number) => reload({ page: n })"
                        @update:per-page="(n: number) => reload({ per_page: n, page: 1 })"
                    />
                </div>
            </div>
        </template>

        <!-- ================= Analytics ================= -->
        <AnalyticsTab
            v-else-if="tab === 'analytics'"
            :analytics="analytics"
            :performance="agencyPerformance"
            :compare="compare"
            @year="selectYear"
            @performance="selectPerformance"
        />

        <!-- ================= Programs ================= -->
        <template v-else>
            <div class="border border-border bg-card">
                <p v-if="programs.length === 0" class="px-4 py-12 text-center text-xs text-muted-foreground">
                    {{ t('No programs yet. Add the tastings and tours you sell to groups.') }}
                </p>
                <ul v-else class="divide-y divide-border">
                    <li v-for="program in programs" :key="program.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 text-xs">
                        <div class="min-w-0 flex-1 basis-56">
                            <p class="flex items-center gap-2">
                                <span class="font-medium text-foreground" :class="program.is_active ? '' : 'text-muted-foreground line-through'">{{ program.name }}</span>
                                <Badge v-if="!program.is_active" variant="outline">{{ t('Inactive') }}</Badge>
                            </p>
                            <p v-if="programMeta(program)" class="text-muted-foreground">{{ programMeta(program) }}</p>
                            <p v-if="program.description" class="mt-0.5 line-clamp-2 text-muted-foreground">{{ program.description }}</p>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold tabular-nums">{{ formatMoney(program.base_price.minor, program.base_price.currency) }}</p>
                            <p class="text-muted-foreground">{{ t('per person') }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <Button variant="outline" size="sm" @click="openPrices(program)">
                                {{ t('Agency prices') }}
                                <span class="text-muted-foreground tabular-nums">{{ program.agency_price_count }}</span>
                            </Button>
                            <Button v-if="canManage" variant="ghost" size="sm" @click="editProgram(program)">{{ t('Edit') }}</Button>
                        </div>
                    </li>
                </ul>
            </div>
        </template>
    </div>

    <ReservationFormPanel
        v-if="canManage"
        :open="createOpen"
        :reservation="null"
        :programs="programs"
        :agencies="agencies"
        @close="createOpen = false"
    />
    <ProgramFormPanel v-if="canManage" :open="programFormOpen" :program="editingProgram" @close="programFormOpen = false" />
    <ProgramPricesPanel
        :open="pricesProgramId !== null"
        :program="pricesProgram"
        :prices="shownPrices"
        :agencies="agencies"
        :can-manage="canManage"
        @close="closePrices"
    />
</template>
