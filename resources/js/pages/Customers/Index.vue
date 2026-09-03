<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    CircleCheck,
    CircleSlash,
    Download,
    Filter,
    PencilLine,
    Plus,
    Search,
    Trash2,
    X,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CustomerFormPanel from '@/components/customers/CustomerFormPanel.vue';
import MergeCustomersPanel from '@/components/customers/MergeCustomersPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { usePopover } from '@/composables/usePopover';
import { CUSTOMER_TYPES, customerTypeLabel } from '@/lib/customers';
import { formatMoney, formatNumber } from '@/lib/money';
import type { Customer, CustomerFilters, PricingTierSummary } from '@/types/customers';
import type { Paginated, SharedProps } from '@/types';
import type { MenuItem, TabItem } from '@/types/ui';

/**
 * Customers list, following Figma 230:2395: page header with Export and New
 * Customer, the module tabs, a filter row, then a selectable table with a
 * floating action bar and a full pager.
 *
 * Rebate deserves a note. The column shows the rebate that ACTUALLY applies —
 * `effective_rebate_percent`, which is the customer's own if they have one and
 * the tier's otherwise (pricing engine §5.3). Showing `rebate_percent` would
 * read "0%" for every customer who inherits from a tier, which is exactly the
 * question this column exists to answer.
 */
const props = defineProps<{
    customers: Paginated<Customer>;
    filters: CustomerFilters;
    /** Only present once the Tier filter has asked for them. */
    tiers?: PricingTierSummary[];
}>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { can } = useAuth();

const search = ref(props.filters.search ?? '');
const selected = ref<string[]>([]);
const formOpen = ref(false);
const editing = ref<Customer | null>(null);
const mergeOpen = ref(false);

/*
  One popover at a time, with proper dismissal: the three filter menus share an
  anchor so a click anywhere outside the filter row closes whichever is open.
  Without this a menu stays open until you pick something, which on a filter
  row means you cannot back out of a decision.
*/
const filterRow = ref<HTMLElement | null>(null);
const { open: filterOpen, close: closeFilter, show: showFilter } = usePopover(filterRow);
const openFilter = ref<'status' | 'tier' | 'type' | null>(null);

watch(filterOpen, (isOpen) => {
    if (!isOpen) openFilter.value = null;
});

let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ search: value || undefined }), 300);
});

function reload(overrides: Record<string, unknown>): void {
    router.get(
        '/customers',
        {
            search: props.filters.search ?? undefined,
            is_active: props.filters.is_active ?? undefined,
            pricing_tier_id: props.filters.pricing_tier_id ?? undefined,
            customer_type: props.filters.customer_type ?? undefined,
            // Carried forward so changing a filter does not silently reset the
            // page size back to the server default.
            per_page: props.customers.meta.per_page,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['customers', 'filters'] },
    );
}

const MODULE_TABS: TabItem[] = [
    { label: 'Customers', href: '/customers' },
    { label: 'Analytics', href: '/customers-analytics' },
];

/** Tiers are only fetched when the Tier filter is first opened. */
function toggleFilter(which: 'status' | 'tier' | 'type'): void {
    if (openFilter.value === which) {
        closeFilter();

        return;
    }

    openFilter.value = which;
    showFilter();

    if (which === 'tier' && props.tiers === undefined) {
        router.reload({ only: ['tiers'] });
    }
}

function setFilter(key: string, value: unknown): void {
    closeFilter();
    reload({ [key]: value ?? undefined });
}

const activeFilterCount = computed(
    () =>
        [props.filters.is_active, props.filters.pricing_tier_id, props.filters.customer_type].filter(
            (v) => v !== null,
        ).length,
);

/* ---- selection ------------------------------------------------------- */

const pageIds = computed(() => props.customers.data.map((c) => c.id));
const allSelected = computed(
    () => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)),
);

function toggleAll(): void {
    selected.value = allSelected.value ? [] : [...pageIds.value];
}

function toggleOne(id: string): void {
    selected.value = selected.value.includes(id)
        ? selected.value.filter((s) => s !== id)
        : [...selected.value, id];
}

const selectedCustomers = computed(() =>
    props.customers.data.filter((c) => selected.value.includes(c.id)),
);

/* ---- formatting ------------------------------------------------------ */

const rebate = (customer: Customer): string => {
    const value = Number.parseFloat(customer.effective_rebate_percent);

    return Number.isFinite(value) ? `${Number(value.toFixed(2))}%` : '—';
};

const revenue = (customer: Customer): string =>
    customer.revenue_minor === null ? '—' : formatMoney(customer.revenue_minor, 'EUR', locale.value);

function goToPage(page: number): void {
    reload({ page });
}

function setPerPage(perPage: number): void {
    // Changing the page size while sitting on, say, page 4 of a now-shorter
    // list would silently show nothing — reset to page 1 with it.
    reload({ per_page: perPage, page: 1 });
}

function edit(customer: Customer): void {
    editing.value = customer;
    formOpen.value = true;
}

function create(): void {
    editing.value = null;
    formOpen.value = true;
}

/*
  The row's `⋯` menu (Figma `230:3755`). What it offers is a property of the
  member, not of the row, so it is built once: every row shows the same two
  entries, and each is drawn only where the capability backing it is held.
*/
const rowActions = computed<MenuItem[]>(() => {
    const items: MenuItem[] = [];

    if (can('customers.manage')) items.push({ key: 'edit', label: 'Edit', icon: PencilLine });
    if (can('customers.delete')) {
        items.push({ key: 'delete', label: 'Delete', icon: Trash2, destructive: true });
    }

    return items;
});

function onRowAction(key: string, customer: Customer): void {
    if (key === 'edit') {
        edit(customer);

        return;
    }

    if (key === 'delete') destroy(customer);
}

/**
 * Deleting is destructive and the menu gives it no second step of its own, so
 * it asks here. The wording names both outcomes because the server picks
 * between them: a customer with orders is deactivated, never deleted, since
 * their orders are the revenue record (DeleteCustomerAction).
 */
function destroy(customer: Customer): void {
    const message =
        `Delete ${customer.company_name}? ` +
        'Customers with orders are deactivated instead, so their history survives.';

    if (!confirm(message)) return;

    router.delete(`/customers/${customer.id}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Customers">
        <div class="space-y-5">
            <PageHeader title="Customers">
                <template #actions>
                    <!-- @todo Export all. No customer export endpoint exists. -->
                    <Button variant="outline" size="sm">
                        <Download class="size-3.5" :stroke-width="1.5" />
                        Export all
                    </Button>
                    <Button v-if="can('customers.manage')" size="sm" @click="create">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        New Customer
                    </Button>
                </template>
            </PageHeader>

            <Tabs :items="MODULE_TABS" current="Customers" />

            <!-- Filter row -->
            <div ref="filterRow" class="flex flex-wrap items-center gap-2">
                <div class="relative w-full max-w-[280px]">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                        :stroke-width="1.5"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Filter customers…"
                        aria-label="Filter customers"
                        class="h-8 w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                </div>

                <!-- The design draws these as dashed-outline buttons until a
                     value is chosen, which is what makes an applied filter
                     legible at a glance. -->
                <div class="relative">
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 border px-2.5 text-xs transition-colors"
                        :class="
                            filters.is_active === null
                                ? 'border-dashed border-border text-foreground hover:border-foreground/40'
                                : 'border-primary bg-primary text-primary-foreground'
                        "
                        @click="toggleFilter('status')"
                    >
                        <Filter class="size-3.5" :stroke-width="1.5" />
                        Status
                        <template v-if="filters.is_active !== null">
                            · {{ filters.is_active ? 'Active' : 'Inactive' }}
                        </template>
                    </button>
                    <div
                        v-if="filterOpen && openFilter === 'status'"
                        class="absolute top-9 left-0 z-20 min-w-40 border border-border bg-card p-1 shadow-lg"
                    >
                        <button
                            v-for="option in [
                                { label: 'Any status', value: null },
                                { label: 'Active', value: true },
                                { label: 'Inactive', value: false },
                            ]"
                            :key="String(option.value)"
                            type="button"
                            class="block w-full px-2 py-1.5 text-left text-xs hover:bg-muted"
                            @click="setFilter('is_active', option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>

                <div class="relative">
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 border px-2.5 text-xs transition-colors"
                        :class="
                            filters.pricing_tier_id === null
                                ? 'border-dashed border-border text-foreground hover:border-foreground/40'
                                : 'border-primary bg-primary text-primary-foreground'
                        "
                        @click="toggleFilter('tier')"
                    >
                        <Filter class="size-3.5" :stroke-width="1.5" />
                        Tier
                    </button>
                    <div
                        v-if="filterOpen && openFilter === 'tier'"
                        class="absolute top-9 left-0 z-20 max-h-64 min-w-48 overflow-y-auto border border-border bg-card p-1 shadow-lg"
                    >
                        <button
                            type="button"
                            class="block w-full px-2 py-1.5 text-left text-xs hover:bg-muted"
                            @click="setFilter('pricing_tier_id', null)"
                        >
                            Any tier
                        </button>
                        <button
                            v-for="tier in tiers ?? []"
                            :key="tier.id"
                            type="button"
                            class="block w-full px-2 py-1.5 text-left text-xs hover:bg-muted"
                            @click="setFilter('pricing_tier_id', tier.id)"
                        >
                            {{ tier.name }}
                        </button>
                        <p v-if="(tiers ?? []).length === 0" class="px-2 py-1.5 text-xs text-muted-foreground">
                            No pricing tiers yet.
                        </p>
                    </div>
                </div>

                <div class="relative">
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 border px-2.5 text-xs transition-colors"
                        :class="
                            filters.customer_type === null
                                ? 'border-dashed border-border text-foreground hover:border-foreground/40'
                                : 'border-primary bg-primary text-primary-foreground'
                        "
                        @click="toggleFilter('type')"
                    >
                        <Filter class="size-3.5" :stroke-width="1.5" />
                        Type
                        <template v-if="filters.customer_type">
                            · {{ customerTypeLabel(filters.customer_type) }}
                        </template>
                    </button>
                    <div
                        v-if="filterOpen && openFilter === 'type'"
                        class="absolute top-9 left-0 z-20 min-w-44 border border-border bg-card p-1 shadow-lg"
                    >
                        <button
                            type="button"
                            class="block w-full px-2 py-1.5 text-left text-xs hover:bg-muted"
                            @click="setFilter('customer_type', null)"
                        >
                            Any type
                        </button>
                        <button
                            v-for="type in CUSTOMER_TYPES"
                            :key="type.value"
                            type="button"
                            class="block w-full px-2 py-1.5 text-left text-xs hover:bg-muted"
                            @click="setFilter('customer_type', type.value)"
                        >
                            {{ type.label }}
                        </button>
                    </div>
                </div>

                <button
                    v-if="activeFilterCount > 0"
                    type="button"
                    class="inline-flex h-8 items-center gap-1 px-2 text-xs text-muted-foreground hover:text-foreground"
                    @click="reload({ is_active: undefined, pricing_tier_id: undefined, customer_type: undefined })"
                >
                    <X class="size-3.5" :stroke-width="1.5" />
                    Clear
                </button>
            </div>

            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[60rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="w-10 px-4 py-2.5">
                                    <Checkbox
                                        :model-value="allSelected"
                                        label="Select all customers on this page"
                                        hide-label
                                        @update:model-value="toggleAll"
                                    />
                                </th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Company <span aria-hidden="true">↑</span></th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Contact</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Type</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Tier</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">
                                    Rebate
                                </th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">
                                    Orders
                                </th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">
                                    Revenue
                                </th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Status</th>
                                <th scope="col" class="w-16 px-4 py-2.5"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="row in customers.data"
                                :key="row.id"
                                class="border-b border-border transition-colors last:border-b-0 hover:bg-muted/40"
                            >
                                <td class="px-4 py-3">
                                    <Checkbox
                                        :model-value="selected.includes(row.id)"
                                        :label="`Select ${row.company_name}`"
                                        hide-label
                                        @update:model-value="toggleOne(row.id)"
                                    />
                                </td>
                                <td class="px-4 py-3">
                                    <Link
                                        :href="`/customers/${row.id}`"
                                        class="font-medium text-foreground hover:underline"
                                    >
                                        {{ row.company_name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ row.contact_name ?? '—' }}</td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ customerTypeLabel(row.customer_type) }}
                                </td>
                                <td class="px-4 py-3">
                                    <Badge v-if="row.pricing_tier" variant="outline">{{ row.pricing_tier.name }}</Badge>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ rebate(row) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ row.order_count === null ? '—' : formatNumber(row.order_count, locale) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ revenue(row) }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                                        <CircleCheck
                                            v-if="row.is_active"
                                            class="size-3.5 text-muted-foreground"
                                            :stroke-width="1.5"
                                        />
                                        <CircleSlash v-else class="size-3.5 text-muted-foreground" :stroke-width="1.5" />
                                        {{ row.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end">
                                        <DropdownMenu
                                            v-if="rowActions.length > 0"
                                            :items="rowActions"
                                            :label="`Actions for ${row.company_name}`"
                                            @select="onRowAction($event, row)"
                                        />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="customers.data.length === 0">
                                <td colspan="10" class="px-4 py-12 text-center text-muted-foreground">
                                    No customers match these filters.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                    <p class="text-xs text-muted-foreground">
                        {{ selected.length }} of {{ formatNumber(customers.meta.total, locale) }} row(s) selected
                    </p>

                    <Pagination
                        :meta="customers.meta"
                        @update:page="goToPage"
                        @update:per-page="setPerPage"
                    />
                </div>
            </div>
        </div>

        <!-- Floating selection bar (Figma 230:2395) -->
        <div
            v-if="selected.length > 0"
            class="fixed bottom-6 left-1/2 z-40 flex -translate-x-1/2 items-center gap-1 border border-border bg-card px-3 py-2 shadow-lg"
        >
            <span class="px-2 text-xs">{{ selected.length }} selected</span>
            <!-- @todo Export selection. Same missing endpoint as Export all. -->
            <Button variant="ghost" size="sm">
                <Download class="size-3.5" :stroke-width="1.5" />
                Export
            </Button>
            <Button
                v-if="can('customers.delete')"
                variant="ghost"
                size="sm"
                :disabled="selected.length < 2"
                @click="mergeOpen = true"
            >
                Merge
            </Button>
            <button
                type="button"
                class="ml-1 p-1.5 text-muted-foreground hover:text-foreground"
                aria-label="Clear selection"
                @click="selected = []"
            >
                <X class="size-3.5" :stroke-width="1.5" />
            </button>
        </div>

        <CustomerFormPanel
            v-if="can('customers.manage')"
            :open="formOpen"
            :customer="editing"
            @close="formOpen = false"
        />

        <MergeCustomersPanel
            v-if="can('customers.delete')"
            :open="mergeOpen"
            :customers="selectedCustomers"
            @close="mergeOpen = false"
            @merged="((selected = []), (mergeOpen = false))"
        />
    </AppLayout>
</template>
