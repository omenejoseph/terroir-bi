"use client";

import * as React from "react";
import { Package, Pencil, Plus, X } from "lucide-react";

import { useTranslation } from "@/i18n/context";
import { SALES_UNITS, type InventoryItem, type OrderItemInput, type OrderItemUnit } from "@/lib/types";
import { Button } from "@/components/ui/button";
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
    const price = line.unit_price.trim();
    if (line.kind === "catalog") {
      if (!line.inventory_item_id) continue;
      out.push({
        inventory_item_id: line.inventory_item_id,
        quantity,
        unit_type: line.unit_type,
        ...(price === "" ? {} : { unit_price: Number(price) }),
      });
    } else {
      const description = line.custom_description.trim();
      if (!description || price === "") continue;
      out.push({
        inventory_item_id: null,
        quantity,
        unit_type: line.unit_type,
        unit_price: Number(price),
        custom_description: description,
      });
    }
  }
  return out;
}

export function OrderLineItemsEditor({
  lines,
  onChange,
}: {
  lines: DraftLine[];
  onChange: (lines: DraftLine[]) => void;
}) {
  const { t } = useTranslation();

  function update(key: string, patch: Partial<DraftLine>) {
    onChange(lines.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  }
  function remove(key: string) {
    onChange(lines.filter((l) => l.key !== key));
  }
  function selectItem(key: string, item: InventoryItem) {
    // A catalog item can only be ordered in its sales unit (strict).
    update(key, {
      inventory_item_id: item.id,
      name: item.name,
      sku: item.sku,
      unit_type: (item.sales_unit as OrderItemUnit | null) ?? "bottles",
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
                onChange={(e) => update(line.key, { quantity: e.target.value })}
              />
            </div>
            <div className="w-28 space-y-1">
              <label className="text-xs text-muted-foreground">{t("orders.items.unitType")}</label>
              <Select
                value={line.unit_type}
                aria-label={t("orders.items.unitType")}
                // Catalog items are locked to their sales unit; custom lines are free.
                disabled={line.kind === "catalog" && line.inventory_item_id !== ""}
                onChange={(e) => update(line.key, { unit_type: e.target.value as OrderItemUnit })}
              >
                {(line.kind === "catalog" && line.inventory_item_id !== ""
                  ? [line.unit_type]
                  : SALES_UNITS
                ).map((u) => (
                  <option key={u} value={u}>
                    {t(`orders.items.unitTypes.${u}`)}
                  </option>
                ))}
              </Select>
            </div>
            <div className="w-28 space-y-1">
              <label className="text-xs text-muted-foreground">
                {line.kind === "custom" ? t("orders.items.unitPrice") : t("orders.items.priceOverride")}
              </label>
              <Input
                type="number"
                min={0}
                value={line.unit_price}
                onChange={(e) => update(line.key, { unit_price: e.target.value })}
                placeholder={line.kind === "catalog" ? t("orders.items.auto") : ""}
                aria-label={
                  line.kind === "custom" ? t("orders.items.unitPrice") : t("orders.items.priceOverride")
                }
              />
            </div>
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
