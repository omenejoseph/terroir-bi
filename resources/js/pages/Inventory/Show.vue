<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import CardHeader from '@/components/ui/CardHeader.vue';
import CardTitle from '@/components/ui/CardTitle.vue';
import { formatMoney, formatQuantity } from '@/lib/money';
import type { InventoryItem } from '@/types/inventory';
import type { SharedProps } from '@/types';

const props = defineProps<{ item: InventoryItem }>();

const page = usePage<SharedProps>();

const fields = computed(() => [
    { label: 'SKU', value: props.item.sku },
    { label: 'Category', value: props.item.category },
    { label: 'Group', value: props.item.group ?? '—' },
    { label: 'Unit', value: props.item.unit },
    { label: 'Current stock', value: formatQuantity(props.item.current_stock, page.props.locale) },
    { label: 'Minimum stock', value: formatQuantity(props.item.min_stock, page.props.locale) },
    {
        label: 'Default price',
        value: props.item.default_price
            ? formatMoney(props.item.default_price.minor, props.item.default_price.currency, page.props.locale)
            : '—',
    },
    {
        label: 'Cost per unit',
        value: props.item.cost_per_unit
            ? formatMoney(props.item.cost_per_unit.minor, props.item.cost_per_unit.currency, page.props.locale)
            : '—',
    },
]);
</script>

<template>
    <AppLayout :title="item.name">
        <div class="max-w-3xl space-y-6">
            <div class="flex items-center gap-3">
                <Button variant="outline" size="sm" href="/inventory">Back</Button>
                <Badge :variant="item.is_active ? 'success' : 'neutral'">
                    {{ item.is_active ? 'Active' : 'Inactive' }}
                </Badge>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>{{ item.name }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <img
                        v-if="item.image_url"
                        :src="item.image_url"
                        :alt="item.name"
                        class="mb-6 max-h-64 rounded-lg border border-border object-cover"
                    />

                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div v-for="field in fields" :key="field.label">
                            <dt class="text-sm text-muted-foreground">{{ field.label }}</dt>
                            <dd class="mt-0.5 text-sm font-medium">{{ field.value }}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
