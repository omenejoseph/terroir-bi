<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Copy, Lock, Maximize2, Minus, Pencil, Plus, Ruler, Trash2, Undo2, Unlock, Wine, X } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import { useTranslations } from '@/composables/useTranslations';
import { WINE_TYPE_SWATCH, qty, so2Status, SO2_STATUS_BG } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import { csrfHeader } from '@/lib/csrf';
import type { MapVessel } from '@/types/cellar';

/**
 * The cellar map. In view mode each vessel is a tile shaped by its type, filled
 * to its level in the wine's colour, with its SO₂ and a fault overlay; a click
 * opens the lot (focused on that vessel) or the empty vessel. In edit mode
 * (cellar.manage) tiles can be moved, multi-selected, lasso-selected, resized,
 * matched in size, duplicated, taken off the map and placed again, with undo;
 * every change autosaves. Rooms are tabs that can be renamed, and reordered
 * per browser.
 *
 * Selection-based actions that need a form (assign wine, unassign) are raised
 * to the page via events so they live in the page's SidePanels.
 */
const props = defineProps<{ vessels: MapVessel[]; canManage: boolean }>();
const emit = defineEmits<{ assign: [ids: string[]]; unassign: [ids: string[]]; addVessels: [room: string] }>();

const { t } = useTranslations();

const DEFAULT_W = 80;
const DEFAULT_H = 80;
const GRID = 10;

// ── Local layout state (the source of truth while editing) ───────────────────
interface Box {
    x: number | null;
    y: number | null;
    w: number;
    h: number;
    room: string;
}
const boxes = reactive<Record<string, Box>>({});

function syncFromProps(): void {
    for (const key of Object.keys(boxes)) if (!props.vessels.some((v) => v.id === key)) delete boxes[key];
    for (const v of props.vessels) {
        boxes[v.id] = { x: v.position_x, y: v.position_y, w: v.map_width ?? DEFAULT_W, h: v.map_height ?? DEFAULT_H, room: v.room ?? 'Main Cellar' };
    }
}
watch(() => props.vessels, syncFromProps, { immediate: true, deep: false });

const byId = computed(() => new Map(props.vessels.map((v) => [v.id, v])));

// ── Rooms (order remembered per browser) ────────────────────────────────────
const ORDER_KEY = 'terroir.cellar.roomOrder';
const roomOrder = ref<string[]>([]);
onMounted(() => {
    try {
        roomOrder.value = JSON.parse(window.localStorage.getItem(ORDER_KEY) ?? '[]') as string[];
    } catch {
        roomOrder.value = [];
    }
});
const rooms = computed(() => {
    const names = [...new Set(props.vessels.map((v) => v.room ?? 'Main Cellar'))];
    const rank = (r: string) => {
        const i = roomOrder.value.indexOf(r);

        return i === -1 ? 1000 + names.indexOf(r) : i;
    };

    return names.sort((a, b) => rank(a) - rank(b));
});
const room = ref('');
// Open on the first room (in the member's order) that holds wine.
watch(rooms, (list) => {
    if (list.includes(room.value)) return;
    const withWine = list.find((r) => props.vessels.some((v) => (v.room ?? 'Main Cellar') === r && Number(v.current_volume) > 0));
    room.value = withWine ?? list[0] ?? 'Main Cellar';
}, { immediate: true });

function saveOrder(list: string[]): void {
    roomOrder.value = list;
    try {
        window.localStorage.setItem(ORDER_KEY, JSON.stringify(list));
    } catch {
        /* private mode — order just isn't remembered */
    }
}

const dragRoom = ref<string | null>(null);
function dropRoom(target: string): void {
    if (dragRoom.value === null || dragRoom.value === target) return;
    const list = rooms.value.filter((r) => r !== dragRoom.value);
    list.splice(list.indexOf(target), 0, dragRoom.value);
    saveOrder(list);
    dragRoom.value = null;
}

const renaming = ref<string | null>(null);
const renameTo = ref('');
function startRename(r: string): void {
    if (!editing.value) return;
    renaming.value = r;
    renameTo.value = r;
}
function commitRename(): void {
    const from = renaming.value;
    const to = renameTo.value.trim();
    renaming.value = null;
    if (from === null || to === '' || to === from) return;
    saveOrder(rooms.value.map((r) => (r === from ? to : r)));
    room.value = to;
    router.post('/cellar/rooms/rename', { from, to }, { preserveScroll: true, preserveState: true });
}

// ── View: zoom, fullscreen ──────────────────────────────────────────────────
const zoom = ref(1);
const fullscreen = ref(false);
const editing = ref(false);

const placed = computed(() => props.vessels.filter((v) => boxes[v.id]?.room === room.value && boxes[v.id]?.x !== null && boxes[v.id]?.y !== null));
const unplaced = computed(() => props.vessels.filter((v) => boxes[v.id]?.x === null || boxes[v.id]?.y === null));

const canvas = computed(() => {
    let w = 600;
    let h = 320;
    for (const v of placed.value) {
        const b = boxes[v.id]!;
        w = Math.max(w, (b.x ?? 0) + b.w + 40);
        h = Math.max(h, (b.y ?? 0) + b.h + 40);
    }

    return { w, h };
});

// ── Tile appearance ─────────────────────────────────────────────────────────
function fillPct(v: MapVessel): number {
    const cap = Number(v.capacity_liters);

    return cap > 0 ? Math.min(100, (Number(v.current_volume) / cap) * 100) : 0;
}
function colour(v: MapVessel): string {
    const type = v.lots?.find((l) => l.lot?.wine_type)?.lot?.wine_type;

    return type ? WINE_TYPE_SWATCH[type] : '#94a3b8';
}
function shape(v: MapVessel): string {
    switch (v.type) {
        case 'BARREL':
        case 'BARRIQUE':
            return 'rounded-[45%]';
        case 'AMPHORA':
            return 'rounded-t-[35%] rounded-b-[50%]';
        case 'TANK':
            return 'rounded-t-[40%]';
        default:
            return '';
    }
}
function so2Of(v: MapVessel): { free: number | null; total: number | null } | null {
    const s = v.lots?.find((l) => l.so2 && (l.so2.free !== null || l.so2.total !== null))?.so2;

    return s ? { free: s.free, total: s.total } : null;
}

// ── Tooltip ─────────────────────────────────────────────────────────────────
const hover = ref<{ v: MapVessel; x: number; y: number } | null>(null);
function showTip(v: MapVessel, e: MouseEvent): void {
    if (editing.value) return;
    hover.value = { v, x: e.clientX, y: e.clientY };
}

// ── Selection, drag, resize, lasso (edit mode) ───────────────────────────────
const selected = ref<string[]>([]);
const isSel = (id: string) => selected.value.includes(id);

type Snapshot = Record<string, Box>;
const undoStack = ref<Snapshot[]>([]);
function snapshot(): void {
    undoStack.value = [...undoStack.value.slice(-19), JSON.parse(JSON.stringify(boxes)) as Snapshot];
}
function undo(): void {
    const prev = undoStack.value.pop();
    if (!prev) return;
    const changed: string[] = [];
    for (const [id, b] of Object.entries(prev)) {
        const cur = boxes[id];
        if (cur && JSON.stringify(cur) !== JSON.stringify(b)) changed.push(id);
        boxes[id] = b;
    }
    queueSave(changed);
}

interface Drag {
    mode: 'move' | 'resize' | 'lasso';
    startX: number;
    startY: number;
    origin: Record<string, Box>;
    moved: boolean;
}
let drag: Drag | null = null;
const lasso = ref<{ x: number; y: number; w: number; h: number } | null>(null);
const stage = ref<HTMLElement | null>(null);

function local(e: PointerEvent): { x: number; y: number } {
    const r = stage.value!.getBoundingClientRect();

    return { x: (e.clientX - r.left) / zoom.value, y: (e.clientY - r.top) / zoom.value };
}

function onTileDown(v: MapVessel, e: PointerEvent, mode: 'move' | 'resize' = 'move'): void {
    if (!editing.value || e.button !== 0) return;
    e.stopPropagation();
    if (e.shiftKey || e.metaKey || e.ctrlKey) {
        selected.value = isSel(v.id) ? selected.value.filter((id) => id !== v.id) : [...selected.value, v.id];
        if (!isSel(v.id)) return;
    } else if (!isSel(v.id)) {
        selected.value = [v.id];
    }
    const p = local(e);
    drag = { mode, startX: p.x, startY: p.y, origin: Object.fromEntries(selected.value.map((id) => [id, { ...boxes[id]! }])), moved: false };
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
}

function onStageDown(e: PointerEvent): void {
    if (!editing.value || e.button !== 0) return;
    if (!(e.shiftKey || e.metaKey || e.ctrlKey)) selected.value = [];
    const p = local(e);
    drag = { mode: 'lasso', startX: p.x, startY: p.y, origin: {}, moved: false };
    lasso.value = { x: p.x, y: p.y, w: 0, h: 0 };
}

function onMove(e: PointerEvent): void {
    if (drag === null) return;
    const p = local(e);
    const dx = p.x - drag.startX;
    const dy = p.y - drag.startY;
    if (!drag.moved && Math.hypot(dx, dy) < 3) return;
    if (!drag.moved && drag.mode !== 'lasso') snapshot();
    drag.moved = true;
    const snap = (n: number) => Math.round(n / GRID) * GRID;

    if (drag.mode === 'lasso') {
        lasso.value = { x: Math.min(drag.startX, p.x), y: Math.min(drag.startY, p.y), w: Math.abs(dx), h: Math.abs(dy) };

        return;
    }
    for (const [id, o] of Object.entries(drag.origin)) {
        const b = boxes[id]!;
        if (drag.mode === 'move') {
            b.x = Math.max(0, snap((o.x ?? 0) + dx));
            b.y = Math.max(0, snap((o.y ?? 0) + dy));
        } else {
            b.w = Math.max(30, snap(o.w + dx));
            b.h = Math.max(30, snap(o.h + dy));
        }
    }
}

function onUp(): void {
    if (drag === null) return;
    if (drag.mode === 'lasso' && lasso.value) {
        const r = lasso.value;
        const hits = placed.value
            .filter((v) => {
                const b = boxes[v.id]!;

                return (b.x ?? 0) < r.x + r.w && (b.x ?? 0) + b.w > r.x && (b.y ?? 0) < r.y + r.h && (b.y ?? 0) + b.h > r.y;
            })
            .map((v) => v.id);
        selected.value = [...new Set([...selected.value, ...hits])];
        lasso.value = null;
    } else if (drag.moved) {
        queueSave(Object.keys(drag.origin));
    }
    drag = null;
}

function onTileClick(v: MapVessel): void {
    if (editing.value) return;
    const lot = v.lots?.find((l) => l.lot !== null);
    router.visit(lot ? `/cellar/lots/${lot.wine_lot_id}?vessel=${v.id}` : `/cellar/vessels/${v.id}`);
}

// ── Toolbar actions ─────────────────────────────────────────────────────────
const sizeW = ref('');
const sizeH = ref('');
watch(selected, (ids) => {
    const first = ids[0] ? boxes[ids[0]] : undefined;
    sizeW.value = first ? String(first.w) : '';
    sizeH.value = first ? String(first.h) : '';
});

function applySize(): void {
    const w = Number(sizeW.value);
    const h = Number(sizeH.value);
    if (!(w >= 30 && h >= 30)) return;
    snapshot();
    for (const id of selected.value) Object.assign(boxes[id]!, { w, h });
    queueSave(selected.value);
}

function matchSize(): void {
    const first = boxes[selected.value[0] ?? ''];
    if (!first) return;
    snapshot();
    for (const id of selected.value.slice(1)) Object.assign(boxes[id]!, { w: first.w, h: first.h });
    queueSave(selected.value);
}

function removeFromMap(): void {
    snapshot();
    for (const id of selected.value) Object.assign(boxes[id]!, { x: null, y: null });
    queueSave(selected.value);
    selected.value = [];
}

/** Put an unplaced vessel on this room's map, at the first free spot along the bottom. */
function place(v: MapVessel): void {
    snapshot();
    const b = boxes[v.id]!;
    const bottom = placed.value.reduce((m, p) => Math.max(m, (boxes[p.id]!.y ?? 0) + boxes[p.id]!.h), 0);
    const row = placed.value.filter((p) => (boxes[p.id]!.y ?? 0) >= bottom - DEFAULT_H);
    const right = row.reduce((m, p) => Math.max(m, (boxes[p.id]!.x ?? 0) + boxes[p.id]!.w), 0);
    Object.assign(b, { room: room.value, x: right + GRID * 2 > canvas.value.w - b.w ? 20 : right + GRID * 2, y: right + GRID * 2 > canvas.value.w - b.w ? bottom + GRID * 2 : Math.max(20, bottom - b.h) });
    if (placed.value.length === 0) Object.assign(b, { x: 20, y: 20 });
    queueSave([v.id]);
}

const dupCount = ref('1');
function duplicate(): void {
    router.post('/cellar/vessels/duplicate', { vessel_ids: selected.value, count: Number(dupCount.value) || 1 }, { preserveScroll: true, preserveState: true });
}

// ── Autosave ────────────────────────────────────────────────────────────────
const pending = new Set<string>();
const saving = ref(false);
const saveError = ref<string | null>(null);
let saveTimer: ReturnType<typeof setTimeout> | null = null;

function queueSave(ids: string[]): void {
    ids.forEach((id) => pending.add(id));
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(flush, 400);
}

async function flush(): Promise<void> {
    if (pending.size === 0) return;
    const updates = [...pending].map((id) => {
        const b = boxes[id]!;

        return { id, position_x: b.x === null ? null : Math.round(b.x), position_y: b.y === null ? null : Math.round(b.y), map_width: Math.round(b.w), map_height: Math.round(b.h), room: b.room };
    });
    pending.clear();
    saving.value = true;
    try {
        const res = await fetch('/cellar/vessels/layout', {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
            body: JSON.stringify({ updates }),
        });
        saveError.value = res.ok ? null : t('The map could not be saved. Your last change is not stored.');
    } catch {
        saveError.value = t('The map could not be saved. Your last change is not stored.');
    } finally {
        saving.value = false;
    }
}

function toggleEdit(): void {
    if (editing.value) {
        void flush();
        selected.value = [];
        // Pick up what other members changed, and our own saved layout.
        router.reload({ only: ['vessels'] });
    }
    editing.value = !editing.value;
    hover.value = null;
}

function onKey(e: KeyboardEvent): void {
    // A drawer or dialog owns the keyboard while it is open — Esc closes it, not the selection.
    if (document.querySelector('[role="dialog"]')) return;
    if (e.key === 'Escape') {
        if (fullscreen.value) fullscreen.value = false;
        selected.value = [];
    }
    if (!editing.value) return;
    const target = e.target as HTMLElement | null;
    if (target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return;
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'z') {
        e.preventDefault();
        undo();
    }
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'a') {
        e.preventDefault();
        selected.value = placed.value.map((v) => v.id);
    }
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    void flush();
});

function switchRoom(r: string): void {
    room.value = r;
    selected.value = [];
}

const selectionHoldsWine = computed(() => selected.value.some((id) => (byId.value.get(id)?.lots ?? []).length > 0));

function unassignSelected(): void {
    emit('unassign', selected.value);
}

defineExpose({ select: (ids: string[]) => (selected.value = ids) });

const tipStatus = computed(() => {
    const s = hover.value ? so2Of(hover.value.v) : null;

    return s ? so2Status(s.free) : 'no_data';
});
</script>

<template>
    <div :class="cn('flex flex-col gap-3', fullscreen && 'fixed inset-0 z-50 overflow-auto bg-background p-4')">
        <!-- Toolbar -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex flex-wrap items-center bg-muted p-1" role="tablist">
                <template v-for="r in rooms" :key="r">
                    <input
                        v-if="renaming === r"
                        v-model="renameTo"
                        class="h-6 w-32 border border-input bg-background px-2 text-xs"
                        :aria-label="t('Room name')"
                        autofocus
                        @keydown.enter.prevent="commitRename"
                        @keydown.esc.prevent="renaming = null"
                        @blur="commitRename"
                    />
                    <button
                        v-else
                        type="button"
                        role="tab"
                        :aria-selected="room === r"
                        :draggable="editing"
                        :class="cn('inline-flex h-6 items-center gap-1 px-3 text-xs', room === r ? 'bg-background font-semibold' : 'text-muted-foreground hover:text-foreground')"
                        :title="editing ? t('Double-click to rename, drag to reorder') : undefined"
                        @click="switchRoom(r)"
                        @dblclick="startRename(r)"
                        @dragstart="dragRoom = r"
                        @dragover.prevent
                        @drop="dropRoom(r)"
                    >
                        {{ t(r) }}
                        <Pencil v-if="editing && room === r" class="size-3 opacity-60" @click.stop="startRename(r)" />
                    </button>
                </template>
            </div>

            <div class="ml-auto flex items-center gap-1">
                <span v-if="editing" class="mr-2 text-xs text-muted-foreground">{{ saving ? t('Saving…') : t('All changes saved') }}</span>
                <button type="button" class="p-1.5 text-muted-foreground hover:bg-accent" :aria-label="t('Zoom out')" @click="zoom = Math.max(0.5, +(zoom - 0.1).toFixed(1))"><Minus class="size-4" /></button>
                <span class="w-10 text-center text-xs tabular-nums">{{ Math.round(zoom * 100) }}%</span>
                <button type="button" class="p-1.5 text-muted-foreground hover:bg-accent" :aria-label="t('Zoom in')" @click="zoom = Math.min(2, +(zoom + 0.1).toFixed(1))"><Plus class="size-4" /></button>
                <button type="button" class="p-1.5 text-muted-foreground hover:bg-accent" :aria-label="fullscreen ? t('Exit full screen') : t('Full screen')" @click="fullscreen = !fullscreen">
                    <X v-if="fullscreen" class="size-4" />
                    <Maximize2 v-else class="size-4" />
                </button>
                <Button v-if="canManage" size="sm" :variant="editing ? 'primary' : 'outline'" @click="toggleEdit">
                    <Unlock v-if="editing" class="size-3.5" /><Lock v-else class="size-3.5" />
                    {{ editing ? t('Done editing') : t('Edit map') }}
                </Button>
            </div>
        </div>

        <p v-if="saveError" class="text-xs text-destructive" role="alert">{{ saveError }}</p>

        <!-- Selection toolbar -->
        <div v-if="editing && selected.length" class="flex flex-wrap items-center gap-2 border border-border bg-card px-3 py-2 text-xs">
            <span class="font-medium">{{ t(':n selected', { n: selected.length }) }}</span>
            <span class="mx-1 h-4 w-px bg-border" />
            <Ruler class="size-3.5 text-muted-foreground" />
            <Input v-model="sizeW" class="h-7 w-14 text-xs" inputmode="numeric" :aria-label="t('Width')" />
            ×
            <Input v-model="sizeH" class="h-7 w-14 text-xs" inputmode="numeric" :aria-label="t('Height')" />
            <Button size="sm" variant="outline" @click="applySize">{{ t('Apply size') }}</Button>
            <Button v-if="selected.length > 1" size="sm" variant="outline" @click="matchSize">{{ t('Match size') }}</Button>
            <span class="mx-1 h-4 w-px bg-border" />
            <Input v-model="dupCount" class="h-7 w-12 text-xs" inputmode="numeric" :aria-label="t('Copies')" />
            <Button size="sm" variant="outline" @click="duplicate"><Copy class="size-3.5" /> {{ t('Duplicate') }}</Button>
            <Button size="sm" variant="outline" @click="emit('assign', selected)"><Wine class="size-3.5" /> {{ t('Assign wine') }}</Button>
            <Button v-if="selectionHoldsWine" size="sm" variant="outline" @click="unassignSelected">{{ t('Unassign wine') }}</Button>
            <Button size="sm" variant="ghost" @click="removeFromMap"><Trash2 class="size-3.5" /> {{ t('Remove from map') }}</Button>
            <Button size="sm" variant="ghost" :disabled="undoStack.length === 0" @click="undo"><Undo2 class="size-3.5" /> {{ t('Undo') }}</Button>
            <Button size="sm" variant="ghost" @click="selected = []">{{ t('Clear') }}</Button>
        </div>

        <div class="flex gap-3">
            <!-- Map -->
            <div class="min-w-0 flex-1 overflow-auto border border-border bg-muted/40" :class="fullscreen ? 'h-[calc(100vh-140px)]' : 'max-h-[70vh]'">
                <div :style="{ width: `${canvas.w * zoom}px`, height: `${canvas.h * zoom}px` }">
                    <div
                        ref="stage"
                        class="relative origin-top-left select-none"
                        :class="editing ? 'cursor-crosshair bg-[radial-gradient(circle,var(--color-border)_1px,transparent_1px)] [background-size:10px_10px]' : ''"
                        :style="{ width: `${canvas.w}px`, height: `${canvas.h}px`, transform: `scale(${zoom})` }"
                        @pointerdown="onStageDown"
                        @pointermove="onMove"
                        @pointerup="onUp"
                        @pointercancel="onUp"
                    >
                        <div
                            v-for="v in placed"
                            :key="v.id"
                            :data-vessel="v.id"
                            :class="
                                cn(
                                    'absolute flex flex-col items-center justify-center overflow-hidden border-2 bg-card text-center leading-tight',
                                    shape(v),
                                    editing ? 'cursor-move' : 'cursor-pointer hover:border-foreground/50',
                                    isSel(v.id) ? 'border-primary ring-2 ring-primary/30' : 'border-border',
                                    v.is_faulty && 'border-destructive',
                                )
                            "
                            :style="{ left: `${boxes[v.id]!.x}px`, top: `${boxes[v.id]!.y}px`, width: `${boxes[v.id]!.w}px`, height: `${boxes[v.id]!.h}px` }"
                            role="button"
                            :aria-label="`${v.name} · ${qty(v.current_volume)}/${qty(v.capacity_liters)} L`"
                            @pointerdown="onTileDown(v, $event)"
                            @click="onTileClick(v)"
                            @mousemove="showTip(v, $event)"
                            @mouseleave="hover = null"
                        >
                            <!-- fill level in the wine's colour -->
                            <div class="absolute inset-x-0 bottom-0 opacity-25" :style="{ height: `${fillPct(v)}%`, background: colour(v) }" />
                            <!-- barrel hoops -->
                            <template v-if="v.type === 'BARREL' || v.type === 'BARRIQUE'">
                                <div class="absolute inset-y-0 left-[28%] w-px bg-border" />
                                <div class="absolute inset-y-0 right-[28%] w-px bg-border" />
                            </template>
                            <div v-if="v.is_faulty" class="absolute inset-0 bg-destructive/15" />
                            <span class="relative z-10 flex items-center gap-1 text-[11px] font-semibold text-foreground">
                                <AlertTriangle v-if="v.is_faulty" class="size-3 text-destructive" />
                                {{ v.name }}
                            </span>
                            <span v-if="v.lots?.[0]?.lot" class="relative z-10 max-w-full truncate px-1 text-[9px] text-muted-foreground">{{ v.lots[0].lot.name }}</span>
                            <span class="relative z-10 text-[9px] tabular-nums text-muted-foreground">
                                <template v-if="Number(v.current_volume) > 0">{{ qty(v.current_volume) }}/{{ qty(v.capacity_liters) }} L</template>
                                <template v-else>{{ t('Available') }}</template>
                            </span>
                            <span v-if="so2Of(v)" :class="cn('relative z-10 mt-0.5 px-1 text-[9px] tabular-nums', SO2_STATUS_BG[so2Status(so2Of(v)!.free)])">
                                {{ so2Of(v)!.free ?? '—' }}/{{ so2Of(v)!.total ?? '—' }}
                            </span>
                            <!-- resize handle -->
                            <span
                                v-if="editing && isSel(v.id)"
                                class="absolute bottom-0 right-0 z-20 size-3 cursor-nwse-resize bg-primary"
                                @pointerdown.stop="onTileDown(v, $event, 'resize')"
                            />
                        </div>

                        <div v-if="lasso" class="pointer-events-none absolute border border-primary bg-primary/10" :style="{ left: `${lasso.x}px`, top: `${lasso.y}px`, width: `${lasso.w}px`, height: `${lasso.h}px` }" />

                        <div v-if="placed.length === 0" class="absolute inset-0 flex flex-col items-center justify-center gap-1 text-center text-xs text-muted-foreground">
                            <p>{{ t('No vessels placed in this room yet.') }}</p>
                            <p v-if="canManage && !editing">{{ t('Click “Edit map” to start arranging your cellar.') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Unplaced vessels -->
            <aside v-if="editing" class="w-48 shrink-0 border border-border bg-card p-2 text-xs">
                <div class="mb-2 flex items-center justify-between">
                    <span class="font-semibold">{{ t('Unplaced · :n', { n: unplaced.length }) }}</span>
                    <button type="button" class="text-muted-foreground hover:text-foreground" :aria-label="t('Add vessels')" @click="emit('addVessels', room)"><Plus class="size-4" /></button>
                </div>
                <p v-if="unplaced.length === 0" class="text-muted-foreground">{{ t('Every vessel is on the map.') }}</p>
                <button
                    v-for="v in unplaced"
                    :key="v.id"
                    type="button"
                    class="flex w-full items-center justify-between border-b border-border py-1.5 text-left last:border-0 hover:bg-accent"
                    :title="t('Place in :room', { room: t(room) })"
                    @click="place(v)"
                >
                    <span class="truncate font-medium">{{ v.name }}</span>
                    <span class="shrink-0 text-muted-foreground">{{ qty(v.capacity_liters) }} L</span>
                </button>
            </aside>
        </div>

        <!-- Tooltip -->
        <Teleport to="body">
            <div
                v-if="hover"
                class="pointer-events-none fixed z-50 w-60 border border-border bg-card p-3 text-xs shadow-lg"
                :style="{ left: `${hover.x + 14}px`, top: `${hover.y + 14}px` }"
            >
                <p class="font-semibold text-foreground">{{ hover.v.name }}</p>
                <p class="text-muted-foreground">{{ [hover.v.type, hover.v.material].filter(Boolean).join(' · ') }}</p>
                <p class="mt-1 tabular-nums">{{ qty(hover.v.current_volume) }} / {{ qty(hover.v.capacity_liters) }} L ({{ Math.round(fillPct(hover.v)) }}%)</p>
                <p v-if="hover.v.is_faulty" class="mt-1 text-destructive">{{ t('Faulty') }}<template v-if="hover.v.fault_note">: {{ hover.v.fault_note }}</template></p>
                <div v-for="l in hover.v.lots ?? []" :key="l.vessel_lot_id" class="mt-2 border-t border-border pt-2">
                    <p class="font-medium">{{ l.lot?.name }} <span class="text-muted-foreground">{{ l.lot?.vintage }}</span> · {{ qty(l.volume) }} L</p>
                    <template v-if="l.so2">
                        <p v-if="l.so2.free !== null || l.so2.total !== null" :class="SO2_STATUS_BG[tipStatus]" class="mt-1 inline-block px-1">
                            SO₂ {{ l.so2.free ?? '—' }} {{ t('free') }} / {{ l.so2.total ?? '—' }} {{ t('total') }}
                            <template v-if="!l.so2.in_vessel"> ({{ t('lot') }})</template>
                        </p>
                        <p v-else class="mt-1 text-muted-foreground">{{ t('No analysis on file') }}</p>
                        <p v-if="l.so2.projected !== null" class="mt-1 text-tag-blue-foreground">
                            → {{ l.so2.projected }} {{ t('free') }} · {{ t('Projected with the latest addition') }}
                            <span class="block text-muted-foreground">{{ l.so2.latest_addition?.name }} {{ qty(l.so2.latest_addition?.quantity) }} {{ l.so2.latest_addition?.unit }} — {{ t('run a fresh analysis to confirm') }}</span>
                        </p>
                    </template>
                </div>
                <p v-if="!(hover.v.lots ?? []).length" class="mt-1 text-muted-foreground">{{ t('Empty') }}</p>
            </div>
        </Teleport>
    </div>
</template>
