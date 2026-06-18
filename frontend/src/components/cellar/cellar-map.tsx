"use client";

import * as React from "react";
import { Plus, Layers, Move, AlertTriangle, Undo2, Pencil } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { useRenameRoom, useSaveVesselLayout, useVessels } from "@/hooks/use-cellar";
import type { Vessel, VesselLayoutUpdate } from "@/lib/types";
import { fillRatio, so2Color, wineFill } from "@/lib/cellar-colors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";
import { VesselDialog } from "@/components/cellar/vessel-dialog";
import { BulkVesselDialog } from "@/components/cellar/bulk-vessel-dialog";
import { VesselInspector } from "@/components/cellar/vessel-inspector";

const DEFAULT_W = 64;
const DEFAULT_H = 96;
const MIN_SIZE = 32;
const GRID = 130;

interface Pt {
  x: number;
  y: number;
}
interface Size {
  w: number;
  h: number;
}
interface Rect {
  x: number;
  y: number;
  w: number;
  h: number;
}

/**
 * Interactive cellar map. Vessels sit on a pannable, zoomable canvas. In edit
 * mode they can be dragged (single or shift/lasso multi-select), resized via a
 * corner handle, and moves/resizes are undoable (Cmd/Ctrl+Z) and persisted as a
 * batch. Clicking a vessel opens the inspector to assign/unassign wine. Fill
 * level + colour reflect the wine type and a badge shows the latest free SO₂.
 */
export function CellarMap() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const canManage = can("cellar.manage");
  const { data: vessels, isLoading, isError } = useVessels();
  const saveLayout = useSaveVesselLayout();
  const renameRoom = useRenameRoom();

  const [edit, setEdit] = React.useState(false);
  const [room, setRoom] = React.useState<string | null>(null);
  const [selected, setSelected] = React.useState<Set<string>>(new Set());
  const [inspectId, setInspectId] = React.useState<string | null>(null);
  const [showAdd, setShowAdd] = React.useState(false);
  const [showBulk, setShowBulk] = React.useState(false);
  const [renaming, setRenaming] = React.useState<string | null>(null);

  // Live overrides while dragging/resizing (flushed to the API on drop).
  const [positions, setPositions] = React.useState<Record<string, Pt>>({});
  const [sizes, setSizes] = React.useState<Record<string, Size>>({});
  const [view, setView] = React.useState({ scale: 1, x: 0, y: 0 });
  const [lasso, setLasso] = React.useState<Rect | null>(null);

  const containerRef = React.useRef<HTMLDivElement>(null);
  const dragRef = React.useRef<{ start: Pt; origin: Record<string, Pt>; moved: boolean } | null>(null);
  const panRef = React.useRef<{ start: Pt; origin: Pt } | null>(null);
  const resizeRef = React.useRef<{ id: string; start: Pt; orig: Size } | null>(null);
  const lassoRef = React.useRef<{ start: Pt } | null>(null);
  // Undo stack of full effective-position snapshots (positions only).
  const historyRef = React.useRef<Record<string, Pt>[]>([]);

  const rooms = React.useMemo(() => {
    const set = new Set<string>();
    (vessels ?? []).forEach((v) => set.add(v.room || "Main Cellar"));
    return Array.from(set).sort();
  }, [vessels]);

  const activeRoom = room ?? rooms[0] ?? "Main Cellar";

  const shown = React.useMemo(
    () => (vessels ?? []).filter((v) => (v.room || "Main Cellar") === activeRoom),
    [vessels, activeRoom],
  );

  const posOf = React.useCallback(
    (v: Vessel, index: number): Pt => {
      if (positions[v.id]) return positions[v.id];
      if (v.position_x !== null && v.position_y !== null) return { x: v.position_x, y: v.position_y };
      const perRow = 6;
      return { x: 24 + (index % perRow) * GRID, y: 24 + Math.floor(index / perRow) * (GRID + 20) };
    },
    [positions],
  );

  const sizeOf = React.useCallback(
    (v: Vessel): Size => sizes[v.id] ?? { w: v.map_width ?? DEFAULT_W, h: v.map_height ?? DEFAULT_H },
    [sizes],
  );

  const snapshotPositions = React.useCallback((): Record<string, Pt> => {
    const map: Record<string, Pt> = {};
    shown.forEach((v, i) => {
      map[v.id] = posOf(v, i);
    });
    return map;
  }, [shown, posOf]);

  const toCanvas = React.useCallback(
    (clientX: number, clientY: number): Pt => {
      const r = containerRef.current?.getBoundingClientRect();
      const left = r?.left ?? 0;
      const top = r?.top ?? 0;
      return { x: (clientX - left - view.x) / view.scale, y: (clientY - top - view.y) / view.scale };
    },
    [view],
  );

  /* --- Tile drag ---------------------------------------------------------- */

  function onTilePointerDown(e: React.PointerEvent, v: Vessel) {
    if (!edit || !canManage) {
      setInspectId(v.id);
      return;
    }
    e.stopPropagation();
    (e.target as Element).setPointerCapture?.(e.pointerId);

    const next = new Set(selected);
    if (e.shiftKey) {
      next.has(v.id) ? next.delete(v.id) : next.add(v.id);
    } else if (!next.has(v.id)) {
      next.clear();
      next.add(v.id);
    }
    setSelected(next);

    historyRef.current.push(snapshotPositions());
    const origin: Record<string, Pt> = {};
    shown.forEach((s, i) => {
      if (next.has(s.id)) origin[s.id] = posOf(s, i);
    });
    dragRef.current = { start: { x: e.clientX, y: e.clientY }, origin, moved: false };
  }

  function onResizePointerDown(e: React.PointerEvent, v: Vessel) {
    e.stopPropagation();
    (e.target as Element).setPointerCapture?.(e.pointerId);
    historyRef.current.push(snapshotPositions());
    resizeRef.current = { id: v.id, start: { x: e.clientX, y: e.clientY }, orig: sizeOf(v) };
  }

  function onPointerMove(e: React.PointerEvent) {
    if (dragRef.current) {
      const dx = (e.clientX - dragRef.current.start.x) / view.scale;
      const dy = (e.clientY - dragRef.current.start.y) / view.scale;
      if (Math.abs(dx) + Math.abs(dy) > 2) dragRef.current.moved = true;
      const updated: Record<string, Pt> = { ...positions };
      for (const [id, p] of Object.entries(dragRef.current.origin)) {
        updated[id] = { x: Math.round(p.x + dx), y: Math.round(p.y + dy) };
      }
      setPositions(updated);
    } else if (resizeRef.current) {
      const dw = (e.clientX - resizeRef.current.start.x) / view.scale;
      const dh = (e.clientY - resizeRef.current.start.y) / view.scale;
      setSizes((s) => ({
        ...s,
        [resizeRef.current!.id]: {
          w: Math.max(MIN_SIZE, Math.round(resizeRef.current!.orig.w + dw)),
          h: Math.max(MIN_SIZE, Math.round(resizeRef.current!.orig.h + dh)),
        },
      }));
    } else if (lassoRef.current) {
      const a = lassoRef.current.start;
      const b = toCanvas(e.clientX, e.clientY);
      setLasso({ x: Math.min(a.x, b.x), y: Math.min(a.y, b.y), w: Math.abs(a.x - b.x), h: Math.abs(a.y - b.y) });
    } else if (panRef.current) {
      setView((v) => ({
        ...v,
        x: panRef.current!.origin.x + (e.clientX - panRef.current!.start.x),
        y: panRef.current!.origin.y + (e.clientY - panRef.current!.start.y),
      }));
    }
  }

  function persistPositions(ids: string[]) {
    const updates: VesselLayoutUpdate[] = ids.map((id) => ({
      id,
      position_x: positions[id]?.x ?? null,
      position_y: positions[id]?.y ?? null,
    }));
    if (updates.length > 0) saveLayout.mutate(updates);
  }

  function onPointerUp() {
    if (dragRef.current) {
      if (dragRef.current.moved) persistPositions(Object.keys(dragRef.current.origin));
      else historyRef.current.pop(); // no move → discard the snapshot
      dragRef.current = null;
    }
    if (resizeRef.current) {
      const id = resizeRef.current.id;
      const size = sizes[id];
      if (size) saveLayout.mutate([{ id, map_width: size.w, map_height: size.h }]);
      resizeRef.current = null;
    }
    if (lassoRef.current) {
      finishLasso();
      lassoRef.current = null;
      setLasso(null);
    }
    panRef.current = null;
  }

  function finishLasso() {
    if (!lasso) return;
    const hit = new Set<string>();
    shown.forEach((v, i) => {
      const p = posOf(v, i);
      const s = sizeOf(v);
      const intersects =
        p.x < lasso.x + lasso.w && p.x + s.w > lasso.x && p.y < lasso.y + lasso.h && p.y + s.h > lasso.y;
      if (intersects) hit.add(v.id);
    });
    setSelected(hit);
  }

  function onCanvasPointerDown(e: React.PointerEvent) {
    if (e.button !== 0) return;
    if (edit && canManage) {
      setSelected(new Set());
      lassoRef.current = { start: toCanvas(e.clientX, e.clientY) };
    } else {
      panRef.current = { start: { x: e.clientX, y: e.clientY }, origin: { x: view.x, y: view.y } };
    }
  }

  function onWheel(e: React.WheelEvent) {
    setView((v) => ({ ...v, scale: Math.min(2, Math.max(0.3, v.scale - e.deltaY * 0.001)) }));
  }

  // Undo (Cmd/Ctrl+Z): restore the last position snapshot and persist it.
  React.useEffect(() => {
    if (!edit) return;
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "z") {
        e.preventDefault();
        const snap = historyRef.current.pop();
        if (!snap) return;
        setPositions((prev) => ({ ...prev, ...snap }));
        saveLayout.mutate(
          Object.entries(snap).map(([id, p]) => ({ id, position_x: p.x, position_y: p.y })),
        );
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [edit, saveLayout]);

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Spinner />
      </div>
    );
  }
  if (isError) {
    return <p className="text-sm text-destructive">{t("cellar.error")}</p>;
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <div className="inline-flex flex-wrap items-center gap-1 rounded-lg bg-muted p-1">
          {rooms.map((r) =>
            renaming === r ? (
              <Input
                key={r}
                autoFocus
                defaultValue={r}
                className="h-8 w-36"
                onBlur={(e) => {
                  const to = e.target.value.trim();
                  if (to && to !== r) renameRoom.mutate({ from: r, to });
                  setRenaming(null);
                  if (to && to !== r) setRoom(to);
                }}
                onKeyDown={(e) => {
                  if (e.key === "Enter") (e.target as HTMLInputElement).blur();
                  if (e.key === "Escape") setRenaming(null);
                }}
              />
            ) : (
              <button
                key={r}
                type="button"
                onClick={() => setRoom(r)}
                onDoubleClick={() => edit && canManage && setRenaming(r)}
                className={cn(
                  "rounded-md px-3 py-1.5 text-sm transition-all",
                  r === activeRoom
                    ? "bg-primary font-semibold text-primary-foreground shadow-sm"
                    : "font-medium text-muted-foreground hover:text-foreground",
                )}
              >
                {r}
              </button>
            ),
          )}
        </div>
        <div className="ml-auto flex flex-wrap gap-2">
          {canManage && (
            <>
              <Button variant={edit ? "primary" : "outline"} size="sm" onClick={() => setEdit((v) => !v)}>
                <Move className="size-4" />
                {edit ? t("cellar.doneEditing") : t("cellar.editLayout")}
              </Button>
              {edit && (
                <>
                  <Button variant="outline" size="sm" onClick={() => setRenaming(activeRoom)}>
                    <Pencil className="size-4" />
                    {t("cellar.renameRoom")}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={historyRef.current.length === 0}
                    onClick={() => {
                      const snap = historyRef.current.pop();
                      if (!snap) return;
                      setPositions((prev) => ({ ...prev, ...snap }));
                      saveLayout.mutate(Object.entries(snap).map(([id, p]) => ({ id, position_x: p.x, position_y: p.y })));
                    }}
                  >
                    <Undo2 className="size-4" />
                    {t("cellar.undo")}
                  </Button>
                </>
              )}
              <Button variant="outline" size="sm" onClick={() => setShowBulk(true)}>
                <Layers className="size-4" />
                {t("cellar.bulkVessels")}
              </Button>
              <Button variant="outline" size="sm" onClick={() => setShowAdd(true)}>
                <Plus className="size-4" />
                {t("cellar.addVessel")}
              </Button>
            </>
          )}
        </div>
      </div>

      {shown.length === 0 ? (
        <div className="flex h-64 items-center justify-center rounded-lg border border-dashed border-border text-sm text-muted-foreground">
          {t("cellar.emptyVessels")}
        </div>
      ) : (
        <div
          ref={containerRef}
          className="relative h-[560px] touch-none overflow-hidden rounded-lg border border-border bg-muted/30"
          onPointerDown={onCanvasPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={onPointerUp}
          onWheel={onWheel}
        >
          <div
            className="absolute left-0 top-0 origin-top-left"
            style={{ transform: `translate(${view.x}px, ${view.y}px) scale(${view.scale})` }}
          >
            {shown.map((v, i) => (
              <VesselTile
                key={v.id}
                vessel={v}
                pos={posOf(v, i)}
                size={sizeOf(v)}
                selected={selected.has(v.id)}
                edit={edit}
                onPointerDown={(e) => onTilePointerDown(e, v)}
                onResizePointerDown={(e) => onResizePointerDown(e, v)}
              />
            ))}
            {lasso && (
              <div
                className="pointer-events-none absolute border border-primary bg-primary/10"
                style={{ left: lasso.x, top: lasso.y, width: lasso.w, height: lasso.h }}
              />
            )}
          </div>
          <div className="pointer-events-none absolute bottom-2 right-2 rounded bg-card/80 px-2 py-1 text-xs text-muted-foreground">
            {Math.round(view.scale * 100)}%
          </div>
        </div>
      )}

      {inspectId && (
        <VesselInspector vesselId={inspectId} onClose={() => setInspectId(null)} canManage={canManage} />
      )}
      {showAdd && <VesselDialog room={activeRoom} onClose={() => setShowAdd(false)} />}
      {showBulk && <BulkVesselDialog room={activeRoom} onClose={() => setShowBulk(false)} />}
    </div>
  );
}

function VesselTile({
  vessel,
  pos,
  size,
  selected,
  edit,
  onPointerDown,
  onResizePointerDown,
}: {
  vessel: Vessel;
  pos: Pt;
  size: Size;
  selected: boolean;
  edit: boolean;
  onPointerDown: (e: React.PointerEvent) => void;
  onResizePointerDown: (e: React.PointerEvent) => void;
}) {
  const { w, h } = size;
  const lot = vessel.lots?.[0]?.lot ?? null;
  const ratio = fillRatio(vessel.current_volume, vessel.capacity_liters);
  const fill = wineFill(lot?.wine_type ?? null);
  const free = lot?.free_so2 != null ? Number(lot.free_so2) : null;
  const isBarrel = vessel.type === "BARREL" || vessel.type === "BARRIQUE";

  return (
    <div
      className={cn("absolute flex select-none flex-col items-center", edit ? "cursor-move" : "cursor-pointer")}
      style={{ left: pos.x, top: pos.y, width: w }}
      onPointerDown={onPointerDown}
    >
      <div
        className={cn(
          "relative overflow-hidden border bg-card",
          isBarrel ? "rounded-[45%]" : "rounded-md rounded-t-2xl",
          selected ? "border-primary ring-2 ring-primary" : "border-border",
          vessel.is_faulty && "ring-2 ring-destructive",
          ratio <= 0 && "border-dashed opacity-70",
        )}
        style={{ width: w, height: h }}
      >
        <div
          className="absolute inset-x-0 bottom-0 transition-[height]"
          style={{ height: `${ratio * 100}%`, backgroundColor: fill, opacity: 0.85 }}
        />
        {free !== null && (
          <span
            className="absolute right-0.5 top-0.5 rounded px-1 text-[9px] font-semibold text-white"
            style={{ backgroundColor: so2Color(free) }}
          >
            {Math.round(free)}
          </span>
        )}
        {vessel.is_faulty && <AlertTriangle className="absolute left-0.5 top-0.5 size-3 text-destructive" />}
        {edit && selected && (
          <span
            role="button"
            aria-label="resize"
            className="absolute bottom-0 right-0 size-3 cursor-se-resize rounded-sm bg-primary"
            onPointerDown={onResizePointerDown}
          />
        )}
      </div>
      <span className="mt-1 max-w-full truncate text-[11px] font-medium">{vessel.name}</span>
      {lot && <span className="max-w-full truncate text-[10px] text-muted-foreground">{lot.name}</span>}
    </div>
  );
}
