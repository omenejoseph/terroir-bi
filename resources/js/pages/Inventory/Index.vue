<script setup lang="ts">
import { ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import { useAuth } from '@/composables/useAuth';
import { formatMoney, formatQuantity } from '@/lib/money';
import type { InventoryFilters, InventoryItem } from '@/types/inventory';
import type { Paginated, SharedProps } from '@/types';

const props = defineProps<{ items: Paginated<InventoryItem>; filters: InventoryFilters }>();

const page = usePage<SharedProps>();
const { can } = useAuth();

const search = ref(props.filters.search ?? '');

/*
  Debounced server-side search. `replace` keeps the browser history from filling
  with a step per keystroke; `preserveState` keeps the input focused and its
  value intact while the partial reload is in flight.
*/
let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            '/inventory',
            { search: value || undefined },
            { preserveState: true, replace: true, only: ['items', 'filters'] },
        );
    }, 300);
});

function price(item: InventoryItem): string {
    return item.default_price
        ? formatMoney(item.default_price.amount, item.default_price.currency, page.props.locale)
        : '—';
}
</script>

<template>
    <AppLayout title="Inventory">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <Input v-model="search" placeholder="Search name or SKU…" class="max-w-xs" />
                <span class="text-sm text-muted-foreground">{{ items.meta.total }} items</span>
                <Button v-if="can('inventory.manage')" href="/inventory/new" class="ml-auto">New item</Button>
            </div>

            <!-- The table scrolls inside its own container so the page never does. -->
            <div class="overflow-x-auto rounded-lg border border-border bg-card">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead class="border-b border-border text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Item</th>
                            <th scope="col" class="px-4 py-3 font-medium">SKU</th>
                            <th scope="col" class="px-4 py-3 font-medium">Category</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Stock</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Price</th>
                            <th scope="col" class="px-4 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="item in items.data"
                            :key="item.id"
                            class="cursor-pointer hover:bg-accent/50"
                            @click="router.visit(`/inventory/${item.id}`)"
                        >
                            <td class="px-4 py-3 font-medium">{{ item.name }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ item.sku }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ item.category }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatQuantity(item.current_stock, page.props.locale) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ price(item) }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="item.is_active ? 'success' : 'neutral'">
                                    {{ item.is_active ? 'Active' : 'Inactive' }}
                                </Badge>
                            </td>
                        </tr>
                        <tr v-if="items.data.length === 0">
                            <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">
                                No items match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="items.meta.last_page > 1" class="flex items-center justify-between text-sm">
                <span class="text-muted-foreground">
                    Page {{ items.meta.current_page }} of {{ items.meta.last_page }}
                </span>
                <div class="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="items.meta.current_page <= 1"
                        @click="router.get('/inventory', { ...filters, page: items.meta.current_page - 1 })"
                    >
                        Previous
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="items.meta.current_page >= items.meta.last_page"
                        @click="router.get('/inventory', { ...filters, page: items.meta.current_page + 1 })"
                    >
                        Next
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
