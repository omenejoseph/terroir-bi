<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Input from '@/components/ui/Input.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { InventoryItem, RecipeInputOption, RecipeLine } from '@/types/inventory';
import type { ComboboxOption } from '@/types/ui';

/**
 * Product Detail · Recipe tab (Figma 449:1577): the item's bill of
 * materials — one line per catalog input and how much of it this item
 * consumes. Saved as a whole replacement (App\Actions\Inventory\
 * SetRecipeAction), the same shape the JSON API's own recipe editor writes.
 *
 * Only catalog lines can be added here — App\Models\RecipeItem's own
 * "custom ingredient" fields exist in the schema, but no write path (this
 * one included, and the JSON API's) has ever created one, so this editor
 * does not invent that surface either.
 */
const props = defineProps<{
    item: InventoryItem;
    lines: RecipeLine[];
    /** Every other active catalog item — undefined until this tab first asks for it. */
    inputs: RecipeInputOption[] | undefined;
}>();

const { t } = useTranslations();

interface Draft {
    key: string;
    input_id: string;
    input_name: string;
    quantity: string;
}

let seq = 0;
const nextKey = (): string => `line-${(seq += 1)}`;

const draft = ref<Draft[]>([]);

/* Repopulate fresh whenever the server's own lines change — after a save, or
   when switching back onto this tab. */
watch(
    () => props.lines,
    (lines) => {
        draft.value = lines
            .filter((line) => line.input_id !== null)
            .map((line) => ({
                key: nextKey(),
                input_id: line.input_id as string,
                input_name: line.input_name,
                quantity: line.quantity,
            }));
    },
    { immediate: true },
);

onMounted(() => {
    if (props.inputs === undefined) {
        router.reload({ only: ['recipeInputOptions'] });
    }
});

const picked = ref<string | null>(null);

const OPTIONS = computed<ComboboxOption[]>(() =>
    (props.inputs ?? [])
        .filter((input) => !draft.value.some((line) => line.input_id === input.id))
        .map((input) => ({
            value: input.id,
            label: [input.name, input.vintage].filter(Boolean).join(' '),
            description: [input.sku, input.unit].filter(Boolean).join(' · '),
            keywords: [input.sku, input.group].filter(Boolean) as string[],
        })),
);

function addLine(id: string): void {
    const input = (props.inputs ?? []).find((i) => i.id === id);
    if (!input) return;

    draft.value = [...draft.value, { key: nextKey(), input_id: input.id, input_name: input.name, quantity: '1' }];
    picked.value = null;
}

function removeLine(key: string): void {
    draft.value = draft.value.filter((line) => line.key !== key);
}

const form = useForm<{ items: { input_id: string; quantity: string }[] }>({ items: [] });

/* Saving an empty recipe (every line removed) is valid — it clears the bill
   of materials — so the only real guard is that no remaining line is blank. */
const canSubmit = computed(() => draft.value.every((line) => line.quantity.trim() !== ''));

function submit(): void {
    form.items = draft.value.map((line) => ({ input_id: line.input_id, quantity: line.quantity }));

    form.put(`/inventory/${props.item.id}/recipe`, {
        preserveScroll: true,
        // See CustomerPriceDialog.vue's submit() for why a plain patch/put
        // without `only` would silently drop every other Optional prop this
        // page may already have loaded (recipeInputOptions, pricing pickers).
        only: ['recipe'],
    });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <p class="text-sm text-muted-foreground">
            {{ t('What this item consumes to produce one unit — used by the Produce tab.') }}
        </p>

        <div v-if="draft.length" class="flex flex-col divide-y divide-border border border-border">
            <div v-for="line in draft" :key="line.key" class="flex items-center gap-3 px-4 py-3">
                <span class="min-w-0 flex-1 truncate text-sm">{{ line.input_name }}</span>
                <Input
                    v-model="line.quantity"
                    inputmode="decimal"
                    class="w-28"
                    :aria-label="t('Quantity of :name', { name: line.input_name })"
                />
                <button
                    type="button"
                    class="shrink-0 p-1.5 text-muted-foreground transition-colors hover:text-destructive"
                    :aria-label="t('Remove :name', { name: line.input_name })"
                    @click="removeLine(line.key)"
                >
                    <Trash2 class="size-4" :stroke-width="1.5" />
                </button>
            </div>
        </div>
        <p v-else class="text-xs text-muted-foreground">{{ t('No ingredients added yet.') }}</p>

        <Combobox
            :model-value="picked"
            :placeholder="t('Search catalog item by name or SKU…')"
            :empty-text="t('No item matches.')"
            :options="OPTIONS"
            @update:model-value="$event && addLine($event)"
        />

        <p v-if="form.errors.items" class="text-xs text-destructive" role="alert">{{ form.errors.items }}</p>

        <Button class="self-start" :disabled="form.processing || !canSubmit" @click="submit">
            {{ form.processing ? t('Saving…') : t('Save recipe') }}
        </Button>
    </div>
</template>
