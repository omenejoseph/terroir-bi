<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import CardHeader from '@/components/ui/CardHeader.vue';
import CardTitle from '@/components/ui/CardTitle.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import Input from '@/components/ui/Input.vue';
import InputError from '@/components/ui/InputError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import type { SharedProps } from '@/types';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * The setup welcome (docs/21-onboarding-plan.md, phase 2): three questions,
 * then the starter categories and pricing tiers they suggest — every
 * suggestion is a ticked box the admin can untick, rename or add to before
 * anything is created (App\Actions\Onboarding\ApplyWelcomeAnswersAction).
 * Names that already exist are shown as such and never created twice.
 */
const props = defineProps<{
    answers: { sells?: string; channels?: string[]; has_file?: boolean } | null;
    wineCategories: string[];
    otherCategory: string;
    /** Channel key => suggested tier name, in the tenant's language. */
    channelTiers: Record<string, string>;
    existingCategories: string[];
    existingTiers: string[];
}>();

const page = usePage<SharedProps>();
const { hasModule } = useAuth();
const { t } = useTranslations();

const CHANNEL_LABELS = computed<Record<string, string>>(() => ({
    wholesale: t('Wholesale (restaurants, shops)'),
    retail: t('Retail (private buyers)'),
    agency: t('Agencies / distributors'),
    shipshop: t('Ship & shop'),
}));

interface CategoryRow {
    name: string;
    has_vintage: boolean;
    picked: boolean;
}

interface TierRow {
    /** The channel it was suggested for. */
    channel: string;
    name: string;
    rebate_percent: string;
    picked: boolean;
}

const sells = ref<string>(props.answers?.sells ?? 'wine');
const channels = ref<string[]>(props.answers?.channels ?? ['wholesale']);
const hasFile = ref<boolean>(props.answers?.has_file ?? false);

const lower = (names: string[]): Set<string> => new Set(names.map((n) => n.trim().toLowerCase()));
const existingCategoryNames = computed(() => lower(props.existingCategories));
const existingTierNames = computed(() => lower(props.existingTiers));

const categories = ref<CategoryRow[]>(props.wineCategories.map((name) => ({ name, has_vintage: true, picked: true })));
const newCategory = ref('');

/** "Other products" follows the first answer: there when they sell more than wine, gone when not (unless edited). */
watch(
    sells,
    (value) => {
        const index = categories.value.findIndex((c) => c.name === props.otherCategory);

        if (value === 'wine_and_more' && index === -1) {
            categories.value.push({ name: props.otherCategory, has_vintage: false, picked: true });
        } else if (value === 'wine' && index !== -1) {
            categories.value.splice(index, 1);
        }
    },
    { immediate: true },
);

function addCategory(): void {
    const name = newCategory.value.trim();
    if (name === '' || categories.value.some((c) => c.name.toLowerCase() === name.toLowerCase())) return;

    categories.value.push({ name, has_vintage: true, picked: true });
    newCategory.value = '';
}

/** One tier per channel sold to; rebates start empty (0 %) for the admin to fill in. */
const tiers = ref<TierRow[]>([]);

watch(
    channels,
    (picked) => {
        const kept = tiers.value.filter((tier) => picked.includes(tier.channel));
        const added = picked
            .filter((key) => props.channelTiers[key] !== undefined && !kept.some((tier) => tier.channel === key))
            .map((key) => ({ channel: key, name: props.channelTiers[key]!, rebate_percent: '', picked: true }));

        tiers.value = [...kept, ...added];
    },
    { immediate: true, deep: true },
);

function toggleChannel(key: string, on: boolean): void {
    channels.value = on ? [...channels.value, key] : channels.value.filter((c) => c !== key);
}

const form = useForm({});
const errors = computed(() => form.errors as Record<string, string>);

function submit(): void {
    form.transform(() => ({
        sells: sells.value,
        channels: channels.value,
        has_file: hasFile.value,
        categories: hasModule('inventory')
            ? categories.value
                  .filter((c) => c.picked && !existingCategoryNames.value.has(c.name.trim().toLowerCase()))
                  .map((c) => ({ name: c.name.trim(), has_vintage: c.has_vintage }))
            : [],
        tiers: hasModule('customers')
            ? tiers.value
                  .filter((tier) => tier.picked && !existingTierNames.value.has(tier.name.trim().toLowerCase()))
                  .map((tier) => ({
                      name: tier.name.trim(),
                      rebate_percent: tier.rebate_percent.trim() === '' ? 0 : Number(tier.rebate_percent.replace(',', '.')),
                  }))
            : [],
    })).post('/setup/welcome');
}

function skip(): void {
    router.post('/setup/welcome/skip');
}
</script>

<template>
    <Head :title="t('Welcome')" />
    <div class="max-w-2xl space-y-5">
        <PageHeader
            :title="t('Welcome to Terroir, :name', { name: page.props.org?.name ?? '' })"
            :description="t('Three quick questions, and we\'ll prepare your product categories and pricing tiers. You can change everything later.')"
        />

        <form class="space-y-5" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('1. What do you sell?') }}</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="sells" type="radio" value="wine" class="accent-primary" />
                        {{ t('Our own wine') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="sells" type="radio" value="wine_and_more" class="accent-primary" />
                        {{ t('Our wine, plus other products (olive oil, merchandise, …)') }}
                    </label>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ t('2. Who do you sell to?') }}</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2">
                    <Checkbox
                        v-for="(label, key) in CHANNEL_LABELS"
                        :key="key"
                        :model-value="channels.includes(String(key))"
                        :label="label"
                        @update:model-value="toggleChannel(String(key), $event)"
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ t('3. Do you have your product list in a spreadsheet?') }}</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="hasFile" type="radio" :value="true" class="accent-primary" />
                        {{ t('Yes — I\'ll import it') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="hasFile" type="radio" :value="false" class="accent-primary" />
                        {{ t('No — I\'ll type them in') }}
                    </label>
                </CardContent>
            </Card>

            <Card v-if="hasModule('inventory')">
                <CardHeader>
                    <CardTitle>{{ t('Starter categories') }}</CardTitle>
                    <p class="text-xs text-muted-foreground">{{ t('Untick any you don\'t need. Subcategories (e.g. by grape) can be added later.') }}</p>
                </CardHeader>
                <CardContent class="space-y-2">
                    <div v-for="(category, i) in categories" :key="i" class="flex items-center gap-3">
                        <Checkbox
                            v-model="category.picked"
                            :label="category.name"
                            hide-label
                            :disabled="existingCategoryNames.has(category.name.trim().toLowerCase())"
                        />
                        <Input v-model="category.name" class="flex-1" maxlength="100" :disabled="!category.picked" />
                        <span v-if="existingCategoryNames.has(category.name.trim().toLowerCase())" class="shrink-0 text-xs text-muted-foreground">
                            {{ t('Already exists') }}
                        </span>
                        <label v-else class="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground">
                            <input v-model="category.has_vintage" type="checkbox" class="accent-primary" :disabled="!category.picked" />
                            {{ t('Has vintages') }}
                        </label>
                    </div>
                    <div class="flex items-center gap-2 pt-1">
                        <Input v-model="newCategory" class="flex-1" maxlength="100" :placeholder="t('Add another category')" @keydown.enter.prevent="addCategory" />
                        <Button variant="outline" size="sm" :disabled="newCategory.trim() === ''" @click="addCategory">
                            <Plus class="size-3.5" :stroke-width="1.5" />
                            {{ t('Add') }}
                        </Button>
                    </div>
                    <InputError :message="errors['categories']" />
                </CardContent>
            </Card>

            <Card v-if="hasModule('customers') && tiers.length > 0">
                <CardHeader>
                    <CardTitle>{{ t('Pricing tiers') }}</CardTitle>
                    <p class="text-xs text-muted-foreground">
                        {{ t('One per channel you sell to. The rebate is taken off your list price for every customer on the tier — leave it at 0 if they pay list price.') }}
                    </p>
                </CardHeader>
                <CardContent class="space-y-2">
                    <div v-for="(tier, i) in tiers" :key="i" class="flex items-center gap-3">
                        <Checkbox
                            v-model="tier.picked"
                            :label="tier.name"
                            hide-label
                            :disabled="existingTierNames.has(tier.name.trim().toLowerCase())"
                        />
                        <Input v-model="tier.name" class="flex-1" maxlength="255" :disabled="!tier.picked" />
                        <span v-if="existingTierNames.has(tier.name.trim().toLowerCase())" class="shrink-0 text-xs text-muted-foreground">
                            {{ t('Already exists') }}
                        </span>
                        <div v-else class="flex w-28 shrink-0 items-center gap-1">
                            <Input
                                v-model="tier.rebate_percent"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                placeholder="0"
                                :aria-label="t('Rebate %')"
                                :disabled="!tier.picked"
                                :invalid="Boolean(errors[`tiers.${i}.rebate_percent`])"
                            />
                            <span class="text-xs text-muted-foreground">%</span>
                        </div>
                    </div>
                    <InputError :message="errors['tiers']" />
                </CardContent>
            </Card>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <button type="button" class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground" @click="skip">
                    <X class="size-3.5" :stroke-width="1.5" />
                    {{ t('Not now — take me to the checklist') }}
                </button>
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? t('Saving…') : t('Continue') }}
                </Button>
            </div>
        </form>
    </div>
</template>
