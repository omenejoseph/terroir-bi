<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowLeft, ArrowUp, PencilLine, Plus, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useAuth } from '@/composables/useAuth';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { useUrlTab } from '@/composables/useUrlTab';
import { formatMoney } from '@/lib/money';
import type { VillaDetail, VillaRate, VillaSection } from '@/types/accommodation';
import type { TabItem } from '@/types/ui';

defineOptions({ layout: AppLayout });

/**
 * One villa: its details and the texts its guest portal shows, the nightly rates bookings are priced
 * from, and the portal's free-form sections in order. A villa is retired, never deleted.
 */
const props = defineProps<{ villa: VillaDetail }>();

const { can } = useAuth();
const { t } = useTranslations();
const canManage = computed(() => can('hospitality.manage'));

const TABS = ['general', 'portal', 'pricing'] as const;
const { tab, select: selectTab } = useUrlTab(TABS, 'general');
const TAB_ITEMS = computed<TabItem[]>(() => [
    { value: 'general', label: t('General') },
    { value: 'portal', label: t('Portal') },
    { value: 'pricing', label: t('Pricing') },
]);

/* ---- details + portal texts (one form, two tabs) ------------------------------------ */

const FIELDS = [
    'name', 'address', 'coordinates', 'wifi_name', 'wifi_password', 'check_in_time', 'check_out_time', 'instructions', 'rules',
    'welcome', 'transport', 'amenities', 'emergency_phone', 'contact_phone', 'contact_email', 'logo',
] as const;
type Field = (typeof FIELDS)[number];

const initial = (): Record<Field, string> & { is_active: boolean } => ({
    ...(Object.fromEntries(FIELDS.map((f) => [f, (props.villa[f] as string | null) ?? ''])) as Record<Field, string>),
    is_active: props.villa.is_active,
});

const form = useForm(initial());
watch(
    () => props.villa,
    () => {
        form.defaults(initial());
        form.reset();
    },
);

function save(): void {
    form
        .transform((d) => ({
            ...Object.fromEntries(FIELDS.map((f) => [f, d[f].trim() === '' ? null : d[f].trim()])),
            is_active: d.is_active,
        }))
        .patch(`/accommodation/villas/${props.villa.id}`, { preserveScroll: true });
}

const TEXTS = computed(() => [
    { key: 'welcome' as const, label: t('Welcome') },
    { key: 'instructions' as const, label: t('Arrival instructions') },
    { key: 'rules' as const, label: t('House rules') },
    { key: 'transport' as const, label: t('Getting around') },
    { key: 'amenities' as const, label: t('Amenities') },
]);

/* ---- rates ---------------------------------------------------------------------------- */

const rateOpen = ref(false);
const editingRate = ref<VillaRate | null>(null);
const rateForm = useForm({ label: '', start_date: '', end_date: '', nightly_rate: '', min_nights: '', notes: '' });

function openRate(rate: VillaRate | null): void {
    editingRate.value = rate;
    rateForm.defaults({
        label: rate?.label ?? '',
        start_date: rate?.start_date ?? '',
        end_date: rate?.end_date ?? '',
        nightly_rate: rate ? String(rate.nightly_rate.minor / 100) : '',
        min_nights: rate?.min_nights ? String(rate.min_nights) : '',
        notes: rate?.notes ?? '',
    });
    rateForm.reset();
    rateForm.clearErrors();
    rateOpen.value = true;
}

function saveRate(): void {
    const base = `/accommodation/villas/${props.villa.id}/rates`;
    rateForm
        .transform((d) => ({
            label: d.label.trim(),
            start_date: d.start_date,
            end_date: d.end_date,
            nightly_rate: Math.round(Number.parseFloat(d.nightly_rate.replace(',', '.')) * 100),
            min_nights: d.min_nights.trim() === '' ? null : Number(d.min_nights),
            notes: d.notes.trim() === '' ? null : d.notes.trim(),
        }))
        .submit(editingRate.value ? 'patch' : 'post', editingRate.value ? `${base}/${editingRate.value.id}` : base, {
            preserveScroll: true,
            onSuccess: () => (rateOpen.value = false),
        });
}

async function deleteRate(rate: VillaRate): Promise<void> {
    const ok = await confirmDialog({ title: t('Remove :name?', { name: rate.label }), description: t('Existing bookings keep their price.'), confirmLabel: t('Remove'), tone: 'danger' });
    if (ok) router.delete(`/accommodation/villas/${props.villa.id}/rates/${rate.id}`, { preserveScroll: true });
}

/* ---- sections ------------------------------------------------------------------------- */

const sectionOpen = ref(false);
const editingSection = ref<VillaSection | null>(null);
const sectionForm = useForm({ title: '', icon: '', content: '', is_active: true });

function openSection(section: VillaSection | null): void {
    editingSection.value = section;
    sectionForm.defaults({ title: section?.title ?? '', icon: section?.icon ?? '', content: section?.content ?? '', is_active: section?.is_active ?? true });
    sectionForm.reset();
    sectionForm.clearErrors();
    sectionOpen.value = true;
}

function saveSection(): void {
    const base = `/accommodation/villas/${props.villa.id}/sections`;
    sectionForm
        .transform((d) => ({ title: d.title.trim(), icon: d.icon.trim() || null, content: d.content.trim() || null, is_active: d.is_active }))
        .submit(editingSection.value ? 'patch' : 'post', editingSection.value ? `${base}/${editingSection.value.id}` : base, {
            preserveScroll: true,
            onSuccess: () => (sectionOpen.value = false),
        });
}

function move(index: number, by: -1 | 1): void {
    const ids = props.villa.sections.map((s) => s.id);
    const target = index + by;
    if (target < 0 || target >= ids.length) return;
    [ids[index], ids[target]] = [ids[target]!, ids[index]!];
    router.post(`/accommodation/villas/${props.villa.id}/sections/reorder`, { ids }, { preserveScroll: true });
}

async function deleteSection(section: VillaSection): Promise<void> {
    const ok = await confirmDialog({ title: t('Remove :name?', { name: section.title }), confirmLabel: t('Remove'), tone: 'danger' });
    if (ok) router.delete(`/accommodation/villas/${props.villa.id}/sections/${section.id}`, { preserveScroll: true });
}

const day = (d: string): string => new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { day: '2-digit', month: '2-digit', year: 'numeric' });
</script>

<template>
    <Head :title="villa.name" />
    <div class="space-y-5">
        <Link href="/accommodation?tab=villas" class="inline-flex items-center gap-2 text-xs text-muted-foreground hover:text-foreground">
            <ArrowLeft class="size-3.5" :stroke-width="1.5" />
            {{ t('Villas') }}
        </Link>

        <div class="flex flex-wrap items-center gap-2">
            <h2 class="text-xl font-semibold text-foreground">{{ villa.name }}</h2>
            <Badge v-if="!villa.is_active" variant="outline">{{ t('Inactive') }}</Badge>
        </div>

        <Tabs :items="TAB_ITEMS" :current="tab" @select="selectTab" />

        <!-- General + portal texts share one form and one Save. -->
        <form v-if="tab !== 'pricing'" class="space-y-5" @submit.prevent="save">
            <section v-if="tab === 'general'" class="grid grid-cols-1 gap-4 border border-border bg-card p-4 sm:grid-cols-2">
                <FormField v-slot="{ id, invalid }" :label="t('Name')" required :error="form.errors.name">
                    <Input :id="id" v-model="form.name" :invalid="invalid" maxlength="255" :disabled="!canManage" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Address')" :error="form.errors.address">
                    <Input :id="id" v-model="form.address" :invalid="invalid" maxlength="255" :disabled="!canManage" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Coordinates')" :hint="t('e.g. 43.7350, 15.8952')" :error="form.errors.coordinates">
                    <Input :id="id" v-model="form.coordinates" :invalid="invalid" maxlength="255" :disabled="!canManage" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Logo URL')" :error="form.errors.logo">
                    <Input :id="id" v-model="form.logo" :invalid="invalid" maxlength="2048" :disabled="!canManage" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Check-in time')" :error="form.errors.check_in_time">
                    <Input :id="id" v-model="form.check_in_time" type="time" :invalid="invalid" :disabled="!canManage" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Check-out time')" :error="form.errors.check_out_time">
                    <Input :id="id" v-model="form.check_out_time" type="time" :invalid="invalid" :disabled="!canManage" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Wi-Fi name')" :error="form.errors.wifi_name">
                    <Input :id="id" v-model="form.wifi_name" :invalid="invalid" maxlength="255" :disabled="!canManage" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Wi-Fi password')" :error="form.errors.wifi_password">
                    <Input :id="id" v-model="form.wifi_password" :invalid="invalid" maxlength="255" :disabled="!canManage" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Contact phone')" :error="form.errors.contact_phone">
                    <Input :id="id" v-model="form.contact_phone" type="tel" :invalid="invalid" maxlength="255" :disabled="!canManage" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Contact email')" :error="form.errors.contact_email">
                    <Input :id="id" v-model="form.contact_email" type="email" :invalid="invalid" maxlength="255" :disabled="!canManage" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Emergency phone')" :error="form.errors.emergency_phone">
                    <Input :id="id" v-model="form.emergency_phone" type="tel" :invalid="invalid" maxlength="255" :disabled="!canManage" />
                </FormField>
                <div class="sm:col-span-2">
                    <SwitchRow v-model="form.is_active" :label="t('Active')" :hint="t('An inactive villa keeps its bookings but takes no new ones.')" />
                </div>
            </section>

            <template v-else>
                <section class="grid grid-cols-1 gap-4 border border-border bg-card p-4">
                    <FormField v-for="text in TEXTS" :key="text.key" v-slot="{ id }" :label="text.label" :error="form.errors[text.key]">
                        <Textarea :id="id" v-model="form[text.key]" :rows="4" :placeholder="t('Optional')" />
                    </FormField>
                </section>

                <section class="border border-border bg-card">
                    <div class="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
                        <h3 class="text-sm font-semibold">{{ t('Portal sections') }}</h3>
                        <Button v-if="canManage" variant="outline" size="sm" @click="openSection(null)">
                            <Plus class="size-3.5" :stroke-width="1.5" />
                            {{ t('Add section') }}
                        </Button>
                    </div>
                    <p v-if="villa.sections.length === 0" class="px-4 py-8 text-center text-xs text-muted-foreground">{{ t('No sections yet.') }}</p>
                    <ul v-else class="divide-y divide-border">
                        <li v-for="(s, i) in villa.sections" :key="s.id" class="flex items-start gap-3 px-4 py-3 text-xs">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium" :class="s.is_active ? '' : 'text-muted-foreground line-through'">{{ s.title }}</p>
                                <p v-if="s.content" class="mt-0.5 line-clamp-2 whitespace-pre-line text-muted-foreground">{{ s.content }}</p>
                            </div>
                            <div v-if="canManage" class="flex shrink-0 gap-1">
                                <button type="button" class="p-1 text-muted-foreground hover:text-foreground disabled:opacity-30" :disabled="i === 0" :aria-label="t('Move up')" @click="move(i, -1)">
                                    <ArrowUp class="size-3.5" :stroke-width="1.5" />
                                </button>
                                <button type="button" class="p-1 text-muted-foreground hover:text-foreground disabled:opacity-30" :disabled="i === villa.sections.length - 1" :aria-label="t('Move down')" @click="move(i, 1)">
                                    <ArrowDown class="size-3.5" :stroke-width="1.5" />
                                </button>
                                <button type="button" class="p-1 text-muted-foreground hover:text-foreground" :aria-label="t('Edit')" @click="openSection(s)">
                                    <PencilLine class="size-3.5" :stroke-width="1.5" />
                                </button>
                                <button type="button" class="p-1 text-muted-foreground hover:text-destructive" :aria-label="t('Remove')" @click="deleteSection(s)">
                                    <Trash2 class="size-3.5" :stroke-width="1.5" />
                                </button>
                            </div>
                        </li>
                    </ul>
                </section>
            </template>

            <div v-if="canManage" class="flex justify-end">
                <Button type="submit" :disabled="form.processing || form.name.trim() === '' || !form.isDirty">{{ t('Save changes') }}</Button>
            </div>
        </form>

        <!-- ================= Pricing ================= -->
        <section v-else class="border border-border bg-card">
            <div class="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
                <div>
                    <h3 class="text-sm font-semibold">{{ t('Nightly rates') }}</h3>
                    <p class="text-xs text-muted-foreground">{{ t('Both dates are included. Where rates overlap, the one starting latest applies.') }}</p>
                </div>
                <Button v-if="canManage" variant="outline" size="sm" @click="openRate(null)">
                    <Plus class="size-3.5" :stroke-width="1.5" />
                    {{ t('Add rate') }}
                </Button>
            </div>
            <p v-if="villa.rates.length === 0" class="px-4 py-8 text-center text-xs text-muted-foreground">{{ t('No rates yet — bookings would be priced at 0.') }}</p>
            <ul v-else class="divide-y divide-border">
                <li v-for="r in villa.rates" :key="r.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-xs">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ r.label }}</span>
                        <span class="text-muted-foreground tabular-nums">{{ day(r.start_date) }} – {{ day(r.end_date) }}<template v-if="r.min_nights"> · {{ t('min :count nights', { count: r.min_nights }) }}</template></span>
                    </span>
                    <span class="font-semibold tabular-nums">{{ t(':price / night', { price: formatMoney(r.nightly_rate.minor, r.nightly_rate.currency) }) }}</span>
                    <span v-if="canManage" class="flex shrink-0 gap-1">
                        <button type="button" class="p-1 text-muted-foreground hover:text-foreground" :aria-label="t('Edit')" @click="openRate(r)">
                            <PencilLine class="size-3.5" :stroke-width="1.5" />
                        </button>
                        <button type="button" class="p-1 text-muted-foreground hover:text-destructive" :aria-label="t('Remove')" @click="deleteRate(r)">
                            <Trash2 class="size-3.5" :stroke-width="1.5" />
                        </button>
                    </span>
                </li>
            </ul>
        </section>
    </div>

    <SidePanel v-if="canManage" :open="rateOpen" :title="editingRate ? t('Edit rate') : t('New rate')" @close="rateOpen = false">
        <form id="rate-form" class="flex flex-col gap-5" @submit.prevent="saveRate">
            <FormField v-slot="{ id, invalid }" :label="t('Label')" required :error="rateForm.errors.label">
                <Input :id="id" v-model="rateForm.label" :invalid="invalid" maxlength="255" :placeholder="t('e.g. High season')" />
            </FormField>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField v-slot="{ id, invalid }" :label="t('From')" required :error="rateForm.errors.start_date">
                    <Input :id="id" v-model="rateForm.start_date" type="date" :invalid="invalid" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('To (included)')" required :error="rateForm.errors.end_date">
                    <Input :id="id" v-model="rateForm.end_date" type="date" :invalid="invalid" :min="rateForm.start_date || undefined" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Per night')" required :error="rateForm.errors.nightly_rate">
                    <Input :id="id" v-model="rateForm.nightly_rate" type="number" min="0" step="0.01" inputmode="decimal" :invalid="invalid" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Minimum nights')" :hint="t('Shown only; not enforced.')" :error="rateForm.errors.min_nights">
                    <Input :id="id" v-model="rateForm.min_nights" type="number" min="1" step="1" :invalid="invalid" />
                </FormField>
            </div>
            <FormField v-slot="{ id }" :label="t('Notes')" :error="rateForm.errors.notes">
                <Textarea :id="id" v-model="rateForm.notes" :rows="2" :placeholder="t('Optional')" />
            </FormField>
        </form>
        <template #footer>
            <Button variant="outline" @click="rateOpen = false">{{ t('Cancel') }}</Button>
            <Button type="submit" form="rate-form" :disabled="rateForm.processing || rateForm.label.trim() === '' || rateForm.start_date === '' || rateForm.end_date === '' || rateForm.nightly_rate === ''">
                {{ t('Save') }}
            </Button>
        </template>
    </SidePanel>

    <SidePanel v-if="canManage" :open="sectionOpen" :title="editingSection ? t('Edit section') : t('New section')" @close="sectionOpen = false">
        <form id="section-form" class="flex flex-col gap-5" @submit.prevent="saveSection">
            <FormField v-slot="{ id, invalid }" :label="t('Title')" required :error="sectionForm.errors.title">
                <Input :id="id" v-model="sectionForm.title" :invalid="invalid" maxlength="255" />
            </FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Icon')" :hint="t('An icon name, e.g. beach, wine, car')" :error="sectionForm.errors.icon">
                <Input :id="id" v-model="sectionForm.icon" :invalid="invalid" maxlength="64" />
            </FormField>
            <FormField v-slot="{ id }" :label="t('Content')" :error="sectionForm.errors.content">
                <Textarea :id="id" v-model="sectionForm.content" :rows="8" />
            </FormField>
            <SwitchRow v-model="sectionForm.is_active" :label="t('Active')" :hint="t('Inactive sections are hidden from guests.')" />
        </form>
        <template #footer>
            <Button variant="outline" @click="sectionOpen = false">{{ t('Cancel') }}</Button>
            <Button type="submit" form="section-form" :disabled="sectionForm.processing || sectionForm.title.trim() === ''">{{ t('Save') }}</Button>
        </template>
    </SidePanel>
</template>
