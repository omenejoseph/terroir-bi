<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { PencilLine, Plus, Trash2 } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney } from '@/lib/money';
import type { AgencyOption, AgencyPriceRow, ProgramRow } from '@/types/hospitality';
import type { ComboboxOption } from '@/types/ui';

/**
 * One program's agency price book: what each agency pays per head. A price is entered either as
 * a flat figure or as a rebate off the base price; a rebate is kept with the price so the price
 * follows the base price when it changes. The old app kept this list on the agency's own page —
 * the same rows, read from the program's side here.
 */
const props = defineProps<{
    open: boolean;
    program: ProgramRow | null;
    /** Undefined until the page has fetched this program's prices. */
    prices: AgencyPriceRow[] | undefined;
    agencies: AgencyOption[];
    canManage: boolean;
}>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

/* The row being edited: an agency id, 'new' for the add form, or null. */
const editing = ref<string | null>(null);
const agencyId = ref<string | null>(null);
const mode = ref<'price' | 'rebate'>('price');
const amount = ref('');
const saving = ref(false);
const error = ref<string | null>(null);

watch(
    () => [props.open, props.program?.id],
    () => {
        editing.value = null;
        error.value = null;
    },
);

const pricedIds = computed(() => new Set((props.prices ?? []).map((p) => p.customer_id)));

const agencyOptions = computed<ComboboxOption[]>(() =>
    props.agencies.filter((a) => !pricedIds.value.has(a.id)).map((a) => ({ value: a.id, label: a.name })),
);

const MODES = computed(() => [
    { value: 'price', label: t('Price per person') },
    { value: 'rebate', label: t('Rebate %') },
]);

function startAdd(): void {
    editing.value = 'new';
    agencyId.value = null;
    mode.value = 'price';
    amount.value = '';
    error.value = null;
}

function startEdit(row: AgencyPriceRow): void {
    editing.value = row.customer_id;
    agencyId.value = row.customer_id;
    mode.value = row.rebate_percent !== null ? 'rebate' : 'price';
    amount.value = row.rebate_percent !== null ? String(row.rebate_percent) : String(row.price_per_pax.minor / 100);
    error.value = null;
}

const parsed = computed(() => {
    const n = Number.parseFloat(amount.value.replace(',', '.'));

    if (amount.value.trim() === '' || !Number.isFinite(n) || n < 0) return null;
    if (mode.value === 'rebate' && (n <= 0 || n > 100)) return null;

    return n;
});

/** What the entered rebate comes to, for the line under the field. Rounded on the server too. */
const rebatePreview = computed(() => {
    if (mode.value !== 'rebate' || parsed.value === null || props.program === null) return null;

    const base = props.program.base_price;

    return formatMoney(Math.round(base.minor * (1 - parsed.value / 100)), base.currency);
});

function save(): void {
    if (props.program === null || agencyId.value === null || parsed.value === null) return;

    saving.value = true;
    error.value = null;

    router.put(
        `/hospitality/programs/${props.program.id}/agency-prices/${agencyId.value}`,
        mode.value === 'rebate' ? { rebate_percent: parsed.value } : { price_per_pax: Math.round(parsed.value * 100) },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['programPrices', 'programs'],
            // The page URL carries ?program=…, so the redirect back re-reads this program's prices.
            onSuccess: () => (editing.value = null),
            onError: (errors) => (error.value = Object.values(errors)[0] ?? t('Could not save the price.')),
            onFinish: () => (saving.value = false),
        },
    );
}

async function remove(row: AgencyPriceRow): Promise<void> {
    if (props.program === null) return;

    const confirmed = await confirmDialog({
        title: t('Remove agency price?'),
        description: t(':agency will pay the base price for :program again.', { agency: row.customer_name ?? '', program: props.program.name }),
        confirmLabel: t('Remove'),
        tone: 'danger',
    });

    if (!confirmed) return;

    router.delete(`/hospitality/programs/${props.program.id}/agency-prices/${row.customer_id}`, {
        preserveScroll: true,
        preserveState: true,
        only: ['programPrices', 'programs'],
    });
}
</script>

<template>
    <SidePanel
        :open="open"
        :title="program ? t('Agency prices · :program', { program: program.name }) : t('Agency prices')"
        :subtitle="program ? t('Base price :price per person', { price: formatMoney(program.base_price.minor, program.base_price.currency) }) : undefined"
        @close="emit('close')"
    >
        <div class="flex flex-col gap-4">
            <p v-if="prices === undefined" class="text-xs text-muted-foreground">{{ t('Loading…') }}</p>

            <p v-else-if="prices.length === 0 && editing !== 'new'" class="text-xs text-muted-foreground">
                {{ t('No agency prices yet. Every agency pays the base price.') }}
            </p>

            <ul v-else-if="prices.length > 0" class="divide-y divide-border border border-border">
                <li v-for="row in prices" :key="row.id" class="flex items-center gap-3 px-3 py-2.5 text-xs">
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-foreground">{{ row.customer_name ?? '—' }}</p>
                        <p class="text-muted-foreground">
                            <template v-if="row.rebate_percent !== null">{{ t(':percent% rebate, follows the base price', { percent: row.rebate_percent }) }}</template>
                            <template v-else-if="row.effective_rebate_percent">{{ t(':percent% below base', { percent: row.effective_rebate_percent }) }}</template>
                            <template v-else>{{ t('Flat price') }}</template>
                        </p>
                    </div>
                    <span class="shrink-0 font-semibold tabular-nums">{{ formatMoney(row.price_per_pax.minor, row.price_per_pax.currency) }}</span>
                    <template v-if="canManage">
                        <button type="button" class="p-1 text-muted-foreground hover:text-foreground" :aria-label="t('Edit')" @click="startEdit(row)">
                            <PencilLine class="size-3.5" :stroke-width="1.5" />
                        </button>
                        <button type="button" class="p-1 text-muted-foreground hover:text-destructive" :aria-label="t('Remove')" @click="remove(row)">
                            <Trash2 class="size-3.5" :stroke-width="1.5" />
                        </button>
                    </template>
                </li>
            </ul>

            <form v-if="canManage && editing !== null" class="flex flex-col gap-4 border border-border p-3" @submit.prevent="save">
                <FormField v-slot="{ id }" :label="t('Agency')" required>
                    <Combobox
                        v-if="editing === 'new'"
                        :id="id"
                        v-model="agencyId"
                        :options="agencyOptions"
                        :placeholder="t('Choose an agency…')"
                        :empty-text="t('Every agency already has a price for this program.')"
                    />
                    <p v-else :id="id" class="text-sm">{{ prices?.find((p) => p.customer_id === editing)?.customer_name }}</p>
                </FormField>

                <Tabs :items="MODES" :current="mode" @select="(v) => (mode = v as 'price' | 'rebate')" />

                <FormField
                    v-slot="{ id, invalid }"
                    :label="mode === 'rebate' ? t('Rebate %') : t('Price per person')"
                    required
                    :hint="rebatePreview ? t('Comes to :price per person', { price: rebatePreview }) : undefined"
                    :error="error ?? undefined"
                >
                    <Input
                        :id="id"
                        v-model="amount"
                        :invalid="invalid"
                        type="number"
                        :min="mode === 'rebate' ? '0.01' : '0'"
                        :max="mode === 'rebate' ? '100' : undefined"
                        step="0.01"
                        inputmode="decimal"
                    />
                </FormField>

                <div class="flex justify-end gap-2">
                    <Button variant="outline" size="sm" @click="editing = null">{{ t('Cancel') }}</Button>
                    <Button type="submit" size="sm" :disabled="saving || agencyId === null || parsed === null">{{ t('Save price') }}</Button>
                </div>
            </form>
        </div>

        <template v-if="canManage" #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Close') }}</Button>
            <Button :disabled="editing !== null || prices === undefined" @click="startAdd">
                <Plus class="size-3.5" :stroke-width="1.5" />
                {{ t('Add agency price') }}
            </Button>
        </template>
    </SidePanel>
</template>
