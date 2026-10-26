<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { PencilLine, Plus, Printer, Search, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PrintSheet from '@/components/cellar/PrintSheet.vue';
import KitchenAdjustPanel from '@/components/kitchen/KitchenAdjustPanel.vue';
import KitchenItemFormPanel from '@/components/kitchen/KitchenItemFormPanel.vue';
import KitchenWorkOrderPanel from '@/components/kitchen/KitchenWorkOrderPanel.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import DateRangePicker from '@/components/ui/DateRangePicker.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Select from '@/components/ui/Select.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { useUrlTab } from '@/composables/useUrlTab';
import { formatMoney, formatQuantity } from '@/lib/money';
import type { SharedProps } from '@/types';
import type {
    KitchenCheckDetail,
    KitchenCheckLine,
    KitchenCheckSummary,
    KitchenItem,
    KitchenMovement,
    KitchenOptions,
    KitchenWorkOrder,
    KitchenWorkOrderFilters,
} from '@/types/kitchen';
import type { DateRange, TabItem } from '@/types/ui';

defineOptions({ layout: AppLayout });

/**
 * Kitchen (the old app's /dashboard/kitchen): the kitchen's own stock with its adjustments, the
 * stock-take ("Inventura") and prep tickets, each printable.
 */
const props = defineProps<{
    tab: string;
    options: KitchenOptions;
    items: KitchenItem[];
    itemMovements?: KitchenMovement[];
    checks: KitchenCheckSummary[];
    check?: KitchenCheckDetail | null;
    workOrderFilters: KitchenWorkOrderFilters;
    workOrders: KitchenWorkOrder[];
}>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { can } = useAuth();
const { t } = useTranslations();
const canManage = computed(() => can('kitchen.manage'));

const TABS = ['inventory', 'inventura', 'work-orders'] as const;
const { tab, select: selectTab } = useUrlTab(TABS, 'inventory');
const TAB_ITEMS = computed<TabItem[]>(() => [
    { value: 'inventory', label: t('Stock') },
    { value: 'inventura', label: t('Stock-take') },
    { value: 'work-orders', label: t('Prep tickets') },
]);

const label = (list: { value: string; label: string }[], value: string | null): string => list.find((o) => o.value === value)?.label ?? value ?? '—';
const qty = (v: string | null): string => (v === null ? '—' : formatQuantity(v, locale.value));

/* ================= Stock ================= */

const search = ref('');
const location = ref('');
const showInactive = ref(false);

const locationOptions = computed(() => [{ value: '', label: t('All locations') }, ...props.options.locations]);

const visibleItems = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.items.filter(
        (i) =>
            (showInactive.value || i.is_active) &&
            (location.value === '' || i.location === location.value) &&
            (term === '' || i.name.toLowerCase().includes(term) || (i.group ?? '').toLowerCase().includes(term)),
    );
});

/** Grouped by category, in the category order the options list. */
const grouped = computed(() =>
    props.options.categories
        .map((c) => ({ category: c, items: visibleItems.value.filter((i) => i.category === c.value) }))
        .filter((g) => g.items.length > 0),
);

const lowCount = computed(() => props.items.filter((i) => i.is_active && i.is_low).length);

const itemFormOpen = ref(false);
const editingItem = ref<KitchenItem | null>(null);
const adjustItemId = ref<string | null>(null);
const adjustItem = computed(() => props.items.find((i) => i.id === adjustItemId.value) ?? null);
const movementsFor = ref<string | null>(null);

function newItem(): void {
    editingItem.value = null;
    itemFormOpen.value = true;
}

function editItem(item: KitchenItem): void {
    editingItem.value = item;
    itemFormOpen.value = true;
}

function openAdjust(item: KitchenItem): void {
    adjustItemId.value = item.id;
    movementsFor.value = null;
    router.reload({ data: { item: item.id }, only: ['itemMovements'], replace: true, onSuccess: () => (movementsFor.value = item.id) });
}

function closeAdjust(): void {
    adjustItemId.value = null;
    router.reload({ data: { item: undefined }, only: ['itemMovements'], replace: true });
}

async function deleteItem(item: KitchenItem): Promise<void> {
    const ok = await confirmDialog({
        title: t('Delete :name?', { name: item.name }),
        description: t('The item and its stock history are removed for good. To keep the history, make it inactive instead.'),
        confirmLabel: t('Delete'),
        tone: 'danger',
    });

    if (ok) router.delete(`/kitchen/items/${item.id}`, { preserveScroll: true });
}

const stockSheet = ref<InstanceType<typeof PrintSheet> | null>(null);

/* ================= Stock-take ================= */

const today = new Date().toISOString().slice(0, 10);
const startForm = ref({ date: today, location: '', note: '' });
const starting = ref(false);

function startCheck(): void {
    starting.value = true;
    router.post(
        '/kitchen/checks',
        { date: startForm.value.date, location: startForm.value.location || null, note: startForm.value.note.trim() || null },
        { onFinish: () => (starting.value = false) },
    );
}

const openCheckId = ref<string | null>(null);

function openCheck(id: string): void {
    openCheckId.value = id;
    router.reload({ data: { check: id }, only: ['check'], replace: true });
}

function closeCheck(): void {
    openCheckId.value = null;
    router.reload({ data: { check: undefined }, only: ['check'], replace: true });
}

onMounted(() => {
    const id = new URLSearchParams(window.location.search).get('check');
    if (id) openCheck(id);
});

const shownCheck = computed(() => (props.check && props.check.id === openCheckId.value ? props.check : null));

/** Count inputs keep their own text so typing is never interrupted by a server round-trip. */
const counts = ref<Record<string, string>>({});
watch(
    () => props.check,
    (check) => {
        counts.value = Object.fromEntries((check?.items ?? []).map((l) => [l.id, l.physical_count === null ? '' : String(Number(l.physical_count))]));
    },
    { immediate: true },
);

function saveCount(line: KitchenCheckLine): void {
    if (!shownCheck.value) return;

    const raw = counts.value[line.id] ?? '';
    const current = line.physical_count === null ? '' : String(Number(line.physical_count));
    if (raw === current) return;

    router.patch(
        `/kitchen/checks/${shownCheck.value.id}/lines/${line.id}`,
        { physical_count: raw.trim() === '' ? null : Number.parseFloat(raw.replace(',', '.')) },
        { preserveScroll: true, preserveState: true, only: ['check', 'checks'] },
    );
}

const checkGroups = computed(() =>
    props.options.categories
        .map((c) => ({ category: c, lines: (shownCheck.value?.items ?? []).filter((l) => l.category === c.value) }))
        .filter((g) => g.lines.length > 0),
);

async function completeCheck(): Promise<void> {
    if (!shownCheck.value) return;

    const uncounted = shownCheck.value.lines - shownCheck.value.counted;
    const ok = await confirmDialog({
        title: t('Complete this stock-take?'),
        description:
            uncounted > 0
                ? t('Counted items are set to their counted figure. :count items were not counted and keep their stock.', { count: uncounted })
                : t('Every counted item is set to its counted figure.'),
        confirmLabel: t('Complete'),
    });

    if (ok) router.post(`/kitchen/checks/${shownCheck.value.id}/complete`, {}, { preserveScroll: true, only: ['check', 'checks', 'items'] });
}

async function deleteCheck(): Promise<void> {
    if (!shownCheck.value) return;

    const ok = await confirmDialog({ title: t('Delete this stock-take?'), description: t('The counts entered so far are discarded. Stock is not changed.'), confirmLabel: t('Delete'), tone: 'danger' });

    if (ok) {
        openCheckId.value = null;
        router.delete(`/kitchen/checks/${shownCheck.value.id}`);
    }
}

const countSheet = ref<InstanceType<typeof PrintSheet> | null>(null);

/* ================= Prep tickets ================= */

const woStatusOptions = computed(() => [{ value: '', label: t('Any status') }, ...props.options.work_order_statuses]);
const woAssignee = ref(props.workOrderFilters.assigned_to ?? '');
let woTimer: ReturnType<typeof setTimeout> | undefined;

function reloadWorkOrders(overrides: Record<string, unknown>): void {
    router.get(
        '/kitchen',
        {
            tab: 'work-orders',
            wo_status: props.workOrderFilters.status ?? undefined,
            wo_assignee: props.workOrderFilters.assigned_to ?? undefined,
            wo_from: props.workOrderFilters.from ?? undefined,
            wo_to: props.workOrderFilters.to ?? undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['workOrders', 'workOrderFilters'] },
    );
}

watch(woAssignee, (v) => {
    clearTimeout(woTimer);
    woTimer = setTimeout(() => reloadWorkOrders({ wo_assignee: v.trim() || undefined }), 300);
});

const woRange = computed<DateRange>(() => ({ from: props.workOrderFilters.from, to: props.workOrderFilters.to }));

const woFormOpen = ref(false);
const editingWo = ref<KitchenWorkOrder | null>(null);

function newWorkOrder(): void {
    editingWo.value = null;
    woFormOpen.value = true;
}

function editWorkOrder(order: KitchenWorkOrder): void {
    editingWo.value = order;
    woFormOpen.value = true;
}

function setWoStatus(order: KitchenWorkOrder, status: string): void {
    router.patch(`/kitchen/work-orders/${order.id}/status`, { status }, { preserveScroll: true, only: ['workOrders'] });
}

async function deleteWorkOrder(order: KitchenWorkOrder): Promise<void> {
    const ok = await confirmDialog({ title: t('Delete :name?', { name: order.title }), confirmLabel: t('Delete'), tone: 'danger' });

    if (ok) router.delete(`/kitchen/work-orders/${order.id}`, { preserveScroll: true });
}

const WO_VARIANT: Record<string, 'outline' | 'neutral' | 'success' | 'warning'> = {
    PLANNED: 'outline',
    IN_PROGRESS: 'neutral',
    COMPLETED: 'success',
    CANCELLED: 'warning',
};

const printedWo = ref<KitchenWorkOrder | null>(null);
const woSheet = ref<InstanceType<typeof PrintSheet> | null>(null);

async function printWorkOrder(order: KitchenWorkOrder): Promise<void> {
    printedWo.value = order;
    await nextTick();
    woSheet.value?.print();
}

const dateLabel = (d: string): string => new Date(`${d}T00:00:00`).toLocaleDateString(locale.value, { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
</script>

<template>
    <Head :title="t('Kitchen')" />
    <div class="space-y-5">
        <PageHeader :title="t('Kitchen')" :description="t('Kitchen stock, stock-takes and prep tickets')">
            <template #actions>
                <template v-if="tab === 'inventory'">
                    <Button variant="outline" size="sm" @click="stockSheet?.print()">
                        <Printer class="size-3.5" :stroke-width="1.5" />
                        {{ t('Print') }}
                    </Button>
                    <Button v-if="canManage" size="sm" @click="newItem">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        {{ t('New item') }}
                    </Button>
                </template>
                <Button v-else-if="tab === 'work-orders' && canManage" size="sm" @click="newWorkOrder">
                    <Plus class="size-3.5" :stroke-width="1.5" />
                    {{ t('New prep ticket') }}
                </Button>
            </template>
        </PageHeader>

        <Tabs :items="TAB_ITEMS" :current="tab" @select="selectTab" />

        <!-- ================= Stock ================= -->
        <template v-if="tab === 'inventory'">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative w-full sm:max-w-[240px]">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground" :stroke-width="1.5" />
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="t('Search items…')"
                        :aria-label="t('Search items')"
                        class="h-[30px] w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                </div>
                <div class="w-full sm:w-44">
                    <label for="kitchen-location" class="sr-only">{{ t('Location') }}</label>
                    <Select id="kitchen-location" v-model="location" :options="locationOptions" class="h-[30px] text-xs" />
                </div>
                <Checkbox v-model="showInactive" :label="t('Show inactive')" />
                <p v-if="lowCount > 0" class="ml-auto text-xs text-destructive">{{ t(':count items below minimum', { count: lowCount }) }}</p>
            </div>

            <p v-if="grouped.length === 0" class="border border-border bg-card px-4 py-12 text-center text-xs text-muted-foreground">
                {{ items.length === 0 ? t('No kitchen items yet.') : t('Nothing matches.') }}
            </p>

            <section v-for="g in grouped" :key="g.category.value" class="border border-border bg-card">
                <h3 class="border-b border-border px-4 py-3 text-sm font-semibold">{{ g.category.label }} <span class="font-normal text-muted-foreground">· {{ g.items.length }}</span></h3>
                <ul class="divide-y divide-border">
                    <li v-for="item in g.items" :key="item.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5 text-xs">
                        <button type="button" class="min-w-0 flex-1 basis-48 text-left" @click="openAdjust(item)">
                            <span class="font-medium text-foreground" :class="item.is_active ? '' : 'text-muted-foreground line-through'">{{ item.name }}</span>
                            <span class="block text-muted-foreground">
                                {{ [item.group, label(options.locations, item.location), item.supplier].filter(Boolean).join(' · ') }}
                            </span>
                        </button>
                        <span class="text-right tabular-nums" :class="item.is_low ? 'font-semibold text-destructive' : 'font-semibold'">
                            {{ qty(item.current_stock) }} {{ item.unit }}
                            <span v-if="item.min_stock !== null" class="block font-normal text-muted-foreground">{{ t('min :qty', { qty: qty(item.min_stock) }) }}</span>
                        </span>
                        <span v-if="item.cost_per_unit" class="w-20 text-right text-muted-foreground tabular-nums">
                            {{ formatMoney(item.cost_per_unit.minor, item.cost_per_unit.currency) }}/{{ item.unit }}
                        </span>
                        <span v-if="canManage" class="flex shrink-0 items-center gap-1">
                            <button type="button" class="p-1 text-muted-foreground hover:text-foreground" :aria-label="t('Edit')" @click="editItem(item)">
                                <PencilLine class="size-3.5" :stroke-width="1.5" />
                            </button>
                            <button type="button" class="p-1 text-muted-foreground hover:text-destructive" :aria-label="t('Delete')" @click="deleteItem(item)">
                                <Trash2 class="size-3.5" :stroke-width="1.5" />
                            </button>
                        </span>
                    </li>
                </ul>
            </section>
        </template>

        <!-- ================= Stock-take ================= -->
        <template v-else-if="tab === 'inventura'">
            <template v-if="!openCheckId">
                <form v-if="canManage" class="flex flex-wrap items-end gap-3 border border-border bg-card p-4" @submit.prevent="startCheck">
                    <div>
                        <label for="check-date" class="mb-1 block text-xs text-muted-foreground">{{ t('Date') }}</label>
                        <Input id="check-date" v-model="startForm.date" type="date" class="h-[30px] text-xs" />
                    </div>
                    <div class="w-44">
                        <label for="check-location" class="mb-1 block text-xs text-muted-foreground">{{ t('Location') }}</label>
                        <Select id="check-location" v-model="startForm.location" :options="locationOptions" class="h-[30px] text-xs" />
                    </div>
                    <div class="min-w-48 flex-1">
                        <label for="check-note" class="mb-1 block text-xs text-muted-foreground">{{ t('Note') }}</label>
                        <Input id="check-note" v-model="startForm.note" maxlength="1000" class="h-[30px] text-xs" :placeholder="t('Optional')" />
                    </div>
                    <Button type="submit" size="sm" :disabled="starting || startForm.date === ''">{{ t('Start stock-take') }}</Button>
                </form>

                <section class="border border-border bg-card">
                    <h3 class="border-b border-border px-4 py-3 text-sm font-semibold">{{ t('Stock-takes') }}</h3>
                    <p v-if="checks.length === 0" class="px-4 py-8 text-center text-xs text-muted-foreground">{{ t('No stock-takes yet.') }}</p>
                    <ul v-else class="divide-y divide-border">
                        <li v-for="c in checks" :key="c.id">
                            <button type="button" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-left text-xs hover:bg-muted/40" @click="openCheck(c.id)">
                                <span class="w-32 tabular-nums">{{ dateLabel(c.date) }}</span>
                                <span class="min-w-0 flex-1 text-muted-foreground">
                                    {{ c.location ? label(options.locations, c.location) : t('All locations') }}<template v-if="c.note"> · {{ c.note }}</template>
                                </span>
                                <span class="tabular-nums text-muted-foreground">{{ t(':counted of :lines counted', { counted: c.counted, lines: c.lines }) }}</span>
                                <Badge :variant="c.status === 'COMPLETED' ? 'success' : 'outline'">{{ c.status === 'COMPLETED' ? t('Completed') : t('In progress') }}</Badge>
                            </button>
                        </li>
                    </ul>
                </section>
            </template>

            <template v-else>
                <p v-if="!shownCheck" class="text-xs text-muted-foreground">{{ t('Loading…') }}</p>
                <template v-else>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button variant="ghost" size="sm" @click="closeCheck">← {{ t('All stock-takes') }}</Button>
                        <h3 class="text-sm font-semibold">
                            {{ dateLabel(shownCheck.date) }} · {{ shownCheck.location ? label(options.locations, shownCheck.location) : t('All locations') }}
                        </h3>
                        <Badge :variant="shownCheck.status === 'COMPLETED' ? 'success' : 'outline'">
                            {{ shownCheck.status === 'COMPLETED' ? t('Completed') : t('In progress') }}
                        </Badge>
                        <span class="text-xs text-muted-foreground">{{ t(':counted of :lines counted', { counted: shownCheck.counted, lines: shownCheck.lines }) }}</span>
                        <div class="ml-auto flex gap-2">
                            <Button variant="outline" size="sm" @click="countSheet?.print()">
                                <Printer class="size-3.5" :stroke-width="1.5" />
                                {{ t('Print count sheet') }}
                            </Button>
                            <template v-if="canManage && shownCheck.status === 'IN_PROGRESS'">
                                <Button variant="outline" size="sm" class="border-destructive/40 text-destructive hover:bg-destructive/10" @click="deleteCheck">{{ t('Delete') }}</Button>
                                <Button size="sm" @click="completeCheck">{{ t('Complete stock-take') }}</Button>
                            </template>
                        </div>
                    </div>

                    <section v-for="g in checkGroups" :key="g.category.value" class="border border-border bg-card">
                        <h3 class="border-b border-border px-4 py-3 text-sm font-semibold">{{ g.category.label }}</h3>
                        <ul class="divide-y divide-border">
                            <li v-for="line in g.lines" :key="line.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2 text-xs">
                                <span class="min-w-0 flex-1 basis-40 font-medium">{{ line.name }}</span>
                                <span class="w-28 text-right tabular-nums text-muted-foreground">{{ t('System: :qty', { qty: qty(line.system_stock) }) }} {{ line.unit }}</span>
                                <span class="w-28">
                                    <label :for="`count-${line.id}`" class="sr-only">{{ t('Counted :name', { name: line.name ?? '' }) }}</label>
                                    <Input
                                        :id="`count-${line.id}`"
                                        v-model="counts[line.id]"
                                        type="number"
                                        min="0"
                                        step="0.001"
                                        inputmode="decimal"
                                        class="h-[30px] text-right text-xs"
                                        :disabled="!canManage || shownCheck.status !== 'IN_PROGRESS'"
                                        @blur="saveCount(line)"
                                        @keydown.enter.prevent="saveCount(line)"
                                    />
                                </span>
                                <span
                                    class="w-20 text-right tabular-nums"
                                    :class="line.difference === null ? 'text-muted-foreground' : Number(line.difference) < 0 ? 'text-destructive' : Number(line.difference) > 0 ? 'font-semibold' : 'text-muted-foreground'"
                                >
                                    {{ line.difference === null ? '—' : `${Number(line.difference) > 0 ? '+' : ''}${qty(line.difference)}` }}
                                </span>
                            </li>
                        </ul>
                    </section>
                </template>
            </template>
        </template>

        <!-- ================= Prep tickets ================= -->
        <template v-else>
            <div class="flex flex-wrap items-center gap-2">
                <div class="w-full sm:w-44">
                    <label for="wo-status" class="sr-only">{{ t('Status') }}</label>
                    <Select
                        id="wo-status"
                        :model-value="workOrderFilters.status ?? ''"
                        :options="woStatusOptions"
                        class="h-[30px] text-xs"
                        @update:model-value="(v) => reloadWorkOrders({ wo_status: v || undefined })"
                    />
                </div>
                <div class="w-full sm:w-48">
                    <label for="wo-assignee" class="sr-only">{{ t('Assigned to') }}</label>
                    <Input id="wo-assignee" v-model="woAssignee" class="h-[30px] text-xs" :placeholder="t('Assigned to…')" />
                </div>
                <DateRangePicker
                    :model-value="woRange"
                    :label="t('Date range')"
                    :max="null"
                    @update:model-value="(r: DateRange) => reloadWorkOrders({ wo_from: r.from ?? undefined, wo_to: r.to ?? undefined })"
                />
            </div>

            <p v-if="workOrders.length === 0" class="border border-border bg-card px-4 py-12 text-center text-xs text-muted-foreground">{{ t('No prep tickets.') }}</p>

            <ul v-else class="grid gap-3 md:grid-cols-2">
                <li v-for="order in workOrders" :key="order.id" class="flex flex-col gap-2 border border-border bg-card p-4 text-xs">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-foreground">{{ order.title }}</p>
                            <p class="text-muted-foreground">{{ dateLabel(order.date) }}<template v-if="order.assigned_to"> · {{ order.assigned_to }}</template></p>
                        </div>
                        <Badge :variant="WO_VARIANT[order.status]">{{ label(options.work_order_statuses, order.status) }}</Badge>
                    </div>
                    <ul class="space-y-0.5">
                        <li v-for="line in order.items" :key="line.id" class="flex gap-2">
                            <span class="w-20 shrink-0 text-right font-semibold tabular-nums">{{ qty(line.quantity) }} {{ line.unit ?? '' }}</span>
                            <span>{{ line.name ?? '—' }}<span v-if="line.notes" class="text-muted-foreground"> — {{ line.notes }}</span></span>
                        </li>
                    </ul>
                    <p v-if="order.notes" class="whitespace-pre-line text-muted-foreground">{{ order.notes }}</p>
                    <div class="mt-auto flex flex-wrap items-center gap-2 pt-2">
                        <div v-if="canManage" class="w-36">
                            <label :for="`wo-status-${order.id}`" class="sr-only">{{ t('Status') }}</label>
                            <Select
                                :id="`wo-status-${order.id}`"
                                :model-value="order.status"
                                :options="options.work_order_statuses"
                                class="h-[30px] text-xs"
                                @update:model-value="(v) => setWoStatus(order, v)"
                            />
                        </div>
                        <Button variant="ghost" size="sm" @click="printWorkOrder(order)">
                            <Printer class="size-3.5" :stroke-width="1.5" />
                            {{ t('Print') }}
                        </Button>
                        <template v-if="canManage">
                            <Button variant="ghost" size="sm" @click="editWorkOrder(order)">{{ t('Edit') }}</Button>
                            <Button variant="ghost" size="sm" class="text-destructive" @click="deleteWorkOrder(order)">{{ t('Delete') }}</Button>
                        </template>
                    </div>
                </li>
            </ul>
        </template>
    </div>

    <KitchenItemFormPanel v-if="canManage" :open="itemFormOpen" :item="editingItem" :options="options" @close="itemFormOpen = false" />
    <KitchenAdjustPanel
        :open="adjustItemId !== null"
        :item="adjustItem"
        :movements="movementsFor === adjustItemId ? itemMovements : undefined"
        :can-manage="canManage"
        @close="closeAdjust"
    />
    <KitchenWorkOrderPanel v-if="canManage" :open="woFormOpen" :order="editingWo" :items="items" @close="woFormOpen = false" />

    <!-- Print sheets: invisible on screen, the only thing on paper while printing. -->
    <PrintSheet ref="stockSheet" :title="`${t('Kitchen stock')} — ${dateLabel(today)}`">
        <table class="w-full border-collapse text-[12px]">
            <thead>
                <tr class="border-b border-black text-left">
                    <th class="py-1">{{ t('Item') }}</th>
                    <th class="py-1">{{ t('Location') }}</th>
                    <th class="py-1 text-right">{{ t('Stock') }}</th>
                    <th class="py-1 text-right">{{ t('Minimum') }}</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="g in grouped" :key="g.category.value">
                    <tr><td colspan="4" class="pt-3 font-bold">{{ g.category.label }}</td></tr>
                    <tr v-for="item in g.items" :key="item.id" class="border-b border-gray-300">
                        <td class="py-1">{{ item.name }}</td>
                        <td class="py-1">{{ label(options.locations, item.location) }}</td>
                        <td class="py-1 text-right">{{ qty(item.current_stock) }} {{ item.unit }}</td>
                        <td class="py-1 text-right">{{ qty(item.min_stock) }}</td>
                    </tr>
                </template>
            </tbody>
        </table>
    </PrintSheet>

    <PrintSheet v-if="shownCheck" ref="countSheet" :title="`${t('Stock-take')} — ${dateLabel(shownCheck.date)}`">
        <table class="w-full border-collapse text-[12px]">
            <thead>
                <tr class="border-b border-black text-left">
                    <th class="py-1">{{ t('Item') }}</th>
                    <th class="py-1 text-right">{{ t('System') }}</th>
                    <th class="py-1 text-right">{{ t('Counted') }}</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="g in checkGroups" :key="g.category.value">
                    <tr><td colspan="3" class="pt-3 font-bold">{{ g.category.label }}</td></tr>
                    <tr v-for="line in g.lines" :key="line.id" class="border-b border-gray-300">
                        <td class="py-1.5">{{ line.name }}</td>
                        <td class="py-1.5 text-right">{{ qty(line.system_stock) }} {{ line.unit }}</td>
                        <td class="w-32 py-1.5 text-right">{{ line.physical_count === null ? '________' : qty(line.physical_count) }}</td>
                    </tr>
                </template>
            </tbody>
        </table>
    </PrintSheet>

    <PrintSheet v-if="printedWo" ref="woSheet" :title="printedWo.title">
        <p class="mb-3">
            {{ dateLabel(printedWo.date) }}<template v-if="printedWo.assigned_to"> · {{ printedWo.assigned_to }}</template>
        </p>
        <table class="w-full border-collapse text-[12px]">
            <tbody>
                <tr v-for="line in printedWo.items" :key="line.id" class="border-b border-gray-300">
                    <td class="w-6 py-1.5">☐</td>
                    <td class="w-24 py-1.5 text-right font-bold">{{ qty(line.quantity) }} {{ line.unit ?? '' }}</td>
                    <td class="py-1.5 pl-3">{{ line.name }}<span v-if="line.notes"> — {{ line.notes }}</span></td>
                </tr>
            </tbody>
        </table>
        <p v-if="printedWo.notes" class="mt-3 whitespace-pre-line">{{ printedWo.notes }}</p>
    </PrintSheet>
</template>
