<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowRight, Copy, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-vue-next';

import OrderLineFields from '@/components/orders/OrderLineFields.vue';
import QuantityStepper from '@/components/orders/QuantityStepper.vue';
import StatusStepper from '@/components/orders/StatusStepper.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import MentionInput from '@/components/ui/MentionInput.vue';
import type { Mentionable } from '@/components/ui/MentionInput.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import Select from '@/components/ui/Select.vue';
import Separator from '@/components/ui/Separator.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatNumber } from '@/lib/money';
import { linesToPayload, UNIT_OPTIONS } from '@/lib/orders';
import type { MoneyValue } from '@/types/inventory';
import type { Order, OrderLine, OrderLineDraft, OrderStatusKey, ProductOption } from '@/types/orders';
import type { SharedProps } from '@/types';

/**
 * Order — View (Figma `376:1592`; shown in context on `453:4938`).
 *
 * A read surface with one write on it: the status stepper. Everything else —
 * lines, profitability, the customer, the timeline — is presentation of what
 * the server already computed, so the drawer cannot report a different total or
 * margin than the API does for the same order.
 *
 * The order arrives from the list's partial reload, so it is `null` while that
 * request is in flight; the panel renders its skeleton rather than flickering
 * closed.
 */
const props = defineProps<{ open: boolean; order: Order | null }>();
const emit = defineEmits<{ close: [] }>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { can } = useAuth();
const { t } = useTranslations();

const money = (m: MoneyValue | null | undefined): string =>
    m ? formatMoney(m.minor, m.currency, locale.value) : '—';

/** The four order statuses, in workflow order — the stepper's steps. */
const STEPS = computed<{ key: OrderStatusKey; label: string }[]>(() => [
    { key: 'RECEIVED', label: t('Received') },
    { key: 'IN_PROCESS', label: t('In Process') },
    { key: 'READY_TO_SHIP', label: t('Ready to Ship') },
    { key: 'SHIPPED', label: t('Shipped') },
]);

const order = computed(() => props.order);

const subtitle = computed(() => {
    const o = order.value;
    if (o === null) return undefined;

    return `#${o.order_number}${o.created_at ? ` · ${longDate(o.created_at)}` : ''}`;
});

function longDate(iso: string): string {
    return new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' });
}

function dateTime(iso: string | null): string {
    if (iso === null) return '—';

    return new Date(iso).toLocaleString(locale.value, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** "Restaurant · Zadar" — the customer's identity chip in the meta strip. */
const customerChip = computed(() => {
    const c = order.value?.customer;
    if (!c) return null;

    const type = c.customer_type ? titleCase(c.customer_type) : null;

    return [type, c.city].filter(Boolean).join(' · ') || null;
});

const rebate = computed(() => {
    const value = Number.parseFloat(order.value?.customer?.rebate_percent ?? '');

    return Number.isFinite(value) && value > 0 ? value : null;
});

function titleCase(value: string): string {
    return value
        .toLowerCase()
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

/** "4 lines · 106 bottles", as in the design's Items header. */
const itemSummary = computed(() => {
    const items = order.value?.items ?? [];
    const units = items.reduce((sum, line) => sum + line.quantity, 0);

    return t(':count lines · :units units', { count: items.length, units: formatNumber(units, locale.value) });
});

/** "25,48 € / bottle · 0.75 L" under each line. */
function lineMeta(line: OrderLine): string {
    const unit = line.unit_type === 'cases' ? t('case') : t('bottle');

    return [`${money(line.unit_price)} / ${unit}`, line.unit_size].filter(Boolean).join(' · ');
}

/*
  The status stepper writes through the same action the API's status endpoint
  calls. `preserveScroll` keeps the drawer where it is; the reload afterwards
  brings back the new timeline entry.
*/
function moveTo(status: string): void {
    const id = order.value?.id;
    if (id === undefined) return;

    router.patch(
        `/orders/${id}/status`,
        { status },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => router.reload({ only: ['order', 'orders', 'statusCounts', 'pipeline'], data: { order: id } }),
        },
    );
}

const comment = useForm({ content: '', mentions: [] as string[] });

function postComment(): void {
    const id = order.value?.id;
    if (id === undefined || comment.content.trim() === '') return;

    comment.post(`/orders/${id}/comments`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            comment.reset();
            router.reload({ only: ['order'], data: { order: id } });
        },
    });
}

/** Clear a half-typed comment when the drawer moves to another order. */
watch(() => order.value?.id, () => comment.reset());

/**
 * The @-mention picker's member list — fetched once, filtered locally
 * (Web\TeamMembersController), same "any order viewer may @ a teammate"
 * permissiveness as commenting itself.
 */
const members = ref<Mentionable[]>([]);

onMounted(() => {
    void fetch('/team-members', { headers: { Accept: 'application/json' } })
        .then((response) => (response.ok ? response.json() : { data: [] }))
        .then((body: { data: Mentionable[] }) => {
            members.value = body.data;
        });
});

/*
  Per-line edit (Api\OrderController@updateItem / DeleteOrderItemAction, the
  same 1-hour edit window as the API — never checked here, it just surfaces as
  a failed request past the window, same as the API and the outgoing React
  app both leave it).
*/
const editingLineId = ref<string | null>(null);
const editQuantity = ref(1);
const editUnitType = ref('bottles');

function startEdit(line: OrderLine): void {
    editingLineId.value = line.id;
    editQuantity.value = line.quantity;
    editUnitType.value = line.unit_type;
}

function cancelEdit(): void {
    editingLineId.value = null;
}

/** Every write here redirects `back()` to /orders?order=…, but `order` is an Inertia::optional prop — a followed redirect's full visit omits it, so it needs its own reload, same as moveTo()/postComment() above. */
/**
 * `id` is a plain string captured by the caller BEFORE the mutation starts —
 * not re-read from `order.value` inside here. The mutation itself is a full
 * (non-partial) visit that follows its own `back()` redirect; since `order`
 * is an Inertia::optional (IgnoreFirstLoad) prop, that full visit's response
 * replaces `page.props` wholesale without it, so by the time `onSuccess`
 * runs `order.value` is already null. Re-reading it here would hit that same
 * null and silently no-op, leaving the drawer stuck on "Loading order…".
 */
function reloadOrder(id: string): void {
    router.reload({ only: ['order'], data: { order: id } });
}

function saveEdit(line: OrderLine): void {
    const id = order.value?.id;
    if (id === undefined) return;

    router.patch(
        `/order-items/${line.id}`,
        { quantity: editQuantity.value, unit_type: editUnitType.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                editingLineId.value = null;
                reloadOrder(id);
            },
        },
    );
}

function removeLine(line: OrderLine): void {
    const id = order.value?.id;
    if (id === undefined) return;
    if (!confirm(t('Remove :item?', { item: line.name || line.custom_description || t('this line') }))) return;

    router.delete(`/order-items/${line.id}`, { preserveScroll: true, onSuccess: () => reloadOrder(id) });
}

/** Appending lines, reusing the same picker CreateOrderPanel builds a whole order from. */
const products = computed<ProductOption[]>(() => (page.props.productOptions as ProductOption[] | undefined) ?? []);
const addingItems = ref(false);
const newLines = ref<OrderLineDraft[]>([]);

watch(addingItems, (adding) => {
    const id = order.value?.id;

    // Without `data: { order: id }` this drops the ?order= query param the
    // drawer is keyed on — the response then carries no `order` (it isn't in
    // `only`, and nothing tells the server which one to resolve), and unlike
    // every other reload in this file, this one was missing it: `order`
    // reads null and the drawer sticks on "Loading order…".
    if (adding && products.value.length === 0 && id !== undefined) {
        router.reload({ only: ['productOptions'], data: { order: id } });
    }
});

function cancelAddItems(): void {
    addingItems.value = false;
    newLines.value = [];
}

function submitNewLines(): void {
    const id = order.value?.id;
    if (id === undefined || newLines.value.length === 0) return;

    router.post(
        `/orders/${id}/items`,
        { items: linesToPayload(newLines.value) },
        {
            preserveScroll: true,
            onSuccess: () => {
                cancelAddItems();
                reloadOrder(id);
            },
        },
    );
}

/** Clones this order's customer, notes, shipping and lines into a fresh draft — no confirmation, it's additive, not destructive. */
function duplicateOrder(): void {
    const id = order.value?.id;
    if (id === undefined) return;

    router.post(`/orders/${id}/duplicate`, {});
}

const confirmingDelete = ref(false);

function destroy(): void {
    const id = order.value?.id;
    if (id === undefined) return;

    router.delete(`/orders/${id}`, { onSuccess: () => emit('close') });
}
</script>

<template>
    <SidePanel :open="open" :title="order?.customer?.company_name ?? t('Order')" :subtitle="subtitle" @close="emit('close')">
        <template #header-actions>
            <!-- @todo Overflow menu. The design offers per-order actions here
                 (print, resend, mark paid); none has an endpoint yet. -->
            <button
                type="button"
                class="p-2 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                :aria-label="t('More actions')"
            >
                <MoreHorizontal class="size-4" :stroke-width="1.5" />
            </button>
        </template>

        <template v-if="order" #meta>
            <div class="flex flex-wrap items-center gap-2">
                <Badge variant="solid">
                    {{ STEPS.find((s) => s.key === order!.status)?.label ?? order!.status }}
                </Badge>
                <Badge v-if="customerChip" variant="outline">{{ customerChip }}</Badge>
                <Badge v-if="rebate !== null" variant="outline">{{ t('Rebate :percent%', { percent: rebate }) }}</Badge>
                <Badge v-if="order!.is_backorder" variant="warning">{{ t('Backorder') }}</Badge>
                <Badge v-if="order!.is_consignment" variant="outline">{{ t('Consignment') }}</Badge>
            </div>
            <p class="mt-2 text-xs text-muted-foreground">
                {{ t('Received') }} {{ dateTime(order!.created_at) }}
                <template v-if="order!.status_history.length">
                    · {{ t('Updated') }} {{ dateTime(order!.status_history[order!.status_history.length - 1]!.created_at) }}
                    <template v-if="order!.status_history[order!.status_history.length - 1]!.changed_by">
                        {{ t('by :name', { name: order!.status_history[order!.status_history.length - 1]!.changed_by!.name }) }}
                    </template>
                </template>
            </p>
        </template>

        <p v-if="!order" class="text-xs text-muted-foreground">{{ t('Loading order…') }}</p>

        <div v-else class="flex flex-col gap-6">
            <!-- Status -->
            <section class="flex flex-col gap-3">
                <SectionHeader :title="t('Status')">
                    <template #actions>
                        <span class="text-xs text-muted-foreground">
                            {{ can('orders.manage') ? t('Tap to update') : t('Read only') }}
                        </span>
                    </template>
                </SectionHeader>
                <StatusStepper
                    :steps="STEPS"
                    :current="order.status"
                    :editable="can('orders.manage')"
                    @select="moveTo"
                />
            </section>

            <Separator />

            <!-- Items -->
            <section class="flex flex-col gap-3">
                <SectionHeader :title="t('Items')">
                    <template #actions>
                        <span class="text-xs text-muted-foreground">{{ itemSummary }}</span>
                    </template>
                </SectionHeader>

                <ul class="flex flex-col">
                    <li
                        v-for="line in order.items"
                        :key="line.id"
                        class="flex flex-col gap-2 border-b border-border py-3 last:border-b-0"
                    >
                        <div class="flex items-center gap-3">
                            <img
                                v-if="line.image_url"
                                :src="line.image_url"
                                alt=""
                                class="size-9 shrink-0 border border-border object-cover"
                            />
                            <span v-else class="size-9 shrink-0 border border-border bg-muted" aria-hidden="true" />

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm">
                                    {{ line.name }}
                                    <span v-if="line.custom_description && line.inventory_item_id" class="text-muted-foreground">
                                        {{ line.custom_description }}
                                    </span>
                                </span>
                                <span class="block truncate text-xs text-muted-foreground">{{ lineMeta(line) }}</span>
                            </span>

                            <span v-if="editingLineId !== line.id" class="shrink-0 text-sm whitespace-nowrap">
                                {{ formatNumber(line.quantity, locale) }} {{ line.unit_type }}
                            </span>

                            <template v-if="can('orders.manage') && editingLineId !== line.id">
                                <button
                                    type="button"
                                    class="shrink-0 p-1.5 text-muted-foreground transition-colors hover:text-foreground"
                                    :aria-label="t('Edit :item', { item: line.name })"
                                    @click="startEdit(line)"
                                >
                                    <Pencil class="size-3.5" :stroke-width="1.5" />
                                </button>
                                <button
                                    type="button"
                                    class="shrink-0 p-1.5 text-muted-foreground transition-colors hover:text-destructive"
                                    :aria-label="t('Remove :item', { item: line.name })"
                                    @click="removeLine(line)"
                                >
                                    <Trash2 class="size-3.5" :stroke-width="1.5" />
                                </button>
                            </template>
                        </div>

                        <div v-if="editingLineId === line.id" class="flex flex-wrap items-center gap-2 pl-12">
                            <QuantityStepper v-model="editQuantity" />

                            <!-- A catalog line's unit is fixed by the item — the server rejects anything else. -->
                            <span
                                v-if="line.inventory_item_id"
                                class="inline-flex h-7 items-center border border-border px-2.5 text-xs text-muted-foreground"
                            >
                                {{ line.unit_type === 'cases' ? t('Cases') : t('Bottles') }}
                            </span>
                            <Select v-else v-model="editUnitType" :options="UNIT_OPTIONS" class="h-7 w-24 text-xs" :aria-label="t('Unit')" />

                            <Button size="sm" @click="saveEdit(line)">{{ t('Save') }}</Button>
                            <Button size="sm" variant="ghost" @click="cancelEdit">{{ t('Cancel') }}</Button>
                        </div>
                    </li>
                </ul>

                <template v-if="can('orders.manage')">
                    <OrderLineFields v-if="addingItems" v-model="newLines" :products="products" :locale="locale">
                        <template #actions>
                            <Button size="sm" variant="ghost" type="button" @click="cancelAddItems">{{ t('Cancel') }}</Button>
                            <Button size="sm" type="button" :disabled="newLines.length === 0" @click="submitNewLines">
                                {{ t('Add :count', { count: newLines.length || '' }) }}
                            </Button>
                        </template>
                    </OrderLineFields>
                    <Button v-else variant="outline" size="sm" class="self-start" @click="addingItems = true">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        {{ t('Add item') }}
                    </Button>
                </template>

                <div class="flex items-baseline justify-between gap-3 pt-1">
                    <span class="text-sm">{{ t('Total · excl. VAT') }}</span>
                    <span class="text-lg font-semibold tabular-nums">{{ money(order.total_amount) }}</span>
                </div>
            </section>

            <template v-if="order.profitability">
                <Separator />

                <!-- Profitability -->
                <section class="flex flex-col gap-3">
                    <SectionHeader :title="t('Profitability')" />

                    <!--
                      The design splits revenue into gross, rebate and net. The
                      server records line totals with the rebate already applied
                      and does not keep the gross figure, so reconstructing it
                      here would be arithmetic on an assumption rather than data.
                      @todo Have OrderData carry the pre-rebate revenue so the
                      three-line breakdown can be shown as designed.
                    -->
                    <dl class="grid grid-cols-2 gap-x-8 border border-border bg-muted/40 p-4 text-xs">
                        <div class="flex justify-between gap-3 py-1">
                            <dt class="text-muted-foreground">{{ t('Revenue') }}</dt>
                            <dd class="tabular-nums">{{ money(order.profitability.revenue) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 py-1">
                            <dt class="text-muted-foreground">{{ t('COGS') }}</dt>
                            <dd class="tabular-nums">−{{ money(order.profitability.cogs) }}</dd>
                        </div>

                        <div v-if="order.profitability.logistics" class="flex justify-between gap-3 py-1">
                            <dt class="text-muted-foreground">{{ t('Logistics') }}</dt>
                            <dd class="tabular-nums">−{{ money(order.profitability.logistics) }}</dd>
                        </div>
                        <div v-if="order.profitability.logistics" aria-hidden="true" />

                        <div class="mt-1 flex justify-between gap-3 border-t border-border pt-2">
                            <dt class="font-medium">{{ t('Gross profit') }}</dt>
                            <dd class="text-sm font-semibold tabular-nums">
                                {{ money(order.profitability.gross_profit) }}
                            </dd>
                        </div>
                        <div class="mt-1 flex justify-between gap-3 border-t border-border pt-2">
                            <dt class="font-medium">{{ t('Margin') }}</dt>
                            <dd class="text-sm font-semibold tabular-nums">
                                {{ order.profitability.margin_percent }}%
                            </dd>
                        </div>
                    </dl>

                    <p v-if="!order.profitability.complete" class="text-xs text-destructive">
                        {{
                            t('No cost recorded for :items — margin is overstated until they are costed.', {
                                items: order.profitability.missing_cost_items.join(', '),
                            })
                        }}
                    </p>
                </section>
            </template>

            <template v-if="order.customer">
                <Separator />

                <!-- Customer -->
                <section class="flex flex-col gap-3">
                    <SectionHeader :title="t('Customer')">
                        <template #actions>
                            <Link
                                :href="`/customers/${order.customer.id}`"
                                class="text-xs text-muted-foreground transition-colors hover:text-foreground"
                            >
                                {{ t('View profile') }}
                            </Link>
                        </template>
                    </SectionHeader>

                    <div class="border border-border p-4">
                        <div class="flex items-center gap-3">
                            <Avatar :name="order.customer.company_name" />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium">
                                    {{ order.customer.company_name }}
                                </span>
                                <span v-if="customerChip" class="block truncate text-xs text-muted-foreground">
                                    {{ customerChip }}
                                </span>
                            </span>
                        </div>

                        <dl class="mt-3 text-xs">
                            <div class="flex justify-between gap-3 py-1">
                                <dt class="text-muted-foreground">{{ t('Contact') }}</dt>
                                <dd class="truncate">{{ order.customer.contact_name ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 py-1">
                                <dt class="text-muted-foreground">{{ t('Email') }}</dt>
                                <dd class="truncate">{{ order.customer.email ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 py-1">
                                <dt class="text-muted-foreground">{{ t('Phone') }}</dt>
                                <dd class="truncate">{{ order.customer.phone ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 py-1">
                                <dt class="text-muted-foreground">{{ t('Standing rebate') }}</dt>
                                <dd class="tabular-nums">{{ rebate !== null ? `${rebate}%` : '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                </section>
            </template>

            <Separator />

            <!-- Timeline -->
            <section class="flex flex-col gap-3">
                <SectionHeader :title="t('Timeline')" />

                <ul class="flex flex-col gap-3">
                    <li
                        v-for="(event, i) in [...order.status_history].reverse()"
                        :key="`${event.status}-${event.created_at}-${i}`"
                        class="flex items-start gap-3"
                    >
                        <span
                            class="mt-1 size-2 shrink-0"
                            :class="i === 0 ? 'bg-foreground' : 'bg-muted-foreground/40'"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm">
                                {{ STEPS.find((s) => s.key === event.status)?.label ?? event.status }}
                            </span>
                            <span v-if="event.changed_by" class="block text-xs text-muted-foreground">
                                {{ t('by :name', { name: event.changed_by.name }) }}
                            </span>
                        </span>
                        <span class="shrink-0 text-xs text-muted-foreground">{{ dateTime(event.created_at) }}</span>
                    </li>
                </ul>
            </section>

            <Separator />

            <!-- Comments -->
            <section class="flex flex-col gap-3">
                <SectionHeader :title="t('Comments')">
                    <template #actions>
                        <span class="text-xs text-muted-foreground tabular-nums">{{ order.comments.length }}</span>
                    </template>
                </SectionHeader>

                <ul v-if="order.comments.length" class="flex flex-col gap-4">
                    <li v-for="note in order.comments" :key="note.id" class="flex items-start gap-3">
                        <Avatar :name="note.author?.name" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-baseline gap-2 text-xs">
                                <span class="font-medium text-foreground">{{ note.author?.name ?? t('Someone') }}</span>
                                <span class="text-muted-foreground">{{ dateTime(note.created_at) }}</span>
                            </span>
                            <span class="mt-0.5 block text-sm break-words">{{ note.content }}</span>
                            <!-- @todo Reactions. The design shows emoji counts
                                 under a comment; there is no reactions table. -->
                        </span>
                    </li>
                </ul>

                <form class="flex items-center gap-2 border border-input p-1" @submit.prevent="postComment">
                    <label class="sr-only" :for="`comment-${order.id}`">{{ t('Write a comment') }}</label>
                    <MentionInput
                        :id="`comment-${order.id}`"
                        v-model="comment.content"
                        :members="members"
                        :placeholder="t('Write a comment… use @ to tag')"
                        class="h-8 px-2 text-sm placeholder:text-muted-foreground"
                        @mentions="comment.mentions = $event"
                    />
                    <button
                        type="submit"
                        class="inline-flex size-7 shrink-0 items-center justify-center bg-primary text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                        :disabled="comment.processing || comment.content.trim() === ''"
                        :aria-label="t('Post comment')"
                    >
                        <ArrowRight class="size-3.5" :stroke-width="2" />
                    </button>
                </form>
                <p v-if="comment.errors.content" class="text-xs text-destructive">{{ comment.errors.content }}</p>
            </section>
        </div>

        <template v-if="order" #footer>
            <Button
                v-if="can('orders.delete')"
                variant="ghost"
                class="mr-auto text-destructive hover:bg-destructive/10 hover:text-destructive"
                @click="confirmingDelete ? destroy() : (confirmingDelete = true)"
            >
                {{ confirmingDelete ? t('Confirm delete') : t('Delete') }}
            </Button>
            <Button v-if="can('orders.manage')" variant="outline" @click="duplicateOrder">
                <Copy class="size-3.5" :stroke-width="1.5" />
                {{ t('Duplicate') }}
            </Button>
        </template>
    </SidePanel>
</template>
