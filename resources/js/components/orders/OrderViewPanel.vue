<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowRight, MoreHorizontal } from 'lucide-vue-next';

import StatusStepper from '@/components/orders/StatusStepper.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import Separator from '@/components/ui/Separator.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import { useAuth } from '@/composables/useAuth';
import { formatMoney, formatNumber } from '@/lib/money';
import type { MoneyValue } from '@/types/inventory';
import type { Order, OrderLine, OrderStatusKey } from '@/types/orders';
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

const money = (m: MoneyValue | null | undefined): string =>
    m ? formatMoney(m.minor, m.currency, locale.value) : '—';

/** The four order statuses, in workflow order — the stepper's steps. */
const STEPS: { key: OrderStatusKey; label: string }[] = [
    { key: 'RECEIVED', label: 'Received' },
    { key: 'IN_PROCESS', label: 'In Process' },
    { key: 'READY_TO_SHIP', label: 'Ready to Ship' },
    { key: 'SHIPPED', label: 'Shipped' },
];

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

    return `${items.length} lines · ${formatNumber(units, locale.value)} units`;
});

/** "25,48 € / bottle · 0.75 L" under each line. */
function lineMeta(line: OrderLine): string {
    const unit = line.unit_type === 'cases' ? 'case' : 'bottle';

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

const comment = useForm({ content: '' });

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

const confirmingDelete = ref(false);

function destroy(): void {
    const id = order.value?.id;
    if (id === undefined) return;

    router.delete(`/orders/${id}`, { onSuccess: () => emit('close') });
}
</script>

<template>
    <SidePanel :open="open" :title="order?.customer?.company_name ?? 'Order'" :subtitle="subtitle" @close="emit('close')">
        <template #header-actions>
            <!-- @todo Overflow menu. The design offers per-order actions here
                 (print, resend, mark paid); none has an endpoint yet. -->
            <button
                type="button"
                class="p-2 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                aria-label="More actions"
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
                <Badge v-if="rebate !== null" variant="outline">Rebate {{ rebate }}%</Badge>
                <Badge v-if="order!.is_backorder" variant="warning">Backorder</Badge>
                <Badge v-if="order!.is_consignment" variant="outline">Consignment</Badge>
            </div>
            <p class="mt-2 text-xs text-muted-foreground">
                Received {{ dateTime(order!.created_at) }}
                <template v-if="order!.status_history.length">
                    · Updated {{ dateTime(order!.status_history[order!.status_history.length - 1]!.created_at) }}
                    <template v-if="order!.status_history[order!.status_history.length - 1]!.changed_by">
                        by {{ order!.status_history[order!.status_history.length - 1]!.changed_by!.name }}
                    </template>
                </template>
            </p>
        </template>

        <p v-if="!order" class="text-xs text-muted-foreground">Loading order…</p>

        <div v-else class="flex flex-col gap-6">
            <!-- Status -->
            <section class="flex flex-col gap-3">
                <SectionHeader title="Status">
                    <template #actions>
                        <span class="text-xs text-muted-foreground">
                            {{ can('orders.manage') ? 'Tap to update' : 'Read only' }}
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
                <SectionHeader title="Items">
                    <template #actions>
                        <span class="text-xs text-muted-foreground">{{ itemSummary }}</span>
                    </template>
                </SectionHeader>

                <ul class="flex flex-col">
                    <li
                        v-for="line in order.items"
                        :key="line.id"
                        class="flex items-center gap-3 border-b border-border py-3 last:border-b-0"
                    >
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

                        <span class="shrink-0 text-sm whitespace-nowrap">
                            {{ formatNumber(line.quantity, locale) }} {{ line.unit_type }}
                        </span>
                    </li>
                </ul>

                <div class="flex items-baseline justify-between gap-3 pt-1">
                    <span class="text-sm">Total · excl. VAT</span>
                    <span class="text-lg font-semibold tabular-nums">{{ money(order.total_amount) }}</span>
                </div>
            </section>

            <template v-if="order.profitability">
                <Separator />

                <!-- Profitability -->
                <section class="flex flex-col gap-3">
                    <SectionHeader title="Profitability" />

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
                            <dt class="text-muted-foreground">Revenue</dt>
                            <dd class="tabular-nums">{{ money(order.profitability.revenue) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 py-1">
                            <dt class="text-muted-foreground">COGS</dt>
                            <dd class="tabular-nums">−{{ money(order.profitability.cogs) }}</dd>
                        </div>

                        <div v-if="order.profitability.logistics" class="flex justify-between gap-3 py-1">
                            <dt class="text-muted-foreground">Logistics</dt>
                            <dd class="tabular-nums">−{{ money(order.profitability.logistics) }}</dd>
                        </div>
                        <div v-if="order.profitability.logistics" aria-hidden="true" />

                        <div class="mt-1 flex justify-between gap-3 border-t border-border pt-2">
                            <dt class="font-medium">Gross profit</dt>
                            <dd class="text-sm font-semibold tabular-nums">
                                {{ money(order.profitability.gross_profit) }}
                            </dd>
                        </div>
                        <div class="mt-1 flex justify-between gap-3 border-t border-border pt-2">
                            <dt class="font-medium">Margin</dt>
                            <dd class="text-sm font-semibold tabular-nums">
                                {{ order.profitability.margin_percent }}%
                            </dd>
                        </div>
                    </dl>

                    <p v-if="!order.profitability.complete" class="text-xs text-destructive">
                        No cost recorded for {{ order.profitability.missing_cost_items.join(', ') }} — margin is
                        overstated until they are costed.
                    </p>
                </section>
            </template>

            <template v-if="order.customer">
                <Separator />

                <!-- Customer -->
                <section class="flex flex-col gap-3">
                    <SectionHeader title="Customer">
                        <template #actions>
                            <!-- @todo Link to the customer profile once Phase 4
                                 ports the Customers module. -->
                            <span class="text-xs text-muted-foreground">View profile</span>
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
                                <dt class="text-muted-foreground">Contact</dt>
                                <dd class="truncate">{{ order.customer.contact_name ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 py-1">
                                <dt class="text-muted-foreground">Email</dt>
                                <dd class="truncate">{{ order.customer.email ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 py-1">
                                <dt class="text-muted-foreground">Phone</dt>
                                <dd class="truncate">{{ order.customer.phone ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3 py-1">
                                <dt class="text-muted-foreground">Standing rebate</dt>
                                <dd class="tabular-nums">{{ rebate !== null ? `${rebate}%` : '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                </section>
            </template>

            <Separator />

            <!-- Timeline -->
            <section class="flex flex-col gap-3">
                <SectionHeader title="Timeline" />

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
                                by {{ event.changed_by.name }}
                            </span>
                        </span>
                        <span class="shrink-0 text-xs text-muted-foreground">{{ dateTime(event.created_at) }}</span>
                    </li>
                </ul>
            </section>

            <Separator />

            <!-- Comments -->
            <section class="flex flex-col gap-3">
                <SectionHeader title="Comments">
                    <template #actions>
                        <span class="text-xs text-muted-foreground tabular-nums">{{ order.comments.length }}</span>
                    </template>
                </SectionHeader>

                <ul v-if="order.comments.length" class="flex flex-col gap-4">
                    <li v-for="note in order.comments" :key="note.id" class="flex items-start gap-3">
                        <Avatar :name="note.author?.name" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-baseline gap-2 text-xs">
                                <span class="font-medium text-foreground">{{ note.author?.name ?? 'Someone' }}</span>
                                <span class="text-muted-foreground">{{ dateTime(note.created_at) }}</span>
                            </span>
                            <span class="mt-0.5 block text-sm break-words">{{ note.content }}</span>
                            <!-- @todo Reactions. The design shows emoji counts
                                 under a comment; there is no reactions table. -->
                        </span>
                    </li>
                </ul>

                <form class="flex items-center gap-2 border border-input p-1" @submit.prevent="postComment">
                    <label class="sr-only" :for="`comment-${order.id}`">Write a comment</label>
                    <input
                        :id="`comment-${order.id}`"
                        v-model="comment.content"
                        type="text"
                        placeholder="Write a comment… use @ to tag"
                        class="h-8 min-w-0 flex-1 bg-transparent px-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none"
                    />
                    <button
                        type="submit"
                        class="inline-flex size-7 shrink-0 items-center justify-center bg-primary text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                        :disabled="comment.processing || comment.content.trim() === ''"
                        aria-label="Post comment"
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
                {{ confirmingDelete ? 'Confirm delete' : 'Delete' }}
            </Button>
            <!-- @todo Duplicate. Needs a "copy this order's lines into a new
                 draft" action; CreateOrderAction takes lines, so this is a
                 controller away, but it has no endpoint yet. -->
            <Button variant="outline">Duplicate</Button>
            <!-- @todo Edit order. Line editing has API endpoints
                 (addItems / updateItem / deleteItem) but no designed edit
                 surface in this drawer yet. -->
            <Button v-if="can('orders.manage')">Edit order</Button>
        </template>
    </SidePanel>
</template>
