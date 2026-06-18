"use client";

import * as React from "react";
import { Plus, Layers, Move, AlertTriangle } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { useSaveVesselLayout, useVessels } from "@/hooks/use-cellar";
import type { Vessel, VesselLayoutUpdate } from "@/lib/types";
import { fillRatio, so2Color, wineFill } from "@/lib/cellar-colors";
import { Button } from "@/components/ui/button";
import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";
import { VesselDialog } from "@/components/cellar/vessel-dialog";
import { BulkVesselDialog } from "@/components/cellar/bulk-vessel-dialog";
import { VesselInspector } from "@/components/cellar/vessel-inspector";

const DEFAULT_W = 64;
const DEFAULT_H = 96;
const GRID = 130;

interface Pt {
  x: number;
  y: number;
}

/**
 * Interactive cellar map. Vessels are absolutely positioned on a pannable,
 * zoomable canvas. In edit mode they can be dragged (single or multi-select via
 * shift-click); positions persist as one batch on drop. Clicking a vessel opens
 * the inspector to assign/unassign wine. Fill level + colour reflect the wine
 * type, and a badge shows the latest free/total SO₂.
 */
export function CellarMap() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const canManage = can("cellar.manage");
  const { data: vessels, isLoading, isError } = useVessels();
  const saveLayout = useSaveVesselLayout();

  const [edit, setEdit] = React.useState(false);
  const [room, setRoom] = React.useState<string | null>(null);
  const [selected, setSelected] = React.useState<Set<string>>(new Set());
  const [inspectId, setInspectId] = React.useState<string | null>(null);
  const [showAdd, setShowAdd] = React.useState(false);
  const [showBulk, setShowBulk] = React.useState(false);

  // Local position overrides while dragging (id -> point), flushed on drop.
  const [positions, setPositions] = React.useState<Record<string, Pt>>({});
  const [view, setView] = React.useState({ scale: 1, x: 0, y: 0 });

  const dragRef = React.useRef<{ start: Pt; origin: Record<string, Pt>; moved: boolean } | null>(null);
  const panRef = React.useRef<{ start: Pt; origin: Pt } | null>(null);

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

  // Resolve a vessel's on-canvas position: live drag override → stored → grid.
  const posOf = React.useCallback(
    (v: Vessel, index: number): Pt => {
      if (positions[v.id]) return positions[v.id];
      if (v.position_x !== null && v.position_y !== null) return { x: v.position_x, y: v.position_y };
      const perRow = 6;
      return { x: 24 + (index % perRow) * GRID, y: 24 + Math.floor(index / perRow) * (GRID + 20) };
    },
    [positions],
  );

  function onTilePointerDown(e: React.PointerEvent, v: Vessel) {
    if (!edit || !canManage) {
      setInspectId(v.id);
      return;
    }
    e.stopPropagation();
    (e.target as Element).setPointerCapture?.(e.pointerId);

    // Build/extend the selection.
    const next = new Set(selected);
    if (e.shiftKey) {
      next.has(v.id) ? next.delete(v.id) : next.add(v.id);
    } else if (!next.has(v.id)) {
      next.clear();
      next.add(v.id);
    }
    setSelected(next);

    const origin: Record<string, Pt> = {};
    shown.forEach((s, i) => {
      if (next.has(s.id)) origin[s.id] = posOf(s, i);
    });
    dragRef.current = { start: { x: e.clientX, y: e.clientY }, origin, moved: false };
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
    } else if (panRef.current) {
      setView((v) => ({
        ...v,
        x: panRef.current!.origin.x + (e.clientX - panRef.current!.start.x),
        y: panRef.current!.origin.y + (e.clientY - panRef.current!.start.y),
      }));
    }
  }

  function onPointerUp() {
    if (dragRef.current) {
      if (dragRef.current.moved) {
        const updates: VesselLayoutUpdate[] = Object.keys(dragRef.current.origin).map((id) => ({
          id,
          position_x: positions[id]?.x ?? null,
          position_y: positions[id]?.y ?? null,
        }));
        if (updates.length > 0) saveLayout.mutate(updates);
      }
      dragRef.current = null;
    }
    panRef.current = null;
  }

  function onCanvasPointerDown(e: React.PointerEvent) {
    if (e.button !== 0) return;
    setSelected(new Set());
    panRef.current = { start: { x: e.clientX, y: e.clientY }, origin: { x: view.x, y: view.y } };
  }

  function onWheel(e: React.WheelEvent) {
    const delta = -e.deltaY * 0.001;
    setView((v) => ({ ...v, scale: Math.min(2, Math.max(0.3, v.scale + delta)) }));
  }

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
        <div className="inline-flex flex-wrap gap-1 rounded-lg bg-muted p-1">
          {rooms.map((r) => (
            <button
              key={r}
              type="button"
              onClick={() => setRoom(r)}
              className={cn(
                "rounded-md px-3 py-1.5 text-sm transition-all",
                r === activeRoom
                  ? "bg-primary font-semibold text-primary-foreground shadow-sm"
                  : "font-medium text-muted-foreground hover:text-foreground",
              )}
            >
              {r}
            </button>
          ))}
        </div>
        <div className="ml-auto flex gap-2">
          {canManage && (
            <>
              <Button variant={edit ? "primary" : "outline"} size="sm" onClick={() => setEdit((v) => !v)}>
                <Move className="size-4" />
                {edit ? t("cellar.doneEditing") : t("cellar.editLayout")}
              </Button>
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
          className="relative h-[560px] overflow-hidden rounded-lg border border-border bg-muted/30 touch-none"
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
                selected={selected.has(v.id)}
                edit={edit}
                onPointerDown={(e) => onTilePointerDown(e, v)}
              />
            ))}
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
  selected,
  edit,
  onPointerDown,
}: {
  vessel: Vessel;
  pos: Pt;
  selected: boolean;
  edit: boolean;
  onPointerDown: (e: React.PointerEvent) => void;
}) {
  const w = vessel.map_width ?? DEFAULT_W;
  const h = vessel.map_height ?? DEFAULT_H;
  const lot = vessel.lots?.[0]?.lot ?? null;
  const ratio = fillRatio(vessel.current_volume, vessel.capacity_liters);
  const fill = wineFill(lot?.wine_type ?? null);
  const free = lot?.free_so2 != null ? Number(lot.free_so2) : null;
  const isBarrel = vessel.type === "BARREL" || vessel.type === "BARRIQUE";

  return (
    <div
      className={cn(
        "absolute flex select-none flex-col items-center",
        edit ? "cursor-move" : "cursor-pointer",
      )}
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
        {vessel.is_faulty && (
          <AlertTriangle className="absolute left-0.5 top-0.5 size-3 text-destructive" />
        )}
      </div>
      <span className="mt-1 max-w-full truncate text-[11px] font-medium">{vessel.name}</span>
      {lot && <span className="max-w-full truncate text-[10px] text-muted-foreground">{lot.name}</span>}
    </div>
  );
}
