"use client";

import * as React from "react";
import { Check, X } from "lucide-react";

import { useBulkUpdateInventoryItems } from "@/hooks/use-inventory";
import { useTranslation } from "@/i18n/context";
import { majorToMinor, minorToMajorInput } from "@/lib/money";
import { cn } from "@/lib/utils";
import type { InventoryBulkEdit, InventoryItem } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";

/** Editable fields tracked per row (strings for inputs, booleans for toggles). */
interface Row {
  name: string;
  min_stock: string;
  default_price: string;
  cost_per_unit: string;
  is_active: boolean;
  is_for_sale: boolean;
}

function toRow(item: InventoryItem): Row {
  return {
    name: item.name,
    min_stock: item.min_stock != null ? String(item.min_stock) : "",
    default_price: minorToMajorInput(item.default_price?.minor),
    cost_per_unit: minorToMajorInput(item.cost_per_unit?.minor),
    is_active: item.is_active,
    is_for_sale: item.is_for_sale,
  };
}

/**
 * Prototype-style bulk-edit grid: every item in the current view becomes an
 * inline-editable row. Changed cells highlight; Save sends only the rows that
 * actually changed. Stock is intentionally not editable here — it's ledger-driven
 * and changed via the Stock tab so the movement history stays accurate.
 */
export function BulkEditInventory({
  items,
  onDone,
}: {
  items: InventoryItem[];
  onDone: () => void;
}) {
  const { t } = useTranslation();
  const bulk = useBulkUpdateInventoryItems();
  const original = React.useMemo(() => {
    const map = new Map<string, Row>();
    items.forEach((i) => map.set(i.id, toRow(i)));
    return map;
  }, [items]);
  const [rows, setRows] = React.useState<Map<string, Row>>(() => new Map(original));
  const [savedMsg, setSavedMsg] = React.useState<string | null>(null);

  const set = (id: string, patch: Partial<Row>) =>
    setRows((prev) => {
      const next = new Map(prev);
      next.set(id, { ...(next.get(id) as Row), ...patch });
      return next;
    });

  const changedField = (id: string, field: keyof Row) => original.get(id)?.[field] !== rows.get(id)?.[field];
  const rowChanged = (id: string) =>
    (["name", "min_stock", "default_price", "cost_per_unit", "is_active", "is_for_sale"] as (keyof Row)[]).some(
      (f) => changedField(id, f),
    );
  const changedCount = items.filter((i) => rowChanged(i.id)).length;

  async function save() {
    const payload: InventoryBulkEdit[] = [];
    for (const item of items) {
      if (!rowChanged(item.id)) continue;
      const r = rows.get(item.id) as Row;
      const edit: InventoryBulkEdit = { id: item.id };
      if (changedField(item.id, "name")) edit.name = r.name.trim();
      if (changedField(item.id, "min_stock")) edit.min_stock = r.min_stock.trim() === "" ? null : Number(r.min_stock);
      if (changedField(item.id, "default_price")) edit.default_price = majorToMinor(r.default_price);
      if (changedField(item.id, "cost_per_unit")) edit.cost_per_unit = majorToMinor(r.cost_per_unit);
      if (changedField(item.id, "is_active")) edit.is_active = r.is_active;
      if (changedField(item.id, "is_for_sale")) edit.is_for_sale = r.is_for_sale;
      payload.push(edit);
    }
    if (payload.length === 0) {
      onDone();
      return;
    }
    const res = await bulk.mutateAsync(payload);
    setSavedMsg(t("inventory.bulkEdit.saved", { count: res.updated }));
    onDone();
  }

  const cellInput = "h-8";

  return (
    <Card>
      <CardContent className="space-y-3 pt-6">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 className="text-sm font-semibold">{t("inventory.bulkEdit.title")}</h2>
            <p className="text-xs text-muted-foreground">
              {changedCount > 0
                ? t("inventory.bulkEdit.modified", { count: changedCount })
                : t("inventory.bulkEdit.hint")}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={onDone} disabled={bulk.isPending}>
              <X className="size-3.5" />
              {t("inventory.bulkEdit.cancel")}
            </Button>
            <Button size="sm" onClick={() => void save()} disabled={bulk.isPending || changedCount === 0}>
              {bulk.isPending ? <Spinner className="size-3.5" /> : <Check className="size-3.5" />}
              {t("inventory.bulkEdit.save")}
            </Button>
          </div>
        </div>
        {savedMsg && <p className="rounded-md bg-success/10 px-3 py-2 text-sm text-success">{savedMsg}</p>}

        <div className="overflow-x-auto">
          <table className="w-full min-w-[820px] text-sm">
            <thead className="border-b border-border text-left text-xs text-muted-foreground">
              <tr>
                <th className="py-2 pr-3 font-medium">{t("inventory.bulkEdit.colName")}</th>
                <th className="py-2 pr-3 font-medium">{t("inventory.bulkEdit.colSku")}</th>
                <th className="py-2 pr-3 text-right font-medium">{t("inventory.bulkEdit.colMinStock")}</th>
                <th className="py-2 pr-3 text-right font-medium">{t("inventory.bulkEdit.colPrice")}</th>
                <th className="py-2 pr-3 text-right font-medium">{t("inventory.bulkEdit.colCost")}</th>
                <th className="py-2 pr-3 text-center font-medium">{t("inventory.bulkEdit.colActive")}</th>
                <th className="py-2 text-center font-medium">{t("inventory.bulkEdit.colForSale")}</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => {
                const r = rows.get(item.id) as Row;
                const hl = (f: keyof Row) => cn(cellInput, changedField(item.id, f) && "border-amber-400 bg-amber-50 dark:bg-amber-950/30");
                return (
                  <tr key={item.id} className="border-b border-border/50">
                    <td className="py-1.5 pr-3">
                      <Input
                        aria-label={t("inventory.bulkEdit.colName")}
                        value={r.name}
                        onChange={(e) => set(item.id, { name: e.target.value })}
                        className={cn(hl("name"), "w-44")}
                      />
                    </td>
                    <td className="py-1.5 pr-3 text-xs text-muted-foreground">{item.sku}</td>
                    <td className="py-1.5 pr-3">
                      <Input
                        type="number"
                        min={0}
                        step="0.01"
                        aria-label={t("inventory.bulkEdit.colMinStock")}
                        value={r.min_stock}
                        onChange={(e) => set(item.id, { min_stock: e.target.value })}
                        className={cn(hl("min_stock"), "w-24 text-right")}
                      />
                    </td>
                    <td className="py-1.5 pr-3">
                      <Input
                        type="number"
                        min={0}
                        step="0.01"
                        aria-label={t("inventory.bulkEdit.colPrice")}
                        value={r.default_price}
                        onChange={(e) => set(item.id, { default_price: e.target.value })}
                        className={cn(hl("default_price"), "w-24 text-right")}
                      />
                    </td>
                    <td className="py-1.5 pr-3">
                      <Input
                        type="number"
                        min={0}
                        step="0.01"
                        aria-label={t("inventory.bulkEdit.colCost")}
                        value={r.cost_per_unit}
                        onChange={(e) => set(item.id, { cost_per_unit: e.target.value })}
                        className={cn(hl("cost_per_unit"), "w-24 text-right")}
                      />
                    </td>
                    <td className="py-1.5 pr-3 text-center">
                      <Checkbox
                        aria-label={t("inventory.bulkEdit.colActive")}
                        checked={r.is_active}
                        onChange={(e) => set(item.id, { is_active: e.target.checked })}
                      />
                    </td>
                    <td className="py-1.5 text-center">
                      <Checkbox
                        aria-label={t("inventory.bulkEdit.colForSale")}
                        checked={r.is_for_sale}
                        onChange={(e) => set(item.id, { is_for_sale: e.target.checked })}
                      />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  );
}
