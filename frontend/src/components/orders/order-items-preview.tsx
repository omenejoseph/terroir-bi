"use client";

import { cn } from "@/lib/utils";
import { useTranslation } from "@/i18n/context";

/** A line item shaped just enough to preview it (full OrderItem is compatible). */
export interface OrderItemPreviewLine {
  name: string | null;
  quantity: number;
  unit_type: string;
}

/**
 * Compact list of an order's line items, mirroring order-mgmt: each line reads
 * "<qty>[#] × <product>", where a trailing "#" on the quantity marks cases (no
 * suffix = bottles). Overflow collapses to a "+N more" line.
 */
export function OrderItemsPreview({
  items,
  max = 4,
  className,
}: {
  items: OrderItemPreviewLine[];
  max?: number;
  className?: string;
}) {
  const { t } = useTranslation();
  if (items.length === 0) return null;

  const visible = items.slice(0, max);
  const rest = items.length - visible.length;

  return (
    <ul className={cn("space-y-0.5", className)}>
      {visible.map((item, i) => (
        <li key={i} className="flex items-baseline gap-1 text-xs text-muted-foreground">
          <span
            className="w-8 shrink-0 text-right tabular-nums"
            title={t(`orders.unit.${item.unit_type}`, { count: item.quantity })}
          >
            {item.quantity}
            {item.unit_type === "cases" ? "#" : ""}
          </span>
          <span aria-hidden>×</span>
          <span className="truncate">{item.name ?? t("orders.customItem")}</span>
        </li>
      ))}
      {rest > 0 && (
        <li className="text-[10px] text-muted-foreground">{t("orders.moreItems", { count: rest })}</li>
      )}
    </ul>
  );
}
