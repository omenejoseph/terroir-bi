<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import CardContent from '@/components/ui/CardContent.vue';
import CardHeader from '@/components/ui/CardHeader.vue';
import CardTitle from '@/components/ui/CardTitle.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Select from '@/components/ui/Select.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { Org } from '@/types';
import type { TabItem } from '@/types/ui';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * Organisation settings (nav's "System · Settings", capability
 * `settings.manage`) — the Inertia counterpart of Api\SettingsController.
 * Both this page and the JSON API read/write the same OrganizationSettingsData
 * / UpdateSettingsAction, so they can never disagree about what's stored.
 *
 * The revenue-target and cash-on-hand fields exist for one reason: the
 * Dashboard's "Revenue vs. target" and "Runway" cards (Figma 208:5577,
 * 208:5808) have nowhere else to get a real number — see
 * docs/design/README.md. Leaving any of them blank is what keeps those cards
 * showing "not set" instead of a manufactured figure.
 */
const props = defineProps<{
    settings: Org;
    localeOptions: { value: string; label: string }[];
    timezoneOptions: string[];
}>();

const { t } = useTranslations();

/** Channels a target can be set on — App\Enums\CustomerType::channelKey(); "other" is a catch-all, never a real target. */
const CHANNELS: { key: string; label: string }[] = [
    { key: 'wholesale', label: t('Wholesale') },
    { key: 'retail', label: t('Retail') },
    { key: 'agency', label: t('Agency') },
    { key: 'shipshop', label: t('Ship & shop') },
];

/** Minor units to a "1234.00"-shaped string for an input; '' for null/zero-less-than-0. */
function toMajorString(minor: number | null): string {
    return minor === null ? '' : String(minor / 100);
}

/** The inverse — '', whitespace, or anything unparseable becomes null, never 0. */
function toMinor(value: string): number | null {
    if (value.trim() === '') return null;

    const n = Number.parseFloat(value.replace(',', '.'));

    return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) : null;
}

const timezoneOptions = props.timezoneOptions.map((tz) => ({ value: tz, label: tz }));

const form = useForm({
    name: props.settings.name,
    default_locale: props.settings.default_locale,
    timezone: props.settings.timezone,
    company_oib: props.settings.company_oib ?? '',
    annual_revenue_target: toMajorString(props.settings.annual_revenue_target),
    channel_revenue_targets: Object.fromEntries(
        CHANNELS.map((c) => [c.key, toMajorString(props.settings.channel_revenue_targets?.[c.key] ?? null)]),
    ) as Record<string, string>,
    cash_on_hand: toMajorString(props.settings.cash_on_hand),
    cash_on_hand_as_of: props.settings.cash_on_hand_as_of ?? '',
});

/*
  One form, three tabs: every field stays in the same form so a single Save writes them all, and the
  tab only decides which cards show. The chosen tab is kept in the URL (?tab=) so a refresh or a link
  lands on it.
*/
type SettingsTab = 'organisation' | 'revenue-target' | 'cash-on-hand';

const TABS = computed<TabItem[]>(() => [
    { value: 'organisation', label: t('Organisation') },
    { value: 'revenue-target', label: t('Revenue target') },
    { value: 'cash-on-hand', label: t('Cash on hand') },
]);

const FIELD_TAB: Record<string, SettingsTab> = {
    annual_revenue_target: 'revenue-target',
    channel_revenue_targets: 'revenue-target',
    cash_on_hand: 'cash-on-hand',
    cash_on_hand_as_of: 'cash-on-hand',
};

function initialTab(): SettingsTab {
    const wanted = new URLSearchParams(window.location.search).get('tab');

    return TABS.value.some((tab) => tab.value === wanted) ? (wanted as SettingsTab) : 'organisation';
}

const tab = ref<SettingsTab>(initialTab());

function selectTab(value: string): void {
    tab.value = value as SettingsTab;

    const url = new URL(window.location.href);
    url.searchParams.set('tab', value);
    window.history.replaceState(window.history.state, '', url);
}

/** A validation error on a tab you are not looking at would otherwise be invisible: jump to it. */
watch(
    () => form.errors,
    (errors) => {
        const keys = Object.keys(errors);
        if (keys.length === 0) return;

        const owner = keys.map((key) => FIELD_TAB[key.split('.')[0]!] ?? 'organisation');

        if (!owner.includes(tab.value)) selectTab(owner[0]!);
    },
);

function submit(): void {
    form
        .transform((data) => ({
            ...data,
            company_oib: data.company_oib.trim() === '' ? null : data.company_oib,
            annual_revenue_target: toMinor(data.annual_revenue_target),
            channel_revenue_targets: Object.fromEntries(
                Object.entries(data.channel_revenue_targets)
                    .map(([key, value]) => [key, toMinor(value)])
                    .filter(([, value]) => value !== null),
            ),
            cash_on_hand: toMinor(data.cash_on_hand),
            cash_on_hand_as_of: data.cash_on_hand_as_of.trim() === '' ? null : data.cash_on_hand_as_of,
        }))
        .patch('/settings', { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Settings')" />
        <div class="max-w-2xl space-y-5">
            <PageHeader :title="t('Settings')" :description="t('Organisation-wide configuration.')" />

            <Tabs :items="TABS" :current="tab" @select="selectTab" />

            <form class="space-y-5" @submit.prevent="submit">
                <Card v-show="tab === 'organisation'">
                    <CardHeader>
                        <CardTitle>{{ t('Organisation') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <FormField v-slot="{ id, invalid }" :label="t('Company name')" required :error="form.errors.name">
                            <Input :id="id" v-model="form.name" :invalid="invalid" maxlength="255" />
                        </FormField>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField v-slot="{ id, invalid }" :label="t('Language')" required :error="form.errors.default_locale">
                                <Select :id="id" v-model="form.default_locale" :invalid="invalid" :options="localeOptions" />
                            </FormField>

                            <FormField v-slot="{ id, invalid }" :label="t('Timezone')" required :error="form.errors.timezone">
                                <Select :id="id" v-model="form.timezone" :invalid="invalid" :options="timezoneOptions" />
                            </FormField>
                        </div>

                        <FormField
                            v-slot="{ id, invalid }"
                            :label="t('Tax ID')"
                            :hint="t('Croatian OIB, if applicable.')"
                            :error="form.errors.company_oib"
                        >
                            <Input :id="id" v-model="form.company_oib" :invalid="invalid" maxlength="32" />
                        </FormField>

                        <FormField :label="t('Currency')" :hint="t('Read-only — changing it would relabel stored amounts, not convert them.')">
                            <Input :model-value="settings.default_currency" disabled />
                        </FormField>
                    </CardContent>
                </Card>

                <Card v-show="tab === 'revenue-target'">
                    <CardHeader>
                        <CardTitle>{{ t('Revenue target') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <p class="text-xs text-muted-foreground">
                            {{ t('Backs the Dashboard\'s "Revenue vs. target" card. Leave blank to keep it showing "not set" instead of a number.') }}
                        </p>

                        <FormField
                            v-slot="{ id, invalid }"
                            :label="t('Annual target')"
                            :error="form.errors.annual_revenue_target"
                        >
                            <Input
                                :id="id"
                                v-model="form.annual_revenue_target"
                                :invalid="invalid"
                                type="number"
                                step="0.01"
                                min="0"
                                :placeholder="t('Not set')"
                            />
                        </FormField>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField
                                v-for="channel in CHANNELS"
                                :key="channel.key"
                                v-slot="{ id, invalid }"
                                :label="t(':channel target', { channel: channel.label })"
                                :error="(form.errors as Record<string, string>)[`channel_revenue_targets.${channel.key}`]"
                            >
                                <Input
                                    :id="id"
                                    v-model="form.channel_revenue_targets[channel.key]"
                                    :invalid="invalid"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :placeholder="t('Not set')"
                                />
                            </FormField>
                        </div>
                    </CardContent>
                </Card>

                <Card v-show="tab === 'cash-on-hand'">
                    <CardHeader>
                        <CardTitle>{{ t('Cash on hand') }}</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <p class="text-xs text-muted-foreground">
                            {{ t('Backs the Dashboard\'s "Runway" card. The burn rate is computed from real costs and income; this figure has to come from you.') }}
                        </p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField v-slot="{ id, invalid }" :label="t('Cash on hand')" :error="form.errors.cash_on_hand">
                                <Input
                                    :id="id"
                                    v-model="form.cash_on_hand"
                                    :invalid="invalid"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :placeholder="t('Not set')"
                                />
                            </FormField>

                            <FormField v-slot="{ id, invalid }" :label="t('As of')" :error="form.errors.cash_on_hand_as_of">
                                <Input :id="id" v-model="form.cash_on_hand_as_of" :invalid="invalid" type="date" />
                            </FormField>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-end">
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? t('Saving…') : t('Save changes') }}
                    </Button>
                </div>
            </form>
        </div>
</template>
