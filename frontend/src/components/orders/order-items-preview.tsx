"use client";

import * as React from "react";

import { cn } from "@/lib/utils";
import { useTranslation } from "@/i18n/context";

/** A line item shaped just enough to preview it (full OrderItem is compatible). */
export interface OrderItemPreviewLine {
  name: string | null;
  quantity: number;
  unit_type: string;
  unit_size?: string | null;
  /** Inventory group, used when `group` rendering is enabled. */
  group?: string | null;
}

/**
 * List of an order's line items, mirroring order-mgmt: each line reads
 * "<qty>[#] × <product> (<unit_size>)", where a trailing "#" on the quantity
 * marks cases (no suffix = bottles). The vintage is already part of the name.
 *
 * Two modes:
 *  - default (compact): flat list capped at `max`; overflow → "+N more".
 *  - `group`: items grouped by inventory group, with an uppercase header per
 *    group when there's more than one — used on the orders list so the whole
 *    order shows at a glance. Pass `max={Infinity}` to show every line.
 */
export function OrderItemsPreview({
  items,
  max = 4,
  group = false,
  className,
}: {
  items: OrderItemPreviewLine[];
  max?: number;
  group?: boolean;
  className?: string;
}) {
  const { t } = useTranslation();
  if (items.length === 0) return null;

  const line = (item: OrderItemPreviewLine, key: React.Key) => (
    <li key={key} className="flex items-baseline gap-1 text-xs text-muted-foreground">
      <span
        className="w-8 shrink-0 text-right tabular-nums"
        title={t(`orders.unit.${item.unit_type}`, { count: item.quantity })}
      >
        {item.quantity}
        {item.unit_type === "cases" ? "#" : ""}
      </span>
      <span aria-hidden>×</span>
      <span className="truncate">
        {item.name ?? t("orders.customItem")}
        {item.name && item.unit_size ? <span className="ml-0.5">({item.unit_size})</span> : null}
      </span>
    </li>
  );

  if (!group) {
    const visible = items.slice(0, max);
    const rest = items.length - visible.length;
    return (
      <ul className={cn("space-y-0.5", className)}>
        {visible.map((item, i) => line(item, i))}
        {rest > 0 && (
          <li className="text-[10px] text-muted-foreground">{t("orders.moreItems", { count: rest })}</li>
        )}
      </ul>
    );
  }

  // Grouped: bucket by inventory group, preserving first-seen order.
  const groups = new Map<string, OrderItemPreviewLine[]>();
  for (const item of items) {
    const g = item.group || t("orders.itemGroupOther");
    const bucket = groups.get(g);
    if (bucket) bucket.push(item);
    else groups.set(g, [item]);
  }
  const multiGroup = groups.size > 1;
  const rest = Math.max(0, items.length - max);
  let shown = 0;

  return (
    <div className={cn("space-y-1", className)}>
      {Array.from(groups.entries()).map(([g, groupItems]) => {
        const rows = groupItems.filter(() => shown++ < max);
        if (rows.length === 0) return null;
        return (
          <div key={g}>
            {multiGroup && (
              <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground/70">{g}</p>
            )}
            <ul className="space-y-0.5">{rows.map((item, i) => line(item, `${g}-${i}`))}</ul>
          </div>
        );
      })}
      {rest > 0 && <p className="text-[10px] text-muted-foreground">{t("orders.moreItems", { count: rest })}</p>}
    </div>
  );
}
