<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRightLeft, Blend, Box, CalendarPlus, CircleDot, Container, Copy, Cylinder, ExternalLink, FlaskConical, Lock, Maximize2, MousePointerClick, Minus, Pencil, Plus, Ruler, Trash2, Undo2, Unlock, Wine, X } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import { useAuth } from '@/composables/useAuth';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import { qty, so2Status, SO2_STATUS_BG, wineColour } from '@/lib/cellar';
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
 * Outside edit mode the map is also where cellar work starts: select vessels
 * (shift/⌘-click, a mouse drag on the floor, or "Select" for touch) and an
 * action bar records SO₂, plans an addition or readings, blends them, moves
 * wine or assigns wine. Tiles can be coloured by SO₂, by wine or by fill.
 *
 * Selection-based actions that need a form (assign wine, unassign, record
 * SO₂, move wine) are raised to the page via events so they live in the
 * page's SidePanels.
 */
const props = defineProps<{ vessels: MapVessel[]; canManage: boolean }>();
const emit = defineEmits<{ assign: [ids: string[]]; unassign: [ids: string[]]; addVessels: [room: string]; recordSo2: [ids: string[]]; move: [id: string] }>();

const { t } = useTranslations();
const { can } = useAuth();

const DEFAULT_W = 80;
const DEFAULT_H = 80;

/** The prototype's default footprint by vessel type and capacity, for a vessel never resized by hand. */
function defaultSize(v: { type: string; capacity_liters: string }): { w: number; h: number } {
    const litres = Number(v.capacity_liters);

    switch (v.type) {
        case 'TANK':
            if (litres >= 5000) return { w: 70, h: 110 };
            if (litres >= 2000) return { w: 60, h: 100 };
            if (litres >= 1000) return { w: 55, h: 90 };
            if (litres >= 500) return { w: 50, h: 80 };

            return { w: 40, h: 70 };
        case 'BARREL':
            return litres >= 400 ? { w: 60, h: 42 } : { w: 50, h: 35 };
        case 'BARRIQUE':
            return { w: 45, h: 32 };
        case 'VAT':
            return litres >= 2000 ? { w: 90, h: 70 } : { w: 70, h: 55 };
        case 'AMPHORA':
            return { w: 35, h: 55 };
        default:
            return { w: DEFAULT_W, h: DEFAULT_H };
    }
}
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
        boxes[v.id] = { x: v.position_x, y: v.position_y, w: v.map_width ?? defaultSize(v).w, h: v.map_height ?? defaultSize(v).h, room: v.room ?? 'Main Cellar' };
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
/** The Unplaced list; starts hidden on narrow screens, where it would squeeze the map. */
const showUnplaced = ref(typeof window === 'undefined' || window.matchMedia('(min-width: 768px)').matches);

// Two-finger pinch on the map scales it like the zoom buttons (0.5–2).
const touches = new Map<number, { x: number; y: number }>();
let pinch: { distance: number; zoom: number } | null = null;
function pinchDistance(): number {
    const [a, b] = [...touches.values()];

    return a && b ? Math.hypot(a.x - b.x, a.y - b.y) : 0;
}
function onTouchDown(e: PointerEvent): void {
    if (e.pointerType !== 'touch') return;
    touches.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (touches.size === 2) pinch = { distance: pinchDistance(), zoom: zoom.value };
}
function onTouchMove(e: PointerEvent): void {
    if (e.pointerType !== 'touch' || !touches.has(e.pointerId)) return;
    touches.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pinch && touches.size === 2 && pinch.distance > 0) {
        zoom.value = Math.min(2, Math.max(0.5, Math.round(pinch.zoom * (pinchDistance() / pinch.distance) * 20) / 20));
    }
}
function onTouchUp(e: PointerEvent): void {
    touches.delete(e.pointerId);
    if (touches.size < 2) pinch = null;
}

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
/** The wine's colour, from the first lot in the vessel (wine type, else grape), as the prototype does. */
function colour(v: MapVessel): string {
    const lot = v.lots?.find((l) => l.lot !== null)?.lot;

    return lot ? wineColour(lot) : '#94a3b8';
}

// ── Colour by (remembered per browser) ──────────────────────────────────────
type ColourBy = 'so2' | 'wine' | 'fill';
const COLOUR_KEY = 'terroir.cellar.colourBy';
const colourBy = ref<ColourBy>('wine');
onMounted(() => {
    try {
        const saved = window.localStorage.getItem(COLOUR_KEY);
        if (saved === 'so2' || saved === 'wine' || saved === 'fill') colourBy.value = saved;
    } catch {
        // storage unavailable — keep the default
    }
});
watch(colourBy, (c) => {
    try {
        window.localStorage.setItem(COLOUR_KEY, c);
    } catch {
        // storage unavailable — the choice lasts for this visit
    }
});
/** Free-SO₂ status colours (the same scale as the SO₂ page). */
const SO2_HEX: Record<string, string> = { critical: '#c10007', low: '#ca3500', ok: '#008236', high: '#1447e6', no_data: '#94a3b8' };
/*
  The liquid is always the wine's own colour — a red stays red whatever the tiles are being coloured
  by. "SO₂" mode shows the status on the tile's outline and badge instead of repainting the wine
  (a healthy SO₂ is green, which made a red wine look green); "Fill" mode drops the colour for grey.
*/
function tileColour(v: MapVessel): string {
    return colourBy.value === 'fill' ? '#525252' : colour(v);
}
/** Border colour by status, as the prototype has it; faulty is red. In SO₂ mode the status of the SO₂ takes over. */
const STATUS_BORDER: Record<string, string> = { AVAILABLE: '#4ade80', IN_USE: '#60a5fa', MAINTENANCE: '#facc15', RETIRED: '#9ca3af' };
function borderColour(v: MapVessel): string {
    if (v.is_faulty) return '#ef4444';
    if (colourBy.value === 'so2' && (v.lots ?? []).length) return SO2_HEX[so2Status(so2Of(v)?.free ?? null)]!;

    return STATUS_BORDER[v.status] ?? '#d1d5db';
}
/** Border weight by type: thick staves for barrels, thicker still for vats. */
function typeBorder(v: MapVessel): string {
    switch (v.type) {
        case 'BARREL':
        case 'BARRIQUE':
            return 'border-[2.5px]';
        case 'VAT':
            return 'border-[3px]';
        default:
            return 'border-2';
    }
}
/** Empty either with no wine or with only rounding dust left. */
const isEmpty = (v: MapVessel): boolean => !(v.lots ?? []).some((l) => l.lot !== null) || Number(v.current_volume) <= 0.01;
const firstLot = (v: MapVessel) => v.lots?.find((l) => l.lot !== null)?.lot ?? null;
function typeIcon(v: MapVessel) {
    switch (v.type) {
        case 'BARREL':
        case 'BARRIQUE':
            return Cylinder;
        case 'TANK':
            return Container;
        case 'AMPHORA':
            return CircleDot;
        default:
            return Box;
    }
}
const daysSince = (iso: string | null | undefined) => (iso ? Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)) : null);
function shape(v: MapVessel): string {
    switch (v.type) {
        case 'BARREL':
        case 'BARRIQUE':
            return 'rounded-[45%]';
        case 'TANK':
            return 'rounded-t-[30%] rounded-b-sm';
        case 'VAT':
            return 'rounded-lg';
        case 'AMPHORA':
            return 'rounded-t-[50%] rounded-b-[25%]';
        default:
            return 'rounded-md';
    }
}
function so2Of(v: MapVessel): { free: number | null; total: number | null; date: string | null } | null {
    const s = v.lots?.find((l) => l.so2 && (l.so2.free !== null || l.so2.total !== null))?.so2;

    return s ? { free: s.free, total: s.total, date: s.date } : null;
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

// ── Selection in view mode (acting on vessels, not moving them) ─────────────
const selectMode = ref(false);
let skipClick = false;
function toggleSelectMode(): void {
    selectMode.value = !selectMode.value;
    if (!selectMode.value) selected.value = [];
}
const toggleSel = (id: string) => (selected.value = isSel(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id]);

function onTileDown(v: MapVessel, e: PointerEvent, mode: 'move' | 'resize' = 'move'): void {
    if (!editing.value) {
        if (props.canManage && e.button === 0 && (selectMode.value || e.shiftKey || e.metaKey || e.ctrlKey)) {
            e.stopPropagation();
            toggleSel(v.id);
            skipClick = true;
        }

        return;
    }
    if (e.button !== 0) return;
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
    if (e.button !== 0) return;
    // In view mode only a mouse drag lassos — a finger on the floor pans the map.
    if (!editing.value && (!props.canManage || e.pointerType !== 'mouse')) return;
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
    if (skipClick) {
        skipClick = false;

        return;
    }
    // Members who can act on vessels get the action bar and stay on the map: a click selects the
    // vessel (a second click clears it), and the bar's "Open" goes to its page. Read-only members
    // have no bar, so a click still takes them straight to the page.
    if (props.canManage) {
        selected.value = isSel(v.id) ? [] : [v.id];

        return;
    }
    openVessel(v);
}

/** The lot page focused on this vessel, or the vessel's own page when it holds no wine. */
function openVessel(v: MapVessel): void {
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

/**
 * Delete the selected vessels for good. Empty ones go; any that hold wine are kept (the server skips
 * them and says which), so the confirmation spells out how many of each.
 */
async function deleteSelected(): Promise<void> {
    const ids = [...selected.value];
    const withContents = selectedVessels.value.filter((v) => (v.lots ?? []).some((l) => l.lot !== null)).length;
    const empty = ids.length - withContents;
    if (empty === 0) return void router.delete('/cellar/vessels', { data: { ids }, preserveScroll: true });

    const ok = await confirmDialog({
        title: t('Delete :n vessels', { n: empty }),
        description: withContents > 0
            ? t(':n of the :total selected hold wine and will be kept. The rest are deleted for good.', { n: withContents, total: ids.length })
            : t('These vessels are deleted for good. This cannot be undone.'),
        confirmLabel: t('Delete'),
        tone: 'danger',
    });
    if (!ok) return;

    router.delete('/cellar/vessels', { data: { ids }, preserveScroll: true, onSuccess: () => (selected.value = []) });
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
    selected.value = [];
    selectMode.value = false;
    if (editing.value) {
        void flush();
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

const selectedVessels = computed(() => selected.value.map((id) => byId.value.get(id)).filter((v): v is MapVessel => v !== undefined));
const selectedLitres = computed(() => selectedVessels.value.reduce((n, v) => n + Number(v.current_volume), 0));
const withWine = computed(() => selectedVessels.value.filter((v) => (v.lots ?? []).some((l) => l.lot !== null)));
const ids = (list: MapVessel[]) => list.map((v) => v.id).join(',');
function go(path: string, type?: string): void {
    router.visit(`${path}?vessels=${ids(withWine.value)}${type ? `&type=${type}` : ''}`);
}

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

            <div v-if="!editing" class="flex items-center gap-1 text-xs" role="group" :aria-label="t('Colour by')">
                <span class="text-muted-foreground">{{ t('Colour by') }}</span>
                <button
                    v-for="c in (['so2', 'wine', 'fill'] as const)"
                    :key="c"
                    type="button"
                    :aria-pressed="colourBy === c"
                    :class="cn('h-6 px-2', colourBy === c ? 'bg-primary text-primary-foreground' : 'border border-border hover:bg-accent')"
                    @click="colourBy = c"
                >
                    {{ c === 'so2' ? 'SO₂' : c === 'wine' ? t('Wine') : t('Fill') }}
                </button>
            </div>

            <div class="ml-auto flex items-center gap-1">
                <Button v-if="canManage && !editing" size="sm" :variant="selectMode ? 'primary' : 'ghost'" :aria-pressed="selectMode" @click="toggleSelectMode">
                    <MousePointerClick class="size-3.5" /> {{ t('Select') }}
                </Button>
                <span v-if="editing" class="mr-2 text-xs text-muted-foreground">{{ saving ? t('Saving…') : t('All changes saved') }}</span>
                <button type="button" class="p-1.5 text-muted-foreground hover:bg-accent" :aria-label="t('Zoom out')" @click="zoom = Math.max(0.5, +(zoom - 0.1).toFixed(1))"><Minus class="size-4" /></button>
                <span class="w-10 text-center text-xs tabular-nums">{{ Math.round(zoom * 100) }}%</span>
                <button type="button" class="p-1.5 text-muted-foreground hover:bg-accent" :aria-label="t('Zoom in')" @click="zoom = Math.min(2, +(zoom + 0.1).toFixed(1))"><Plus class="size-4" /></button>
                <button type="button" class="p-1.5 text-muted-foreground hover:bg-accent" :aria-label="fullscreen ? t('Exit full screen') : t('Full screen')" @click="fullscreen = !fullscreen">
                    <X v-if="fullscreen" class="size-4" />
                    <Maximize2 v-else class="size-4" />
                </button>
                <Button v-if="editing" size="sm" variant="ghost" :aria-pressed="showUnplaced" @click="showUnplaced = !showUnplaced">
                    {{ t('Unplaced · :n', { n: unplaced.length }) }}
                </Button>
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
            <Button v-if="can('cellar.delete')" size="sm" variant="ghost" class="text-destructive hover:bg-destructive/10" @click="deleteSelected"><Trash2 class="size-3.5" /> {{ t('Delete vessels') }}</Button>
            <Button size="sm" variant="ghost" :disabled="undoStack.length === 0" @click="undo"><Undo2 class="size-3.5" /> {{ t('Undo') }}</Button>
            <Button size="sm" variant="ghost" @click="selected = []">{{ t('Clear') }}</Button>
        </div>

        <!-- Actions on the selected vessels (view mode) -->
        <div v-if="!editing && selected.length" role="toolbar" :aria-label="t('Selected vessels')" class="flex flex-wrap items-center gap-2 bg-primary px-3 py-2 text-xs text-primary-foreground">
            <span class="font-semibold">{{ t(':n vessels · :l L', { n: selected.length, l: qty(selectedLitres) }) }}</span>
            <span class="mx-1 h-4 w-px bg-primary-foreground/30" />
            <template v-if="withWine.length">
                <Button size="sm" variant="secondary" @click="emit('recordSo2', withWine.map((v) => v.id))"><FlaskConical class="size-3.5" /> {{ t('Record SO₂') }}</Button>
                <Button size="sm" variant="ghost" class="text-primary-foreground hover:bg-primary-foreground/10" @click="go('/cellar/plan', 'ADDITION')"><CalendarPlus class="size-3.5" /> {{ t('Plan addition') }}</Button>
                <Button size="sm" variant="ghost" class="text-primary-foreground hover:bg-primary-foreground/10" @click="go('/cellar/plan', 'ANALYSIS')"><CalendarPlus class="size-3.5" /> {{ t('Plan readings') }}</Button>
                <Button size="sm" variant="ghost" class="text-primary-foreground hover:bg-primary-foreground/10" @click="go('/cellar/blend')"><Blend class="size-3.5" /> {{ t('Blend these') }}</Button>
                <Button v-if="withWine.length === 1" size="sm" variant="ghost" class="text-primary-foreground hover:bg-primary-foreground/10" @click="emit('move', withWine[0]!.id)"><ArrowRightLeft class="size-3.5" /> {{ t('Move wine') }}</Button>
            </template>
            <Button size="sm" variant="ghost" class="text-primary-foreground hover:bg-primary-foreground/10" @click="emit('assign', selected)"><Wine class="size-3.5" /> {{ t('Assign wine') }}</Button>
            <Button v-if="selectedVessels.length === 1" size="sm" variant="ghost" class="text-primary-foreground hover:bg-primary-foreground/10" @click="openVessel(selectedVessels[0]!)"><ExternalLink class="size-3.5" /> {{ t('Open') }}</Button>
            <button type="button" class="ml-auto p-1 opacity-70 hover:opacity-100" :aria-label="t('Clear selection')" @click="selected = []"><X class="size-4" /></button>
        </div>

        <div class="flex gap-3">
            <!-- Map -->
            <div
                class="min-w-0 flex-1 touch-pan-x touch-pan-y overflow-auto border border-border bg-muted/40"
                :class="fullscreen ? 'h-[calc(100vh-140px)]' : 'max-h-[70vh]'"
                @pointerdown="onTouchDown"
                @pointermove="onTouchMove"
                @pointerup="onTouchUp"
                @pointercancel="onTouchUp"
            >
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
                        <!--
                          One vessel, drawn as the prototype draws it: a bordered body (shape and border
                          weight by type, border colour by status, dashed when empty) filled to its level
                          in the wine's colour, with the vessel name on top, the wine / grape / vintage in
                          the middle (as many lines as fit), the litres below, the free/total SO₂ in the
                          corner, and legs under a tank.
                        -->
                        <div
                            v-for="v in placed"
                            :key="v.id"
                            :data-vessel="v.id"
                            :class="cn('group absolute select-none', editing ? 'cursor-move' : 'cursor-pointer', isSel(v.id) && 'z-20')"
                            :style="{ left: `${boxes[v.id]!.x}px`, top: `${boxes[v.id]!.y}px`, width: `${boxes[v.id]!.w}px`, height: `${boxes[v.id]!.h + (v.type === 'TANK' ? 6 : 0)}px` }"
                            role="button"
                            :aria-label="`${v.name} · ${qty(v.current_volume)}/${qty(v.capacity_liters)} L`"
                            @pointerdown="onTileDown(v, $event)"
                            @click="onTileClick(v)"
                            @mousemove="showTip(v, $event)"
                            @mouseleave="hover = null"
                        >
                            <!-- selection ring -->
                            <div v-if="isSel(v.id)" class="pointer-events-none absolute -inset-1.5 z-0 rounded-md border-2 border-primary" />

                            <div
                                :class="cn('relative overflow-hidden bg-muted/40', shape(v), typeBorder(v), isEmpty(v) && 'border-dashed opacity-60')"
                                :style="{ width: `${boxes[v.id]!.w}px`, height: `${boxes[v.id]!.h}px`, borderColor: borderColour(v) }"
                            >
                                <!-- faulty: the whole vessel goes red with a danger sign -->
                                <div v-if="v.is_faulty" class="absolute inset-0 z-[25] flex items-center justify-center bg-red-600/80" :title="v.fault_note ? `${t('Faulty')}: ${v.fault_note}` : t('Faulty')">
                                    <AlertTriangle class="size-1/2 max-h-7 max-w-7 text-white drop-shadow" :stroke-width="2.5" />
                                </div>

                                <!-- barrel hoops: horizontal bands -->
                                <template v-if="(v.type === 'BARREL' || v.type === 'BARRIQUE') && !isEmpty(v)">
                                    <div class="absolute inset-x-0 top-[20%] z-[5] h-[1.5px] bg-white/20" />
                                    <div class="absolute inset-x-0 top-[50%] z-[5] h-[1.5px] bg-white/20" />
                                    <div class="absolute inset-x-0 top-[80%] z-[5] h-[1.5px] bg-white/20" />
                                </template>
                                <!-- tank dome highlight -->
                                <div v-if="v.type === 'TANK' && !isEmpty(v)" class="absolute inset-x-[15%] top-0 z-[5] h-1.5 rounded-b-full bg-white/10" />

                                <!-- liquid, to its level, in the wine's colour -->
                                <div class="absolute inset-x-0 bottom-0" :style="{ height: `${fillPct(v)}%`, backgroundColor: tileColour(v), opacity: isEmpty(v) ? 0.15 : 0.8 }" />

                                <!-- what the tile says -->
                                <div class="absolute inset-0 z-10 flex flex-col items-center justify-between p-1">
                                    <span :class="cn('w-full truncate text-center text-[7px] leading-tight font-bold drop-shadow-sm', isEmpty(v) ? 'text-muted-foreground' : 'text-white/90')">{{ v.name }}</span>

                                    <div class="flex w-full min-w-0 flex-col items-center">
                                        <template v-if="firstLot(v)">
                                            <span v-if="boxes[v.id]!.w >= 40 && boxes[v.id]!.h >= 50" class="w-full truncate text-center text-[8px] leading-tight font-bold text-white drop-shadow-sm">{{ firstLot(v)!.name }}</span>
                                            <span v-if="boxes[v.id]!.w >= 35 && boxes[v.id]!.h >= 40 && firstLot(v)!.grape_variety" class="w-full truncate text-center text-[7px] leading-tight text-white/80 drop-shadow-sm">{{ firstLot(v)!.grape_variety }}</span>
                                            <span v-if="boxes[v.id]!.w >= 40 && boxes[v.id]!.h >= 60 && firstLot(v)!.vintage" class="w-full truncate text-center text-[7px] leading-tight text-white/70 drop-shadow-sm">{{ firstLot(v)!.vintage }}</span>
                                        </template>
                                        <component :is="typeIcon(v)" v-else class="size-3.5 text-muted-foreground drop-shadow" />
                                    </div>

                                    <span :class="cn('text-[7px] font-medium drop-shadow-sm', isEmpty(v) ? 'text-muted-foreground/60' : 'text-white/90')">{{ qty(v.current_volume) }}L</span>
                                </div>

                                <!-- free / total SO₂ (blue; the status colour in "Colour by SO₂") -->
                                <div
                                    v-if="!isEmpty(v) && so2Of(v)?.free != null && boxes[v.id]!.w >= 40"
                                    class="absolute top-0 right-0 z-20 rounded-bl-sm px-0.5 text-[6px] leading-tight font-bold text-white"
                                    :style="{ background: colourBy === 'so2' ? SO2_HEX[so2Status(so2Of(v)!.free)] : '#2563eb' }"
                                    :title="t('Free SO₂ (mg/L) · days since the reading')"
                                >
                                    {{ so2Of(v)!.free }}/{{ so2Of(v)!.total ?? '—' }}
                                </div>

                                <!-- resize handle -->
                                <span
                                    v-if="editing && isSel(v.id)"
                                    class="absolute right-0 bottom-0 z-30 size-3 cursor-nwse-resize bg-primary"
                                    @pointerdown.stop="onTileDown(v, $event, 'resize')"
                                />
                            </div>

                            <!-- tank legs -->
                            <div v-if="v.type === 'TANK'" class="flex justify-between px-[15%]" :style="{ width: `${boxes[v.id]!.w}px` }">
                                <div class="h-2 w-1.5 rounded-b-sm bg-muted-foreground/30" />
                                <div class="h-2 w-1.5 rounded-b-sm bg-muted-foreground/30" />
                            </div>
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
            <aside v-if="editing && showUnplaced" class="w-48 shrink-0 border border-border bg-card p-2 text-xs">
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

        <div v-if="!editing && colourBy === 'so2'" class="flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-muted-foreground">
            <span v-for="[key, label] in ([['critical', 'below 15'], ['low', '15–25'], ['ok', '25–45'], ['high', 'above 45'], ['no_data', 'no reading']] as const)" :key="key" class="flex items-center gap-1.5">
                <span class="inline-block size-2.5" :style="{ background: SO2_HEX[key] }" />{{ t(label) }}
            </span>
            <span>{{ t('Free SO₂, mg/L · days since the reading') }}</span>
        </div>

        <!-- Tooltip -->
        <Teleport to="body">
            <div
                v-if="hover"
                class="pointer-events-none fixed z-50 w-60 border border-border bg-card p-3 text-xs"
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
