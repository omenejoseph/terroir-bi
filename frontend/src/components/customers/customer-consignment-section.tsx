"use client";

import * as React from "react";
import { Plus } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import {
  useCustomerConsignment,
  useCustomerConsignmentReturn,
  useCustomerConsignmentSale,
  usePlaceConsignment,
} from "@/hooks/use-customer-consignment";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { InventoryItem, OrderItemUnit } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { InventoryItemPicker } from "@/components/inventory/inventory-item-picker";

export function CustomerConsignmentSection({ customerId }: { customerId: string }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { date } = useFormatters();
  const canManage = can("orders.manage");

  const summaryQ = useCustomerConsignment(customerId);
  const place = usePlaceConsignment(customerId);
  const sale = useCustomerConsignmentSale(customerId);
  const recordReturn = useCustomerConsignmentReturn(customerId);

  const [placing, setPlacing] = React.useState(false);
  const [placeItem, setPlaceItem] = React.useState<{ id: string; name: string } | null>(null);
  const [placeQty, setPlaceQty] = React.useState("");
  const [placeUnit, setPlaceUnit] = React.useState<OrderItemUnit>("cases");

  const [mode, setMode] = React.useState<"sale" | "return" | null>(null);
  const [qty, setQty] = React.useState<Record<string, string>>({});

  const summary = summaryQ.data;

  async function submitPlace() {
    if (!placeItem || Number(placeQty) <= 0) return;
    await place.mutateAsync({
      items: [{ inventory_item_id: placeItem.id, quantity: Number(placeQty), unit_type: placeUnit }],
    });
    setPlacing(false);
    setPlaceItem(null);
    setPlaceQty("");
  }

  async function submitMovement() {
    const items = (summary?.products ?? [])
      .map((p) => ({ inventory_item_id: p.inventory_item_id, quantity: Number(qty[p.inventory_item_id] ?? "0") }))
      .filter((i) => i.quantity > 0);
    if (items.length === 0) return;
    if (mode === "sale") await sale.mutateAsync({ items });
    else await recordReturn.mutateAsync({ items: items.map(({ inventory_item_id, quantity }) => ({ inventory_item_id, quantity })) });
    setMode(null);
    setQty({});
  }

  if (summaryQ.isLoading) {
    return (
      <Card>
        <CardContent className="flex justify-center py-8">
          <Spinner className="size-5 text-muted-foreground" />
        </CardContent>
      </Card>
    );
  }

  const products = summary?.products ?? [];
  const placements = summary?.placements ?? [];

  return (
    <Card>
      <CardContent className="space-y-5 pt-6">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold">{t("orders.consignment.title")}</h2>
          {canManage && (
            <div className="flex gap-2">
              <Button type="button" variant="outline" size="sm" onClick={() => setPlacing(true)}>
                <Plus className="size-3.5" />
                {t("orders.consignment.place")}
              </Button>
              {products.length > 0 && (
                <>
                  <Button type="button" variant="outline" size="sm" onClick={() => setMode("sale")}>
                    {t("orders.consignment.recordSale")}
                  </Button>
                  <Button type="button" variant="outline" size="sm" onClick={() => setMode("return")}>
                    {t("orders.consignment.recordReturn")}
                  </Button>
                </>
              )}
            </div>
          )}
        </div>

        {products.length === 0 ? (
          <p className="py-4 text-center text-sm text-muted-foreground">{t("orders.consignment.empty")}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-muted-foreground">
                <tr>
                  <th className="py-2 pr-3 font-medium">{t("orders.consignment.colItem")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("orders.consignment.colPlaced")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("orders.consignment.colSold")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("orders.consignment.colReturned")}</th>
                  <th className="py-2 text-right font-medium">{t("orders.consignment.colRemaining")}</th>
                </tr>
              </thead>
              <tbody>
                {products.map((p) => (
                  <tr key={p.inventory_item_id} className="border-b border-border last:border-0">
                    <td className="py-2 pr-3">{p.name}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{p.placed}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{p.sold}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{p.returned}</td>
                    <td className="py-2 text-right tabular-nums">{p.remaining}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {placements.length > 0 && (
          <ul className="space-y-1 text-xs text-muted-foreground">
            {placements.map((pl) => (
              <li key={pl.order_id}>
                {pl.order_number} · {date(pl.placed_at)}
                {pl.closed_at ? ` · ${t("orders.consignment.closed", { date: date(pl.closed_at) })}` : ""}
              </li>
            ))}
          </ul>
        )}

        {placing && (
          <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-3">
            <p className="text-sm font-medium">{t("orders.consignment.place")}</p>
            <InventoryItemPicker
              valueLabel={placeItem?.name}
              onChange={(item: InventoryItem) => setPlaceItem({ id: item.id, name: item.name })}
              placeholder={t("orders.items.selectItem")}
              searchPlaceholder={t("orders.items.searchItems")}
              emptyLabel={t("orders.items.noItems")}
            />
            <div className="flex items-end gap-2">
              <div className="w-24 space-y-1">
                <Label htmlFor="place-qty">{t("orders.consignment.quantity")}</Label>
                <Input id="place-qty" type="number" min={1} value={placeQty} onChange={(e) => setPlaceQty(e.target.value)} />
              </div>
              <div className="w-28 space-y-1">
                <Label htmlFor="place-unit">{t("orders.items.unitType")}</Label>
                <Select id="place-unit" value={placeUnit} onChange={(e) => setPlaceUnit(e.target.value as OrderItemUnit)}>
                  <option value="bottles">{t("orders.items.unitTypes.bottles")}</option>
                  <option value="cases">{t("orders.items.unitTypes.cases")}</option>
                </Select>
              </div>
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="ghost" size="sm" onClick={() => setPlacing(false)}>
                {t("orders.form.cancel")}
              </Button>
              <Button type="button" size="sm" onClick={submitPlace} disabled={place.isPending || !placeItem}>
                {place.isPending && <Spinner />}
                {t("orders.consignment.place")}
              </Button>
            </div>
          </div>
        )}

        {mode && (
          <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-3">
            <p className="text-sm font-medium">
              {mode === "sale" ? t("orders.consignment.saleTitle") : t("orders.consignment.returnTitle")}
            </p>
            {products.map((p) => (
              <div key={p.inventory_item_id} className="flex items-center justify-between gap-2">
                <span className="text-sm">{p.name}</span>
                <Input
                  type="number"
                  min={0}
                  value={qty[p.inventory_item_id] ?? ""}
                  onChange={(e) => setQty((q) => ({ ...q, [p.inventory_item_id]: e.target.value }))}
                  className="h-8 w-20"
                  aria-label={`${p.name} ${t("orders.consignment.quantity")}`}
                />
              </div>
            ))}
            <div className="flex justify-end gap-2">
              <Button type="button" variant="ghost" size="sm" onClick={() => setMode(null)}>
                {t("orders.form.cancel")}
              </Button>
              <Button type="button" size="sm" onClick={submitMovement} disabled={sale.isPending || recordReturn.isPending}>
                {(sale.isPending || recordReturn.isPending) && <Spinner />}
                {mode === "sale" ? t("orders.consignment.submitSale") : t("orders.consignment.submitReturn")}
              </Button>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
