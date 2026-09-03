<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Disclosure from '@/components/ui/Disclosure.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import FormSection from '@/components/ui/FormSection.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Separator from '@/components/ui/Separator.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import { CUSTOMER_TYPES } from '@/lib/customers';
import type { Customer, PricingTierSummary } from '@/types/customers';
import type { SharedProps } from '@/types';

/**
 * Customer — Create / Edit (Figma `316:80`, `316:695`, `322:848` with Advanced
 * open, and `231:9592` for the edit variant).
 *
 * One component for both: the design draws the same four sections either way,
 * and the only differences are the title, the submit verb and whether the form
 * starts populated. Splitting them would mean maintaining the same field list
 * twice.
 */
const props = defineProps<{ open: boolean; customer: Customer | null }>();
const emit = defineEmits<{ close: [] }>();

const page = usePage<SharedProps>();

const tiers = computed<PricingTierSummary[]>(
    () => (page.props.tiers as PricingTierSummary[] | undefined) ?? [],
);

const isEdit = computed(() => props.customer !== null);

const form = useForm({
    oib: '',
    company_name: '',
    contact_name: '',
    email: '',
    phone: '',
    customer_type: '',
    address: '',
    city: '',
    zip: '',
    state: '',
    country: '',
    pricing_tier_id: '',
    rebate_percent: '',
    exclude_from_stats: false,
    hide_prices: false,
    is_agency: false,
    allow_single_bottle: false,
});

/*
  Populate from the customer being edited, and reset to blank for a create.
  Watching `open` rather than `customer` means reopening the same customer
  discards a half-finished edit rather than resuming it, which is what a
  cancelled form should do.
*/
watch(
    () => [props.open, props.customer?.id],
    () => {
        if (!props.open) return;

        const c = props.customer;

        form.defaults({
            oib: c?.oib ?? '',
            company_name: c?.company_name ?? '',
            contact_name: c?.contact_name ?? '',
            email: c?.email ?? '',
            phone: c?.phone ?? '',
            customer_type: c?.customer_type ?? '',
            address: c?.address ?? '',
            city: c?.city ?? '',
            zip: c?.zip ?? '',
            state: c?.state ?? '',
            country: c?.country ?? '',
            pricing_tier_id: c?.pricing_tier?.id ?? '',
            rebate_percent: c?.rebate_percent ?? '',
            exclude_from_stats: c?.exclude_from_stats ?? false,
            hide_prices: c?.hide_prices ?? false,
            is_agency: c?.is_agency ?? false,
            allow_single_bottle: c?.allow_single_bottle ?? false,
        });
        form.reset();
        form.clearErrors();

        // The tier picker needs the tier list; the customers page loads it
        // lazily, so ask for it the first time a form actually opens.
        if (tiers.value.length === 0) router.reload({ only: ['tiers'] });
    },
    { immediate: true },
);

/*
  OIB / VAT lookup. The endpoint lives on the JSON API (it talks to VIES and is
  not a page), so this is the one place the Inertia app calls it directly.
*/
const lookingUp = ref(false);
const lookupError = ref<string | null>(null);

async function lookUp(): Promise<void> {
    const vat = form.oib.trim();
    if (vat === '' || lookingUp.value) return;

    lookingUp.value = true;
    lookupError.value = null;

    try {
        const response = await fetch(`/api/v1/customers/lookup-vat?vat=${encodeURIComponent(vat)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const body = await response.json();

        if (!response.ok) {
            lookupError.value = typeof body.message === 'string' ? body.message : 'Lookup failed.';

            return;
        }

        // Only fill what is empty: a lookup must never overwrite something the
        // operator has already typed.
        const data = body.data ?? {};
        if (form.company_name === '' && typeof data.name === 'string') form.company_name = data.name;
        if (form.address === '' && typeof data.address === 'string') form.address = data.address;
        if (form.city === '' && typeof data.city === 'string') form.city = data.city;
        if (form.zip === '' && typeof data.zip === 'string') form.zip = data.zip;
        if (form.country === '' && typeof data.country === 'string') form.country = data.country;
    } catch {
        lookupError.value = 'Could not reach the lookup service.';
    } finally {
        lookingUp.value = false;
    }
}

const TIER_OPTIONS = computed(() =>
    tiers.value.map((tier) => ({
        value: tier.id,
        label: tier.name,
        description: `${tier.rebate_percent}%`,
    })),
);

const settingsSummary = computed(() =>
    [
        form.exclude_from_stats ? 'Excluded from stats' : 'Counted in stats',
        form.hide_prices ? 'Prices hidden' : 'Prices shown',
        form.is_agency ? 'Agency' : 'Not an agency',
        form.allow_single_bottle ? 'Single bottles allowed' : 'Cases only',
    ].join(' · '),
);

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        // Editing lands back on the same Customer — Show page, which may
        // already have Pricing/Order History/Komisija/the order link loaded
        // from earlier in this visit. Without `only`, this would be a full
        // (non-partial) visit, and a full visit never resolves Optional
        // props — it would silently reset all of those to unloaded. Creating
        // navigates to a brand-new page instead, so no such prop survives to
        // lose.
        ...(isEdit.value ? { only: ['customer'] } : {}),
    };

    // Blank optional fields are sent as null rather than "", so clearing a
    // field actually clears it instead of storing an empty string.
    form
        .transform((data) => {
            const out: Record<string, unknown> = { ...data };
            for (const key of ['contact_name', 'phone', 'customer_type', 'address', 'city', 'zip', 'state', 'country', 'oib', 'pricing_tier_id']) {
                if (out[key] === '') out[key] = null;
            }
            out.rebate_percent = data.rebate_percent === '' ? 0 : Number(data.rebate_percent);

            return out;
        })
        .submit(
            isEdit.value ? 'patch' : 'post',
            isEdit.value ? `/customers/${props.customer!.id}` : '/customers',
            options,
        );
}
</script>

<template>
    <SidePanel :open="open" :title="isEdit ? 'Edit customer' : 'New customer'" @close="emit('close')">
        <form id="customer-form" class="flex flex-col gap-6" @submit.prevent="submit">
            <FormSection label="Identity">
                <FormField
                    label="OIB / VAT"
                    hint="Enter a Croatian OIB or EU VAT number to auto-fill name and address."
                    :error="form.errors.oib ?? lookupError ?? undefined"
                >
                    <template #default="{ id, invalid }">
                        <div class="flex items-center gap-2">
                            <Input :id="id" v-model="form.oib" :invalid="invalid" class="flex-1" />
                            <Button
                                variant="outline"
                                :disabled="lookingUp || form.oib.trim() === ''"
                                @click="lookUp"
                            >
                                {{ lookingUp ? 'Looking up…' : 'Look up' }}
                            </Button>
                        </div>
                    </template>
                </FormField>
            </FormSection>

            <Separator />

            <FormSection label="Contact">
                <FormField label="Company name" required :error="form.errors.company_name">
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.company_name" :invalid="invalid" />
                    </template>
                </FormField>

                <FormField label="Contact name" :error="form.errors.contact_name">
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.contact_name" :invalid="invalid" />
                    </template>
                </FormField>

                <FormField label="Email" required :error="form.errors.email">
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.email" type="email" :invalid="invalid" />
                    </template>
                </FormField>

                <FieldRow>
                    <FormField label="Phone" :error="form.errors.phone">
                        <template #default="{ id, invalid }">
                            <Input :id="id" v-model="form.phone" :invalid="invalid" />
                        </template>
                    </FormField>
                    <FormField label="Customer type" :error="form.errors.customer_type">
                        <template #default="{ id }">
                            <Select
                                :id="id"
                                v-model="form.customer_type"
                                placeholder="Not set"
                                :options="CUSTOMER_TYPES.map((t) => ({ value: t.value, label: t.label }))"
                            />
                        </template>
                    </FormField>
                </FieldRow>
            </FormSection>

            <Separator />

            <FormSection label="Address">
                <FormField label="Street address" :error="form.errors.address">
                    <template #default="{ id, invalid }">
                        <Input :id="id" v-model="form.address" :invalid="invalid" />
                    </template>
                </FormField>

                <FieldRow>
                    <FormField label="City" :error="form.errors.city">
                        <template #default="{ id, invalid }">
                            <Input :id="id" v-model="form.city" :invalid="invalid" />
                        </template>
                    </FormField>
                    <FormField label="ZIP" :error="form.errors.zip">
                        <template #default="{ id, invalid }">
                            <Input :id="id" v-model="form.zip" :invalid="invalid" />
                        </template>
                    </FormField>
                </FieldRow>

                <FieldRow>
                    <FormField label="Region" :error="form.errors.state">
                        <template #default="{ id, invalid }">
                            <Input :id="id" v-model="form.state" :invalid="invalid" />
                        </template>
                    </FormField>
                    <FormField label="Country" :error="form.errors.country">
                        <template #default="{ id, invalid }">
                            <Input :id="id" v-model="form.country" :invalid="invalid" />
                        </template>
                    </FormField>
                </FieldRow>
            </FormSection>

            <Separator />

            <FormSection label="Pricing">
                <FieldRow>
                    <FormField label="Pricing tier" :error="form.errors.pricing_tier_id">
                        <template #default="{ id }">
                            <Combobox
                                :id="id"
                                :model-value="form.pricing_tier_id === '' ? null : form.pricing_tier_id"
                                placeholder="No tier"
                                empty-text="No tier matches."
                                clearable
                                :options="TIER_OPTIONS"
                                @update:model-value="form.pricing_tier_id = $event ?? ''"
                            />
                        </template>
                    </FormField>
                    <FormField
                        label="Rebate %"
                        hint="Overrides the tier default."
                        :error="form.errors.rebate_percent"
                    >
                        <template #default="{ id, invalid }">
                            <Input
                                :id="id"
                                v-model="form.rebate_percent"
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                :invalid="invalid"
                            />
                        </template>
                    </FormField>
                </FieldRow>
            </FormSection>

            <Disclosure title="Advanced settings" :summary="settingsSummary">
                <SwitchRow
                    v-model="form.exclude_from_stats"
                    label="Exclude from statistics"
                    hint="Orders won't count in dashboard analytics."
                />
                <SwitchRow
                    v-model="form.hide_prices"
                    label="Hide prices on order link"
                    hint="Prices won't show on the self-service order page."
                />
                <SwitchRow
                    v-model="form.is_agency"
                    label="Agency"
                    hint="This customer is a hospitality booking agency."
                />
                <SwitchRow
                    v-model="form.allow_single_bottle"
                    label="Allow single bottle orders"
                    hint="Customer can choose case or single bottle on the portal."
                />
            </Disclosure>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">Cancel</Button>
            <Button type="submit" form="customer-form" :disabled="form.processing">
                {{ isEdit ? 'Save changes' : 'Create customer' }}
            </Button>
        </template>
    </SidePanel>
</template>
