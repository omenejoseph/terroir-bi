<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Maximize2, Search, X } from 'lucide-vue-next';

import Input from '@/components/ui/Input.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useTranslations } from '@/composables/useTranslations';
import { WINE_TYPE_SWATCH, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import type { Vessel } from '@/types/cellar';
import type { TabItem } from '@/types/ui';

/**
 * The cellar map reduced to one job — choosing vessels. Same stored layout as
 * the big map, read-only: tap a vessel to toggle it, drag a box with the mouse
 * to select many (touch keeps normal scrolling), or search by vessel code or
 * wine and select every match. Empty vessels show as faint outlines for
 * orientation but cannot be picked.
 */
const props = defineProps<{ vessels: Vessel[]; modelValue: string[] }>();
const emit = defineEmits<{ 'update:modelValue': [ids: string[]] }>();

const { t } = useTranslations();

const DEFAULT_W = 60;
const DEFAULT_H = 60;

interface Tile {
    v: Vessel;
    x: number;
    y: number;
    w: number;
    h: number;
    lot: string | null;
    liters: string;
    color: string | null;
}

const tiles = computed<Tile[]>(() =>
    props.vessels
        .filter((v) => v.position_x !== null && v.position_y !== null)
        .map((v) => {
            const first = v.lots?.[0]?.lot ?? null;

            return {
                v,
                x: v.position_x ?? 0,
                y: v.position_y ?? 0,
                w: v.map_width ?? DEFAULT_W,
                h: v.map_height ?? DEFAULT_H,
                lot: first ? first.name : null,
                liters: v.current_volume,
                color: first?.wine_type ? WINE_TYPE_SWATCH[first.wine_type] : null,
            };
        }),
);

const rooms = computed(() => {
    const names: string[] = [];
    for (const tile of tiles.value) {
        const room = tile.v.room ?? 'Main Cellar';
        if (!names.includes(room)) names.push(room);
    }

    return names;
});

const activeRoom = ref('');
watch(
    rooms,
    (list) => {
        // Open on the first room that has wine to pick, not an empty barrel hall.
        if (!list.includes(activeRoom.value)) {
            activeRoom.value = list.find((r) => tiles.value.some((tile) => (tile.v.room ?? 'Main Cellar') === r && tile.lot !== null)) ?? list[0] ?? '';
        }
    },
    { immediate: true },
);

const roomTabs = computed<TabItem[]>(() => rooms.value.map((r) => ({ label: t(r), value: r })));
const roomTiles = computed(() => tiles.value.filter((tile) => (tile.v.room ?? 'Main Cellar') === activeRoom.value));
const selected = computed(() => new Set(props.modelValue));

const query = ref('');
const matches = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (q === '') return null;

    return tiles.value.filter((tile) => tile.lot !== null && (tile.v.name.toLowerCase().includes(q) || tile.lot.toLowerCase().includes(q)));
});
const allMatchesOn = computed(() => (matches.value ?? []).length > 0 && (matches.value ?? []).every((m) => selected.value.has(m.v.id)));

function setMany(ids: string[], on: boolean): void {
    const next = new Set(props.modelValue);
    for (const id of ids) {
        if (on) next.add(id);
        else next.delete(id);
    }
    emit('update:modelValue', [...next]);
}

function toggle(tile: Tile): void {
    if (tile.lot === null) return;
    setMany([tile.v.id], !selected.value.has(tile.v.id));
}

const fullscreen = ref(false);
function onKey(e: KeyboardEvent): void {
    if (e.key === 'Escape') fullscreen.value = false;
}
watch(fullscreen, (on) => (on ? window.addEventListener('keydown', onKey) : window.removeEventListener('keydown', onKey)));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

/**
 * Inline, a typical vessel is scaled to ~64px so it can carry its wine and
 * litres, scrolling when the room outgrows the panel. Fullscreen fits the
 * whole room to the viewport.
 */
const layout = computed(() => {
    const list = roomTiles.value;
    if (list.length === 0) return { scale: 1, minX: 0, minY: 0, width: 0, height: 0 };
    const minX = Math.min(...list.map((v) => v.x));
    const minY = Math.min(...list.map((v) => v.y));
    const rawW = Math.max(Math.max(...list.map((v) => v.x + v.w)) - minX, 1);
    const rawH = Math.max(Math.max(...list.map((v) => v.y + v.h)) - minY, 1);
    let scale: number;
    if (fullscreen.value && typeof window !== 'undefined') {
        scale = Math.min((window.innerWidth - 96) / rawW, (window.innerHeight - 220) / rawH, 1.8);
    } else {
        const avgW = list.reduce((s, v) => s + v.w, 0) / list.length;
        scale = Math.min(Math.max(64 / avgW, 0.35), 1.4);
    }

    return { scale, minX, minY, width: rawW * scale, height: rawH * scale };
});

function box(tile: Tile) {
    const l = layout.value;

    return {
        left: `${(tile.x - l.minX) * l.scale}px`,
        top: `${(tile.y - l.minY) * l.scale}px`,
        width: `${Math.max(tile.w * l.scale, 20)}px`,
        height: `${Math.max(tile.h * l.scale, 20)}px`,
    };
}

// Rubber-band selection — mouse only. Capture starts only once a real drag
// begins, otherwise the click would be retargeted and plain taps would die.
const dragStart = ref<{ x: number; y: number } | null>(null);
const dragRect = ref<{ x: number; y: number; w: number; h: number } | null>(null);
let suppressClick = false;

function point(e: PointerEvent): { x: number; y: number } {
    const r = (e.currentTarget as HTMLElement).getBoundingClientRect();

    return { x: e.clientX - r.left, y: e.clientY - r.top };
}

function onDown(e: PointerEvent): void {
    if (e.pointerType !== 'mouse' || e.button !== 0) return;
    dragStart.value = point(e);
}

function onMove(e: PointerEvent): void {
    const s = dragStart.value;
    if (s === null) return;
    const p = point(e);
    if (dragRect.value === null) {
        if (Math.hypot(p.x - s.x, p.y - s.y) < 6) return;
        (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
    }
    dragRect.value = { x: Math.min(s.x, p.x), y: Math.min(s.y, p.y), w: Math.abs(p.x - s.x), h: Math.abs(p.y - s.y) };
}

function onUp(): void {
    const r = dragRect.value;
    if (r !== null) {
        const l = layout.value;
        const hits = roomTiles.value.filter((tile) => {
            if (tile.lot === null) return false;
            const x = (tile.x - l.minX) * l.scale;
            const y = (tile.y - l.minY) * l.scale;
            const w = Math.max(tile.w * l.scale, 20);
            const h = Math.max(tile.h * l.scale, 20);

            return x < r.x + r.w && x + w > r.x && y < r.y + r.h && y + h > r.y;
        });
        if (hits.length) {
            const ids = hits.map((h) => h.v.id);
            setMany(ids, !ids.every((id) => selected.value.has(id)));
        }
        suppressClick = true;
        setTimeout(() => (suppressClick = false), 0);
    }
    dragStart.value = null;
    dragRect.value = null;
}

function onTileClick(tile: Tile): void {
    if (suppressClick) return;
    toggle(tile);
}

const roomSelectable = computed(() => roomTiles.value.filter((tile) => tile.lot !== null));
const allRoomOn = computed(() => roomSelectable.value.length > 0 && roomSelectable.value.every((tile) => selected.value.has(tile.v.id)));
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-col gap-3',
                fullscreen && 'fixed inset-0 z-50 overflow-auto bg-background p-6',
            )
        "
    >
        <div class="flex flex-wrap items-center gap-2">
            <Tabs v-if="rooms.length > 1" :items="roomTabs" :current="activeRoom" @select="activeRoom = $event" />
            <button
                v-if="roomSelectable.length"
                type="button"
                class="text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                @click="setMany(roomSelectable.map((r) => r.v.id), !allRoomOn)"
            >
                {{ allRoomOn ? t('Clear room') : t('Select room') }}
            </button>
            <button
                type="button"
                class="ml-auto p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                :aria-label="fullscreen ? t('Exit full screen') : t('Full screen')"
                @click="fullscreen = !fullscreen"
            >
                <X v-if="fullscreen" class="size-4" />
                <Maximize2 v-else class="size-4" />
            </button>
        </div>

        <div class="relative">
            <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input v-model="query" class="pl-8" :placeholder="t('Search vessel or wine… (e.g. A20, Syrah)')" />
        </div>
        <div v-if="matches !== null" class="flex items-center gap-2 text-xs">
            <template v-if="matches.length">
                <button
                    type="button"
                    class="font-medium text-foreground underline underline-offset-2"
                    @click="setMany(matches.map((m) => m.v.id), !allMatchesOn)"
                >
                    {{ allMatchesOn ? t('Deselect all matches') : t('Select all matches (:n)', { n: matches.length }) }}
                </button>
                <span class="truncate text-muted-foreground">{{ matches.map((m) => m.v.name).join(', ') }}</span>
            </template>
            <span v-else class="text-muted-foreground">{{ t('No vessel with wine matches') }}</span>
        </div>

        <div class="overflow-auto border border-border bg-muted/40 p-2">
            <div
                v-if="roomTiles.length"
                class="relative select-none"
                :style="{ width: `${Math.max(layout.width, 200)}px`, height: `${Math.max(layout.height + 8, 160)}px` }"
                @pointerdown="onDown"
                @pointermove="onMove"
                @pointerup="onUp"
                @pointercancel="onUp"
            >
                <button
                    v-for="tile in roomTiles"
                    :key="tile.v.id"
                    type="button"
                    :disabled="tile.lot === null"
                    :aria-pressed="selected.has(tile.v.id)"
                    :title="tile.lot ? `${tile.v.name} · ${tile.lot} · ${qty(tile.liters)} L` : `${tile.v.name} · ${t('empty')}`"
                    :class="
                        cn(
                            'absolute flex flex-col items-center justify-center overflow-hidden border text-center leading-tight transition-colors',
                            tile.v.type === 'BARREL' || tile.v.type === 'BARRIQUE' ? 'rounded-full' : '',
                            tile.lot === null
                                ? 'cursor-default border-dashed border-border bg-transparent text-muted-foreground/60'
                                : selected.has(tile.v.id)
                                  ? 'border-primary bg-primary text-primary-foreground'
                                  : 'border-border bg-card text-foreground hover:border-foreground/50',
                        )
                    "
                    :style="box(tile)"
                    @click="onTileClick(tile)"
                >
                    <span class="flex items-center gap-1 text-[11px] font-semibold">
                        <span v-if="tile.color" class="inline-block size-1.5 rounded-full" :style="{ background: tile.color }" />
                        {{ tile.v.name }}
                    </span>
                    <span v-if="tile.lot" class="max-w-full truncate px-0.5 text-[9px] opacity-80">{{ tile.lot }}</span>
                    <span v-if="tile.lot" class="text-[9px] tabular-nums opacity-80">{{ qty(tile.liters) }} L</span>
                </button>
                <div
                    v-if="dragRect"
                    class="pointer-events-none absolute border border-primary bg-primary/10"
                    :style="{ left: `${dragRect.x}px`, top: `${dragRect.y}px`, width: `${dragRect.w}px`, height: `${dragRect.h}px` }"
                />
            </div>
            <p v-else class="px-3 py-8 text-center text-xs text-muted-foreground">{{ t('No vessels placed on the map in this room yet.') }}</p>
        </div>
    </div>
</template>
