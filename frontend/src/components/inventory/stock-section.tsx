"use client";

import * as React from "react";

import { useAdjustStock, useStockMovements } from "@/hooks/use-inventory";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { MANUAL_STOCK_MOVEMENTS, type InventoryItem, type StockMovementType } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";

export function StockSection({ item, canManage }: { item: InventoryItem; canManage: boolean }) {
  const { t } = useTranslation();
  const { dateTime } = useFormatters();
  const adjust = useAdjustStock();
  const movementsQ = useStockMovements(item.id);

  const [moveType, setMoveType] = React.useState<StockMovementType>("MANUAL_IN");
  const [qty, setQty] = React.useState("");
  const [reconcile, setReconcile] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  async function apply() {
    setError(null);
    const n = Number(qty.trim());
    if (!Number.isFinite(n) || n === 0) return;
    const signed = moveType === "MANUAL_OUT" ? -Math.abs(n) : moveType === "MANUAL_IN" ? Math.abs(n) : n;
    try {
      await adjust.mutateAsync({
        id: item.id,
        input: { type: moveType, quantity: signed, is_reconciliation: reconcile },
      });
      setQty("");
      setReconcile(false);
    } catch {
      setError(t("inventory.stock.errorGeneric"));
    }
  }

  const movements = movementsQ.data ?? [];

  return (
    <Card>
      <CardContent className="space-y-5 pt-6">
        {/* Current stock */}
        <div className="rounded-lg border border-border bg-muted/40 p-4">
          <p className="text-sm text-muted-foreground">{t("inventory.details.currentStock")}</p>
          <p className="text-2xl font-semibold tabular-nums">
            {item.current_stock} {item.unit}
          </p>
        </div>

        {/* Adjust */}
        {canManage && (
          <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div className="flex-1 space-y-1">
              <Label htmlFor="move-type">{t("inventory.stock.typeLabel")}</Label>
              <Select
                id="move-type"
                value={moveType}
                onChange={(e) => setMoveType(e.target.value as StockMovementType)}
              >
                {MANUAL_STOCK_MOVEMENTS.map((m) => (
                  <option key={m} value={m}>
                    {t(`inventory.stock.type.${m}`)}
                  </option>
                ))}
              </Select>
            </div>
            <div className="flex-1 space-y-1">
              <Label htmlFor="move-qty">{t("inventory.stock.quantityLabel")}</Label>
              <Input
                id="move-qty"
                type="number"
                value={qty}
                onChange={(e) => setQty(e.target.value)}
              />
            </div>
            <Button type="button" onClick={apply} disabled={adjust.isPending}>
              {adjust.isPending && <Spinner />}
              {t("inventory.stock.apply")}
            </Button>
          </div>
        )}
        {canManage && (
          <label className="flex items-center gap-2 text-sm">
            <Checkbox checked={reconcile} onChange={(e) => setReconcile(e.target.checked)} />
            {t("inventory.stock.reconciliation")}
          </label>
        )}
        {error && <p className="text-sm text-destructive">{error}</p>}

        {/* Ledger */}
        {movementsQ.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : movements.length === 0 ? (
          <p className="py-4 text-center text-sm text-muted-foreground">
            {t("inventory.movements.empty")}
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-muted-foreground">
                <tr>
                  <th className="py-2 pr-3 font-medium">{t("inventory.movements.colType")}</th>
                  <th className="py-2 pr-3 text-right font-medium">
                    {t("inventory.movements.colQuantity")}
                  </th>
                  <th className="py-2 pr-3 font-medium">{t("inventory.movements.colReference")}</th>
                  <th className="py-2 font-medium">{t("inventory.movements.colDate")}</th>
                </tr>
              </thead>
              <tbody>
                {movements.map((m) => {
                  const positive = !String(m.quantity).startsWith("-");
                  return (
                    <tr key={m.id} className="border-b border-border last:border-0">
                      <td className="py-2 pr-3">
                        <span className="flex flex-wrap items-center gap-1.5">
                          {t(`inventory.movements.label.${m.type}`)}
                          {m.is_reconciliation && (
                            <Badge variant="outline">{t("inventory.stock.reconciliationShort")}</Badge>
                          )}
                        </span>
                      </td>
                      <td
                        className={`py-2 pr-3 text-right tabular-nums ${
                          positive ? "text-success" : "text-destructive"
                        }`}
                      >
                        {positive ? "+" : ""}
                        {m.quantity}
                      </td>
                      <td className="py-2 pr-3 text-muted-foreground">{m.reference ?? "—"}</td>
                      <td className="py-2 text-muted-foreground">
                        {m.created_at ? dateTime(m.created_at) : "—"}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>
    </Card>
  );
}