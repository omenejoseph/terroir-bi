"use client";

import * as React from "react";
import { Package, Pencil, Plus, X } from "lucide-react";

import { useResolvedPrices } from "@/hooks/use-customers";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { majorToMinor } from "@/lib/money";
import {
  SALES_UNITS,
  type InventoryItem,
  type Money,
  type OrderItemInput,
  type OrderItemUnit,
} from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { InventoryItemPicker } from "@/components/inventory/inventory-item-picker";

export interface DraftLine {
  key: string;
  kind: "catalog" | "custom";
  inventory_item_id: string;
  name: string;
  sku: string;
  quantity: string;
  unit_type: OrderItemUnit;
  unit_price: string;
  custom_description: string;
  gift: boolean;
  bottles_per_case: number;
  default_price: Money | null;
}

let counter = 0;
const nextKey = () => `line-${counter++}`;

export function blankCatalogLine(): DraftLine {
  return {
    key: nextKey(),
    kind: "catalog",
    inventory_item_id: "",
    name: "",
    sku: "",
    quantity: "1",
    unit_type: "bottles",
    unit_price: "",
    custom_description: "",
    gift: false,
    bottles_per_case: 1,
    default_price: null,
  };
}

export function blankCustomLine(): DraftLine {
  return { ...blankCatalogLine(), kind: "custom", quantity: "1" };
}

/** Convert draft lines to the API payload, dropping empty/incomplete rows. */
export function linesToItems(lines: DraftLine[]): OrderItemInput[] {
  const out: OrderItemInput[] = [];
  for (const line of lines) {
    const quantity = Number(line.quantity);
    if (!Number.isFinite(quantity) || quantity < 1) continue;
    // A gift line is free regardless of any typed price. Prices are entered in
    // major units (€) and converted to the API's minor units here.
    const price = line.gift ? "0" : line.unit_price.trim();
    const priceMinor = majorToMinor(price);
    if (line.kind === "catalog") {
      if (!line.inventory_item_id) continue;
      out.push({
        inventory_item_id: line.inventory_item_id,
        quantity,
        unit_type: line.unit_type,
        ...(priceMinor === null ? {} : { unit_price: priceMinor }),
      });
    } else {
      const description = line.custom_description.trim();
      if (!description || priceMinor === null) continue;
      out.push({
        inventory_item_id: null,
        quantity,
        unit_type: line.unit_type,
        unit_price: priceMinor,
        custom_description: description,
      });
    }
  }
  return out;
}

export function OrderLineItemsEditor({
  lines,
  onChange,
  customerId,
  onSubtotalChange,
}: {
  lines: DraftLine[];
  onChange: (lines: DraftLine[]) => void;
  /** When set, prices resolve for this customer (custom/tier/rebate); else the list price. */
  customerId?: string;
  /** Reports the live items subtotal (minor units) + currency as lines/prices change. */
  onSubtotalChange?: (minor: number, currency: string) => void;
}) {
  const { t } = useTranslation();
  const { moneyObject } = useFormatters();

  const itemIds = React.useMemo(
    () =>
      lines
        .filter((l) => l.kind === "catalog" && l.inventory_item_id !== "")
        .map((l) => l.inventory_item_id),
    [lines],
  );
  const resolvedQ = useResolvedPrices(customerId ?? "", itemIds);

  // The per-unit price (minor) for a catalog line: a manual override wins, else the
  // customer-resolved per-bottle price (scaled for cases), else the list price.
  function unitMinor(line: DraftLine): number | null {
    if (line.unit_price.trim() !== "") return majorToMinor(line.unit_price);
    const perBottle = resolvedQ.data?.[line.inventory_item_id]?.minor ?? line.default_price?.minor;
    if (perBottle == null) return null;
    return line.unit_type === "cases" ? perBottle * Math.max(1, line.bottles_per_case) : perBottle;
  }
  function currencyFor(line: DraftLine): string {
    return (
      line.default_price?.currency ?? resolvedQ.data?.[line.inventory_item_id]?.currency ?? "EUR"
    );
  }

  // Live items subtotal — sum of each line's unit price × quantity (gifts = 0).
  const resolvedData = resolvedQ.data;
  const subtotal = React.useMemo(() => {
    let sum = 0;
    let currency = "EUR";
    for (const line of lines) {
      const c = line.default_price?.currency ?? resolvedData?.[line.inventory_item_id]?.currency;
      if (c) currency = c;
      const u = unitMinor(line);
      const qty = Number(line.quantity);
      if (u != null && Number.isFinite(qty) && qty > 0) sum += u * qty;
    }
    return { minor: sum, currency };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lines, resolvedData]);

  const subtotalCb = React.useRef(onSubtotalChange);
  subtotalCb.current = onSubtotalChange;
  React.useEffect(() => {
    subtotalCb.current?.(subtotal.minor, subtotal.currency);
  }, [subtotal]);

  function update(key: string, patch: Partial<DraftLine>) {
    onChange(lines.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  }
  function remove(key: string) {
    onChange(lines.filter((l) => l.key !== key));
  }
  function toggleGift(key: string, gift: boolean) {
    // Gifting forces the price to 0; un-gifting clears it (catalog → auto-price).
    update(key, { gift, unit_price: gift ? "0" : "" });
  }
  function selectItem(key: string, item: InventoryItem) {
    // A catalog item can only be ordered in its sales unit (strict).
    update(key, {
      inventory_item_id: item.id,
      name: item.name,
      sku: item.sku,
      unit_type: (item.sales_unit as OrderItemUnit | null) ?? "bottles",
      bottles_per_case: item.bottles_per_case ?? 1,
      default_price: item.default_price,
    });
  }

  return (
    <div className="space-y-3">
      {lines.map((line) => (
        <div key={line.key} className="rounded-lg border border-border p-3">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div className="flex-1 space-y-1">
              <label className="text-xs text-muted-foreground">
                {line.kind === "catalog" ? t("orders.items.item") : t("orders.items.customDescription")}
              </label>
              {line.kind === "catalog" ? (
                <InventoryItemPicker
                  valueLabel={line.name}
                  onChange={(item) => selectItem(line.key, item)}
                  placeholder={t("orders.items.selectItem")}
                  searchPlaceholder={t("orders.items.searchItems")}
                  emptyLabel={t("orders.items.noItems")}
                  forSale
                />
              ) : (
                <Input
                  value={line.custom_description}
                  onChange={(e) => update(line.key, { custom_description: e.target.value })}
                  placeholder={t("orders.items.customPlaceholder")}
                />
              )}
            </div>
            <div className="w-20 space-y-1">
              <label className="text-xs text-muted-foreground">{t("orders.items.quantity")}</label>
              <Input
                type="number"
                min={1}
                value={line.quantity}
                aria-label={t("orders.items.quantity")}
                onChange={(e) => update(line.key, { quantity: e.target.value })}
              />
            </div>
            {/* Catalog lines carry a unit (locked to the item's sales unit); custom lines have none. */}
            {line.kind === "catalog" && (
              <div className="w-28 space-y-1">
                <label className="text-xs text-muted-foreground">{t("orders.items.unitType")}</label>
                <Select
                  value={line.unit_type}
                  aria-label={t("orders.items.unitType")}
                  disabled={line.inventory_item_id !== ""}
                  onChange={(e) => update(line.key, { unit_type: e.target.value as OrderItemUnit })}
                >
                  {(line.inventory_item_id !== "" ? [line.unit_type] : SALES_UNITS).map((u) => (
                    <option key={u} value={u}>
                      {t(`orders.items.unitTypes.${u}`)}
                    </option>
                  ))}
                </Select>
              </div>
            )}
            <div className="w-28 space-y-1">
              <label className="text-xs text-muted-foreground">
                {line.kind === "custom" ? t("orders.items.unitPrice") : t("orders.items.priceOverride")}
              </label>
              <Input
                type="number"
                min={0}
                step="0.01"
                value={line.unit_price}
                disabled={line.gift}
                onChange={(e) => update(line.key, { unit_price: e.target.value })}
                placeholder={line.kind === "catalog" ? t("orders.items.auto") : ""}
                aria-label={
                  line.kind === "custom" ? t("orders.items.unitPrice") : t("orders.items.priceOverride")
                }
              />
            </div>
            <label className="flex items-center gap-1.5 pb-2 text-xs text-muted-foreground sm:self-end">
              <Checkbox
                checked={line.gift}
                onChange={(e) => toggleGift(line.key, e.target.checked)}
                aria-label={t("orders.items.gift")}
              />
              {t("orders.items.gift")}
            </label>
            {line.kind === "catalog" &&
              (() => {
                const u = unitMinor(line);
                const qty = Number(line.quantity);
                const total = u != null && Number.isFinite(qty) ? u * qty : null;
                const currency = currencyFor(line);
                return (
                  <div className="min-w-24 space-y-0.5 text-right text-xs sm:self-end sm:pb-1.5">
                    <div className="text-muted-foreground">
                      {u != null ? `${moneyObject({ minor: u, currency })} ${t("orders.items.each")}` : "—"}
                    </div>
                    <div className="text-sm font-medium tabular-nums text-foreground">
                      {total != null ? moneyObject({ minor: total, currency }) : "—"}
                    </div>
                  </div>
                );
              })()}
            <Button
              type="button"
              variant="ghost"
              size="icon"
              aria-label={t("orders.items.remove")}
              onClick={() => remove(line.key)}
            >
              <X className="size-4" />
            </Button>
          </div>
        </div>
      ))}

      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => onChange([...lines, blankCatalogLine()])}
        >
          <Package className="size-4" />
          {t("orders.items.addItem")}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => onChange([...lines, blankCustomLine()])}
        >
          <Pencil className="size-4" />
          {t("orders.items.addCustom")}
        </Button>
        {lines.length === 0 && (
          <span className="flex items-center gap-1 text-xs text-muted-foreground">
            <Plus className="size-3" />
            {t("orders.items.addHint")}
          </span>
        )}
      </div>
    </div>
  );
}
