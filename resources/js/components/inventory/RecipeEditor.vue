<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { FileText, Plus, Trash2, Wine } from 'lucide-vue-next';

import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney, formatUnitPrice } from '@/lib/money';
import { measureFactor } from '@/lib/stock';
import type {
    InventoryItem,
    RecipeCellarLot,
    RecipeCostSummary,
    RecipeInputOption,
    RecipeLine,
    RecipeSource,
} from '@/types/inventory';
import type { ComboboxOption } from '@/types/ui';

/**
 * Product Detail · Recipe tab (Figma 449:1577): the item's bill of materials — what one unit of it
 * consumes, and what that costs. Saved as a whole replacement (App\Actions\Inventory\SetRecipeAction),
 * the same shape the JSON API's own recipe editor writes.
 *
 * A line is one of three things:
 *  - a catalog item (priced from its cost per unit),
 *  - wine from the cellar (priced per litre exactly as the Cellar shows it; it is not drawn from stock),
 *  - a custom ingredient with a name, unit and cost of its own (not drawn from stock either).
 *
 * A liquid or a weight can be entered in a unit that converts — 75 cl, 5 ml, 20 g — and is costed on
 * the converted quantity, so a wine costed per litre prices a bottle's worth correctly.
 */
const props = defineProps<{
    item: InventoryItem;
    lines: RecipeLine[];
    /** Every other active catalog item — undefined until this tab first asks for it. */
    inputs: RecipeInputOption[] | undefined;
    cellarLots: RecipeCellarLot[] | undefined;
    sources: RecipeSource[] | undefined;
    summary: RecipeCostSummary;
}>();

const { t } = useTranslations();

type Kind = 'catalog' | 'cellar' | 'custom';

interface Draft {
    key: string;
    kind: Kind;
    input_id: string | null;
    wine_lot_id: string | null;
    name: string;
    /** The unit the cost is held in: the input's own, litres for cellar wine, the custom unit. */
    base_unit: string;
    /** Cost of one base unit, minor units (fractional is fine). Null = unpriced. */
    base_cost: number | null;
    currency: string | null;
    quantity: string;
    /** The unit `quantity` is entered in; null = the base unit. */
    unit: string | null;
    unit_options: string[];
    /** Custom lines: the cost typed in major units. */
    custom_cost: string;
}

let seq = 0;
const nextKey = (): string => `line-${(seq += 1)}`;

const draft = ref<Draft[]>([]);

const MINOR_DIGITS = (currency: string): number => (['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'HUF'].includes(currency.toUpperCase()) ? 0 : 2);
const currencyOf = computed(() => props.item.cost_per_unit?.currency ?? props.item.default_price?.currency ?? props.summary.per_unit?.currency ?? 'EUR');

function toDraft(line: RecipeLine): Draft {
    const currency = line.unit_cost?.currency ?? null;
    const factor = measureFactor(line.unit, line.kind === 'cellar' ? 'l' : line.input_unit) ?? 1;
    const digits = MINOR_DIGITS(currency ?? currencyOf.value);

    return {
        key: nextKey(),
        kind: line.kind,
        input_id: line.input_id,
        wine_lot_id: line.wine_lot_id,
        name: line.input_name,
        base_unit: line.kind === 'cellar' ? 'l' : line.input_unit,
        // unit_cost is per the line's own unit; undo the conversion to get the cost of one base unit.
        base_cost: line.unit_cost === null ? null : line.unit_cost.minor / factor,
        currency,
        quantity: line.quantity,
        unit: line.unit,
        unit_options: line.unit_options,
        custom_cost: line.kind === 'custom' && line.custom_cost !== null ? String(line.custom_cost / 10 ** digits) : '',
    };
}

/* Repopulate fresh whenever the server's own lines change — after a save, or when switching back onto this tab. */
watch(
    () => props.lines,
    (lines) => {
        draft.value = lines.map(toDraft);
    },
    { immediate: true },
);

onMounted(() => {
    if (props.inputs === undefined || props.cellarLots === undefined || props.sources === undefined) {
        router.reload({ only: ['recipeInputOptions', 'recipeCellarLots', 'recipeSources'] });
    }
});

/* ---- adding lines ------------------------------------------------------------------- */

const picked = ref<string | null>(null);

/** Which picker is open under the buttons: catalog materials or wine from the cellar. */
const adding = ref<'material' | 'cellar' | null>(null);

const MATERIAL_OPTIONS = computed<ComboboxOption[]>(() =>
    (props.inputs ?? [])
        .filter((input) => !draft.value.some((line) => line.input_id === input.id))
        .map((input) => ({
            value: input.id,
            label: [input.name, input.vintage].filter(Boolean).join(' '),
            description: [input.sku, input.unit].filter(Boolean).join(' · '),
            keywords: [input.sku, input.group].filter(Boolean) as string[],
        })),
);

const CELLAR_OPTIONS = computed<ComboboxOption[]>(() =>
    (props.cellarLots ?? [])
        .filter((lot) => !draft.value.some((line) => line.wine_lot_id === lot.id))
        .map((lot) => ({
            value: lot.id,
            label: [lot.name, lot.vintage].filter(Boolean).join(' '),
            description: `${lot.lot_number} · ${formatMoney(lot.cost_per_liter.minor, lot.cost_per_liter.currency)}/l`,
            keywords: [lot.lot_number],
        })),
);

function toggleAdding(kind: 'material' | 'cellar'): void {
    adding.value = adding.value === kind ? null : kind;
    picked.value = null;
}

function catalogDraft(input: RecipeInputOption, quantity = '1', unit: string | null = null): Draft {
    return {
        key: nextKey(),
        kind: 'catalog',
        input_id: input.id,
        wine_lot_id: null,
        name: input.name,
        base_unit: input.unit,
        base_cost: input.unit_cost?.minor ?? null,
        currency: input.unit_cost?.currency ?? null,
        quantity,
        unit,
        unit_options: input.unit_options,
        custom_cost: '',
    };
}

function cellarDraft(lot: RecipeCellarLot, quantity = '1', unit: string | null = null): Draft {
    return {
        key: nextKey(),
        kind: 'cellar',
        input_id: null,
        wine_lot_id: lot.id,
        name: [lot.name, lot.vintage].filter(Boolean).join(' '),
        base_unit: 'l',
        base_cost: lot.cost_per_liter.minor,
        currency: lot.cost_per_liter.currency,
        // Wine is measured, not counted: default to a bottle's worth in ml so the cost reads right.
        quantity: unit === null ? '750' : quantity,
        unit: unit ?? 'ml',
        unit_options: ['ml', 'cl', 'l'],
        custom_cost: '',
    };
}

function addLine(value: string): void {
    if (adding.value === 'cellar') {
        const lot = (props.cellarLots ?? []).find((l) => l.id === value);
        if (lot) draft.value = [...draft.value, cellarDraft(lot)];
    } else {
        const input = (props.inputs ?? []).find((i) => i.id === value);
        if (input) draft.value = [...draft.value, catalogDraft(input)];
    }

    picked.value = null;
    adding.value = null;
}

function addCustom(): void {
    adding.value = null;
    draft.value = [
        ...draft.value,
        {
            key: nextKey(),
            kind: 'custom',
            input_id: null,
            wine_lot_id: null,
            name: '',
            base_unit: '',
            base_cost: null,
            currency: null,
            quantity: '1',
            unit: null,
            unit_options: [],
            custom_cost: '',
        },
    ];
}

function removeLine(key: string): void {
    draft.value = draft.value.filter((line) => line.key !== key);
}

/** Replaces the draft with another product's recipe, keeping only what can still be resolved. */
function copyFrom(id: string | null): void {
    const source = (props.sources ?? []).find((s) => s.id === id);
    if (!source) return;

    const copied: Draft[] = [];

    for (const line of source.lines) {
        if (line.input_id) {
            const input = (props.inputs ?? []).find((i) => i.id === line.input_id);
            if (input) copied.push(catalogDraft(input, line.quantity, line.unit));
        } else if (line.wine_lot_id) {
            const lot = (props.cellarLots ?? []).find((l) => l.id === line.wine_lot_id);
            if (lot) copied.push(cellarDraft(lot, line.quantity, line.unit ?? 'l'));
        } else if (line.custom_name) {
            const digits = MINOR_DIGITS(currencyOf.value);
            copied.push({
                ...blankCustom(),
                name: line.custom_name,
                base_unit: line.custom_unit ?? '',
                quantity: line.quantity,
                custom_cost: line.custom_cost === null ? '' : String(line.custom_cost / 10 ** digits),
            });
        }
    }

    draft.value = copied;
    copySource.value = null;
}

function blankCustom(): Draft {
    return { key: nextKey(), kind: 'custom', input_id: null, wine_lot_id: null, name: '', base_unit: '', base_cost: null, currency: null, quantity: '1', unit: null, unit_options: [], custom_cost: '' };
}

const copySource = ref<string | null>(null);
const SOURCE_OPTIONS = computed<ComboboxOption[]>(() =>
    (props.sources ?? []).map((s) => ({ value: s.id, label: [s.name, s.vintage].filter(Boolean).join(' '), description: `${s.lines.length} ${t('ingredients')}` })),
);

/* ---- cost, live as it is typed -------------------------------------------------------- */

function qty(line: Draft): number {
    const n = Number.parseFloat(line.quantity.replace(',', '.'));

    return Number.isFinite(n) ? n : 0;
}

/** Cost of one base unit in minor units: catalog / cellar come from the server; a custom line is typed. */
function baseCost(line: Draft): number | null {
    if (line.kind !== 'custom') return line.base_cost;
    if (line.custom_cost.trim() === '') return null;

    const major = Number.parseFloat(line.custom_cost.replace(',', '.'));

    return Number.isFinite(major) ? Math.round(major * 10 ** MINOR_DIGITS(currencyOf.value)) : null;
}

/** What one of the line's own unit costs, minor units. */
function unitCost(line: Draft): number | null {
    const cost = baseCost(line);
    if (cost === null) return null;

    return cost * (measureFactor(line.unit ?? line.base_unit, line.base_unit) ?? 1);
}

function lineCost(line: Draft): number | null {
    const cost = baseCost(line);
    if (cost === null) return null;

    return Math.round(cost * qty(line) * (measureFactor(line.unit ?? line.base_unit, line.base_unit) ?? 1));
}

const currency = computed(() => draft.value.find((l) => l.currency)?.currency ?? currencyOf.value);

const total = computed(() => {
    const costs = draft.value.map(lineCost);

    return {
        minor: costs.reduce<number>((sum, c) => sum + (c ?? 0), 0) * props.summary.multiplier,
        complete: draft.value.length > 0 && costs.every((c) => c !== null),
        any: costs.some((c) => c !== null),
    };
});

const selling = computed(() => props.item.default_price);
const margin = computed(() => (selling.value && total.value.complete ? selling.value.minor - total.value.minor : null));
const marginPct = computed(() => (margin.value !== null && selling.value && selling.value.minor > 0 ? (margin.value / selling.value.minor) * 100 : null));

/** Unit choices for a line: its convertible units, or nothing when it has only one. */
function unitChoices(line: Draft): { value: string; label: string }[] {
    return line.unit_options.map((u) => ({ value: u, label: u }));
}

/* ---- saving ---------------------------------------------------------------------------- */

interface Payload {
    input_id?: string | null;
    wine_lot_id?: string | null;
    quantity: string;
    unit?: string | null;
    custom_name?: string;
    custom_unit?: string | null;
    custom_cost?: number | null;
}

const form = useForm<{ items: Payload[] }>({ items: [] });

const canSubmit = computed(() =>
    draft.value.every((line) => line.quantity.trim() !== '' && qty(line) > 0 && (line.kind !== 'custom' || line.name.trim() !== '')),
);

function payload(line: Draft): Payload {
    if (line.kind === 'catalog') return { input_id: line.input_id, quantity: line.quantity, unit: line.unit };
    if (line.kind === 'cellar') return { wine_lot_id: line.wine_lot_id, quantity: line.quantity, unit: line.unit };

    return {
        custom_name: line.name.trim(),
        custom_unit: line.base_unit.trim() || null,
        custom_cost: baseCost(line),
        quantity: line.quantity,
    };
}

function submit(): void {
    form.items = draft.value.map(payload);

    form.put(`/inventory/${props.item.id}/recipe`, {
        preserveScroll: true,
        // See CustomerPriceDialog.vue's submit() for why a plain put without `only` would silently drop every
        // other Optional prop this page may already have loaded. Saving also moves the item's own cost.
        only: ['recipe', 'recipeCost', 'item'],
    });
}

const itemErrors = computed(() => Object.entries(form.errors).filter(([key]) => key.startsWith('items')).map(([, message]) => message));
</script>

<template>
    <div class="flex flex-col gap-4">
        <p class="text-sm text-muted-foreground">
            {{ t('What one unit of this item is made from — used by the Produce tab and to work out its cost.') }}
        </p>

        <!-- Cost, selling price and margin -->
        <div v-if="draft.length && total.any" class="flex flex-col gap-1.5 border border-border bg-muted/40 p-3 text-sm">
            <div class="flex items-center justify-between">
                <span class="text-muted-foreground">{{ t('Recipe cost per unit') }}<template v-if="summary.multiplier > 1"> ({{ t(':count bottles', { count: summary.multiplier }) }})</template></span>
                <span class="font-semibold tabular-nums">{{ formatMoney(total.minor, currency) }}</span>
            </div>
            <p v-if="!total.complete" class="text-xs text-muted-foreground">
                {{ t('Some lines have no cost yet, so this is a lower figure.') }}
            </p>
            <template v-if="selling && selling.minor > 0 && total.complete">
                <div class="mt-1 flex items-center justify-between border-t border-border pt-1.5">
                    <span class="text-muted-foreground">{{ t('Selling price') }}</span>
                    <span class="font-medium tabular-nums">{{ formatMoney(selling.minor, selling.currency) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-muted-foreground">{{ t('Margin') }}</span>
                    <span class="font-semibold tabular-nums" :class="(margin ?? 0) >= 0 ? 'text-foreground' : 'text-destructive'">
                        {{ formatMoney(margin ?? 0, selling.currency) }}<template v-if="marginPct !== null"> ({{ marginPct.toFixed(1) }}%)</template>
                    </span>
                </div>
            </template>
        </div>

        <div v-if="draft.length" class="flex flex-col divide-y divide-border border border-border">
            <div v-for="line in draft" :key="line.key" class="flex flex-col gap-2 px-4 py-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-0 flex-1 basis-48">
                        <template v-if="line.kind === 'custom'">
                            <label class="mb-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                                <FileText class="size-3.5" :stroke-width="1.5" /> {{ t('Custom item') }}
                            </label>
                            <Input v-model="line.name" :placeholder="t('e.g. Plavina 2018 wine')" :aria-label="t('Custom item name')" />
                        </template>
                        <template v-else>
                            <p class="flex items-center gap-2 text-sm">
                                <Wine v-if="line.kind === 'cellar'" class="size-3.5 shrink-0 text-muted-foreground" :stroke-width="1.5" />
                                <span class="min-w-0 truncate">{{ line.name }}</span>
                                <Badge v-if="line.kind === 'cellar'" variant="outline">{{ t('Cellar') }}</Badge>
                            </p>
                        </template>
                    </div>

                    <div class="w-24">
                        <label v-if="line.kind === 'custom'" class="mb-1 block text-xs text-muted-foreground">{{ t('Qty') }}</label>
                        <Input
                            v-model="line.quantity"
                            inputmode="decimal"
                            :aria-label="t('Quantity of :name', { name: line.name })"
                        />
                    </div>

                    <!-- A unit that converts: ml / cl / l for liquids, g / kg for weights -->
                    <div v-if="line.unit_options.length" class="w-24">
                        <Select
                            :model-value="line.unit ?? line.base_unit"
                            :options="unitChoices(line)"
                            :aria-label="t('Unit')"
                            @update:model-value="line.unit = $event"
                        />
                    </div>
                    <div v-else-if="line.kind === 'custom'" class="w-24">
                        <label class="mb-1 block text-xs text-muted-foreground">{{ t('Unit') }}</label>
                        <Input v-model="line.base_unit" :placeholder="t('units')" :aria-label="t('Unit')" />
                    </div>
                    <span v-else class="w-24 pb-2 text-sm text-muted-foreground">{{ line.base_unit }}</span>

                    <div v-if="line.kind === 'custom'" class="w-28">
                        <label class="mb-1 block text-xs text-muted-foreground">{{ t('Cost / unit') }}</label>
                        <Input v-model="line.custom_cost" inputmode="decimal" placeholder="0.00" :aria-label="t('Cost per unit')" />
                    </div>

                    <div class="w-24 pb-2 text-right text-sm tabular-nums">
                        <span v-if="lineCost(line) !== null" class="font-medium">{{ formatMoney(lineCost(line)!, line.currency ?? currencyOf) }}</span>
                        <span v-else class="text-xs text-muted-foreground">{{ t('No cost') }}</span>
                    </div>

                    <button
                        type="button"
                        class="mb-1 shrink-0 p-1.5 text-muted-foreground transition-colors hover:text-destructive"
                        :aria-label="t('Remove :name', { name: line.name })"
                        @click="removeLine(line.key)"
                    >
                        <Trash2 class="size-4" :stroke-width="1.5" />
                    </button>
                </div>

                <p v-if="unitCost(line) !== null && line.kind !== 'custom'" class="text-xs text-muted-foreground">
                    {{ formatUnitPrice(unitCost(line)!, line.currency ?? currencyOf) }}/{{ line.unit ?? line.base_unit }}
                    <template v-if="line.kind === 'cellar'"> · {{ t('cost per litre from the Cellar') }}</template>
                    <template v-else-if="line.unit && line.unit !== line.base_unit"> · {{ formatUnitPrice(line.base_cost ?? 0, line.currency ?? currencyOf) }}/{{ line.base_unit }}</template>
                </p>
                <p v-if="line.kind !== 'catalog'" class="text-xs text-muted-foreground">
                    {{ t('Counts towards the cost only — not taken from stock when producing.') }}
                </p>
            </div>
        </div>
        <p v-else class="text-xs text-muted-foreground">{{ t('No ingredients added yet.') }}</p>

        <div class="flex flex-wrap items-center gap-3">
            <Button variant="outline" size="sm" :aria-expanded="adding === 'material'" @click="toggleAdding('material')">
                <Plus class="size-4" :stroke-width="1.5" />
                {{ t('Add material') }}
            </Button>
            <Button variant="outline" size="sm" :aria-expanded="adding === 'cellar'" @click="toggleAdding('cellar')">
                <Wine class="size-4" :stroke-width="1.5" />
                {{ t('Add cellar content') }}
            </Button>
            <Button variant="outline" size="sm" @click="addCustom">
                <FileText class="size-4" :stroke-width="1.5" />
                {{ t('Add custom item') }}
            </Button>
        </div>

        <Combobox
            v-if="adding === 'material'"
            :model-value="picked"
            :placeholder="t('Search material by name or SKU…')"
            :empty-text="t('No material matches.')"
            :options="MATERIAL_OPTIONS"
            @update:model-value="$event && addLine($event)"
        />
        <Combobox
            v-else-if="adding === 'cellar'"
            :model-value="picked"
            :placeholder="t('Search wine in the cellar…')"
            :empty-text="t('No ready or ageing wine in the cellar.')"
            :options="CELLAR_OPTIONS"
            @update:model-value="$event && addLine($event)"
        />

        <p v-for="message in itemErrors" :key="message" class="text-xs text-destructive" role="alert">{{ message }}</p>

        <div class="flex flex-wrap items-center gap-3">
            <div v-if="SOURCE_OPTIONS.length" class="w-56">
                <Combobox
                    :model-value="copySource"
                    :options="SOURCE_OPTIONS"
                    :placeholder="t('Copy recipe from…')"
                    :empty-text="t('No product with a recipe matches.')"
                    @update:model-value="copyFrom"
                />
            </div>
            <Button class="ml-auto" :disabled="form.processing || !canSubmit" @click="submit">
                {{ form.processing ? t('Saving…') : t('Save recipe') }}
            </Button>
        </div>
    </div>
</template>
