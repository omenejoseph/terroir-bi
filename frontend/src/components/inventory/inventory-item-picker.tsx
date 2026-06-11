"use client";

import * as React from "react";
import { createPortal } from "react-dom";
import { ChevronsUpDown } from "lucide-react";

import { useInventory } from "@/hooks/use-inventory";
import type { InventoryItem } from "@/lib/types";
import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";

/**
 * Searchable picker over inventory items. Queries the inventory list API as you
 * type (server-side search), so it finds any item — not just the first page.
 * Selecting commits the chosen item; the label shown when collapsed is driven by
 * `valueLabel` so existing selections render even before a search runs.
 *
 * The dropdown is rendered in a portal (fixed-positioned under the trigger) so
 * it isn't clipped by `overflow-hidden` ancestors such as expandable cards.
 */
export function InventoryItemPicker({
  id,
  valueLabel,
  excludeId,
  onChange,
  placeholder,
  searchPlaceholder,
  emptyLabel,
  forSale = false,
}: {
  id?: string;
  valueLabel?: string;
  excludeId?: string;
  onChange: (item: InventoryItem) => void;
  placeholder: string;
  searchPlaceholder: string;
  emptyLabel: string;
  /** Restrict results to for-sale items (used when adding order lines). */
  forSale?: boolean;
}) {
  const [open, setOpen] = React.useState(false);
  const [query, setQuery] = React.useState("");
  const [debounced, setDebounced] = React.useState("");
  const [pos, setPos] = React.useState<{
    top: number;
    bottom: number;
    left: number;
    width: number;
    openUp: boolean;
    maxHeight: number;
  } | null>(null);
  const triggerRef = React.useRef<HTMLButtonElement>(null);
  const dropdownRef = React.useRef<HTMLDivElement>(null);

  // Debounce the search so we don't hit the API on every keystroke.
  React.useEffect(() => {
    const t = setTimeout(() => setDebounced(query.trim()), 250);
    return () => clearTimeout(t);
  }, [query]);

  // Keep the portal aligned with the trigger while open (incl. on scroll). Flip
  // above the trigger when there's more room there, and clamp the height to the
  // available space so the list always scrolls internally — never off-screen.
  const updatePosition = React.useCallback(() => {
    const el = triggerRef.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const margin = 8;
    const spaceBelow = window.innerHeight - r.bottom - margin;
    const spaceAbove = r.top - margin;
    const openUp = spaceBelow < 240 && spaceAbove > spaceBelow;
    setPos({
      top: r.bottom + 4,
      bottom: window.innerHeight - r.top + 4,
      left: r.left,
      width: r.width,
      openUp,
      maxHeight: Math.max(140, openUp ? spaceAbove : spaceBelow),
    });
  }, []);

  React.useLayoutEffect(() => {
    if (!open) return;
    updatePosition();
    window.addEventListener("resize", updatePosition);
    window.addEventListener("scroll", updatePosition, true); // capture: any scrolling ancestor
    return () => {
      window.removeEventListener("resize", updatePosition);
      window.removeEventListener("scroll", updatePosition, true);
    };
  }, [open, updatePosition]);

  React.useEffect(() => {
    if (!open) return;
    const onDocClick = (e: MouseEvent) => {
      const target = e.target as Node;
      if (triggerRef.current?.contains(target)) return;
      if (dropdownRef.current?.contains(target)) return;
      setOpen(false);
    };
    document.addEventListener("mousedown", onDocClick);
    return () => document.removeEventListener("mousedown", onDocClick);
  }, [open]);

  // Only query while the dropdown is open — a closed picker makes no calls.
  // Identical queries (same search) across rows are deduped + cached.
  const listQ = useInventory(
    {
      ...(debounced ? { search: debounced } : {}),
      ...(forSale ? { is_for_sale: true } : {}),
    },
    { enabled: open },
  );
  const items = React.useMemo(
    () => (listQ.data?.data ?? []).filter((i) => i.id !== excludeId),
    [listQ.data, excludeId],
  );

  function commit(item: InventoryItem) {
    onChange(item);
    setQuery("");
    setDebounced("");
    setOpen(false);
  }

  return (
    <>
      <button
        type="button"
        id={id}
        ref={triggerRef}
        onClick={() => setOpen((o) => !o)}
        className="flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <span className={cn("truncate", !valueLabel && "text-muted-foreground")}>
          {valueLabel || placeholder}
        </span>
        <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
      </button>

      {open &&
        pos &&
        typeof document !== "undefined" &&
        createPortal(
          <div
            ref={dropdownRef}
            style={{
              position: "fixed",
              left: pos.left,
              width: pos.width,
              maxHeight: pos.maxHeight,
              ...(pos.openUp ? { bottom: pos.bottom } : { top: pos.top }),
            }}
            className="z-50 flex flex-col overflow-hidden rounded-md border border-border bg-popover shadow-md"
          >
            <input
              autoFocus
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={searchPlaceholder}
              className="w-full shrink-0 border-b border-border bg-transparent px-3 py-2 text-sm focus-visible:outline-none"
            />
            <ul className="flex-1 overflow-auto p-1">
              {listQ.isLoading ? (
                <li className="flex justify-center py-3">
                  <Spinner className="size-4 text-muted-foreground" />
                </li>
              ) : items.length === 0 ? (
                <li className="px-2 py-1.5 text-sm text-muted-foreground">{emptyLabel}</li>
              ) : (
                items.map((item) => (
                  <li key={item.id}>
                    <button
                      type="button"
                      onClick={() => commit(item)}
                      className="flex w-full items-center rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
                    >
                      <span className="truncate">
                        {item.name} <span className="text-muted-foreground">({item.sku})</span>
                      </span>
                    </button>
                  </li>
                ))
              )}
            </ul>
          </div>,
          document.body,
        )}
    </>
  );
}
