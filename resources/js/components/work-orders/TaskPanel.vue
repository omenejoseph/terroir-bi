<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Disclosure from '@/components/ui/Disclosure.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Separator from '@/components/ui/Separator.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { CATEGORY_LABELS, PRIORITY_LABELS, STATUS_LABELS } from '@/lib/work-orders';
import type { TaskStatusKey, WorkOrder, WorkOrderCategoryKey } from '@/types/work-orders';
import type { ComboboxOption, TabItem } from '@/types/ui';
import type { SharedProps } from '@/types';

/**
 * New / View Task (Figma `317:369`, `318:402`, `321:618` and `324:862`).
 *
 * One component for both, as with the customer form: the design draws the same
 * fields either way, and viewing adds a status strip at the top and a read-only
 * Information panel at the foot. Splitting them would mean two copies of the
 * same field list.
 */
const props = defineProps<{
    open: boolean;
    task: WorkOrder | null;
    /** Which column a card started from, so a new one lands where it was asked for. */
    defaultStatus: TaskStatusKey | null;
    /** Which board was selected when a new task was started, so it lands there too. */
    defaultBoardId: string | null;
}>();

const emit = defineEmits<{ close: [] }>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);

const assignees = computed<ComboboxOption[]>(
    () => (page.props.assigneeOptions as ComboboxOption[] | undefined) ?? [],
);
const vessels = computed<ComboboxOption[]>(
    () => (page.props.vesselOptions as ComboboxOption[] | undefined) ?? [],
);

const isEdit = computed(() => props.task !== null);

const form = useForm({
    title: '',
    description: '',
    category: '',
    priority: 'MEDIUM',
    status: 'TODO',
    start_date: '',
    due_date: '',
    assignee_id: '',
    vessel_id: '',
    board_id: '',
});

/** `YYYY-MM-DD` for a date input, from the ISO string the server sends. */
function dateInput(iso: string | null): string {
    return iso === null ? '' : iso.slice(0, 10);
}

watch(
    () => [props.open, props.task?.id],
    () => {
        if (!props.open) return;

        const t = props.task;

        form.defaults({
            title: t?.title ?? '',
            description: t?.description ?? '',
            category: t?.category ?? '',
            priority: t?.priority ?? 'MEDIUM',
            status: t?.status ?? props.defaultStatus ?? 'TODO',
            start_date: dateInput(t?.start_date ?? null),
            due_date: dateInput(t?.due_date ?? null),
            assignee_id: t?.assignee?.id ?? '',
            vessel_id: t?.vessel?.id ?? '',
            board_id: t?.board_id ?? props.defaultBoardId ?? '',
        });
        form.reset();
        form.clearErrors();

        if (assignees.value.length === 0) {
            router.reload({ only: ['assigneeOptions', 'vesselOptions'] });
        }
    },
    { immediate: true },
);

const CATEGORY_OPTIONS = Object.entries(CATEGORY_LABELS).map(([value, label]) => ({ value, label }));
const PRIORITY_OPTIONS = Object.entries(PRIORITY_LABELS).map(([value, label]) => ({ value, label }));

/**
 * The design's strip has four steps; three of them are real statuses and the
 * fourth ("This Week") is a date window. See WorkOrderBoard for why it is not
 * a column, and therefore not a step here either.
 */
const STATUS_TABS: TabItem[] = (['TODO', 'IN_PROGRESS', 'DONE'] as TaskStatusKey[]).map((value) => ({
    value,
    label: STATUS_LABELS[value] ?? value,
}));

/** Moving status on an existing task writes immediately, as it does on the board. */
function moveStatus(status: string): void {
    form.status = status;

    if (props.task === null) return;

    router.patch(`/work-orders/${props.task.id}/status`, { status }, { preserveScroll: true });
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

function shortDate(iso: string | null): string {
    if (iso === null) return '—';

    return new Date(iso).toLocaleDateString(locale.value, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

const confirmingDelete = ref(false);

function destroy(): void {
    if (props.task === null) return;

    router.delete(`/work-orders/${props.task.id}`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}

function submit(): void {
    // Blank optional fields go as null so clearing one actually clears it.
    const transform = (data: Record<string, unknown>): Record<string, unknown> => {
        const out = { ...data };

        for (const key of ['description', 'category', 'start_date', 'due_date', 'assignee_id', 'vessel_id', 'board_id']) {
            if (out[key] === '') out[key] = null;
        }

        // Status is written by the strip on an existing task, not by Save.
        if (props.task !== null) delete out.status;

        return out;
    };

    form
        .transform(transform)
        .submit(isEdit.value ? 'patch' : 'post', isEdit.value ? `/work-orders/${props.task!.id}` : '/work-orders', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}
</script>

<template>
    <SidePanel
        :open="open"
        :title="isEdit ? (task?.title ?? 'Task') : 'New task'"
        :subtitle="
            isEdit && task
                ? `Created ${dateTime(task.created_at)}${task.created_by ? ` by ${task.created_by.name}` : ''}`
                : undefined
        "
        @close="emit('close')"
    >
        <template v-if="isEdit" #meta>
            <Tabs :items="STATUS_TABS" :current="form.status" variant="solid" @select="moveStatus" />
        </template>

        <form id="task-form" class="flex flex-col gap-5" @submit.prevent="submit">
            <FormField label="Title" required :error="form.errors.title">
                <template #default="{ id, invalid }">
                    <Input :id="id" v-model="form.title" :invalid="invalid" placeholder="What needs doing?" />
                </template>
            </FormField>

            <FormField label="Description" :error="form.errors.description">
                <template #default="{ id }">
                    <Textarea
                        :id="id"
                        v-model="form.description"
                        :rows="3"
                        placeholder="Anything the person doing this needs to know…"
                    />
                </template>
            </FormField>

            <FieldRow>
                <FormField label="Category" :error="form.errors.category">
                    <template #default="{ id }">
                        <Select :id="id" v-model="form.category" placeholder="None" :options="CATEGORY_OPTIONS" />
                    </template>
                </FormField>
                <FormField label="Priority" :error="form.errors.priority">
                    <template #default="{ id }">
                        <Select :id="id" v-model="form.priority" :options="PRIORITY_OPTIONS" />
                    </template>
                </FormField>
            </FieldRow>

            <FieldRow>
                <FormField label="Start date" :error="form.errors.start_date">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.start_date" type="date" />
                    </template>
                </FormField>
                <FormField label="Due date" :error="form.errors.due_date">
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.due_date" type="date" />
                    </template>
                </FormField>
            </FieldRow>

            <FormField label="Assigned to" :error="form.errors.assignee_id">
                <template #default="{ id }">
                    <Combobox
                        :id="id"
                        :model-value="form.assignee_id === '' ? null : form.assignee_id"
                        placeholder="Unassigned"
                        empty-text="No one matches."
                        clearable
                        :options="assignees"
                        @update:model-value="form.assignee_id = $event ?? ''"
                    />
                </template>
            </FormField>

            <Disclosure title="Add details" summary="Where the work happens, and whether it repeats">
                <FormField label="Vessel" :error="form.errors.vessel_id">
                    <template #default="{ id }">
                        <Combobox
                            :id="id"
                            :model-value="form.vessel_id === '' ? null : form.vessel_id"
                            placeholder="No vessel"
                            empty-text="No vessel matches."
                            clearable
                            :options="vessels"
                            @update:model-value="form.vessel_id = $event ?? ''"
                        />
                    </template>
                </FormField>

                <!-- @todo Recurring. A work order has no recurrence field, so
                     this switch has nothing to write to; it is here because the
                     design's Add details panel has it (321:618). -->
                <SwitchRow
                    :model-value="false"
                    label="Recurring work order"
                    hint="Repeats on a schedule once completed."
                />
            </Disclosure>

            <template v-if="isEdit && task">
                <Separator />

                <section class="flex flex-col gap-3">
                    <h3 class="text-sm font-semibold">Information</h3>
                    <dl class="text-xs">
                        <div class="flex justify-between gap-3 py-1">
                            <dt class="text-muted-foreground">Created by</dt>
                            <dd>{{ task.created_by?.name ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 py-1">
                            <dt class="text-muted-foreground">Assigned to</dt>
                            <dd>{{ task.assignee?.name ?? 'Unassigned' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 py-1">
                            <dt class="text-muted-foreground">Due date</dt>
                            <dd>{{ shortDate(task.due_date) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 py-1">
                            <dt class="text-muted-foreground">Created</dt>
                            <dd>{{ dateTime(task.created_at) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 py-1">
                            <dt class="text-muted-foreground">Updated</dt>
                            <dd>{{ dateTime(task.updated_at) }}</dd>
                        </div>
                    </dl>
                </section>
            </template>
        </form>

        <template #footer>
            <Button
                v-if="isEdit"
                variant="ghost"
                class="mr-auto text-destructive hover:bg-destructive/10 hover:text-destructive"
                @click="confirmingDelete ? destroy() : (confirmingDelete = true)"
            >
                <Trash2 class="size-3.5" :stroke-width="1.5" />
                {{ confirmingDelete ? 'Confirm delete' : 'Delete' }}
            </Button>
            <Button variant="outline" @click="emit('close')">Cancel</Button>
            <Button type="submit" form="task-form" :disabled="form.processing || form.title.trim() === ''">
                {{ isEdit ? 'Save changes' : 'Create task' }}
            </Button>
        </template>
    </SidePanel>
</template>
