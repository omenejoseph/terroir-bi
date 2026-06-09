"use client";

import * as React from "react";
import { Check, Pencil, Plus, Trash2, X } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import {
  useAddOrderItems,
  useDeleteOrderItem,
  useUpdateOrderItem,
  useUpdateOrderItemCost,
} from "@/hooks/use-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Order, OrderItem, OrderItemUnit } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";
import {
  blankCatalogLine,
  linesToItems,
  OrderLineItemsEditor,
  type DraftLine,
} from "@/components/orders/order-line-items-editor";

export function OrderItemsSection({ order, canManage }: { order: Order; canManage: boolean }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject } = useFormatters();
  const showCost = can("financials.view");

  const addItems = useAddOrderItems(order.id);
  const [adding, setAdding] = React.useState(false);
  const [lines, setLines] = React.useState<DraftLine[]>(() => [blankCatalogLine()]);
  const [addError, setAddError] = React.useState<string | null>(null);

  async function submitAdd() {
    const items = linesToItems(lines);
    if (items.length === 0) {
      setAddError(t("orders.items.errorGeneric"));
      return;
    }
    setAddError(null);
    await addItems.mutateAsync(items);
    setLines([blankCatalogLine()]);
    setAdding(false);
  }

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        {order.items.length === 0 ? (
          <p className="py-4 text-center text-sm text-muted-foreground">{t("orders.items.empty")}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-muted-foreground">
                <tr>
                  <th className="py-2 pr-3 font-medium">{t("orders.items.item")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("orders.items.quantity")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("orders.items.unitPrice")}</th>
                  {showCost && (
                    <th className="py-2 pr-3 text-right font-medium">{t("orders.items.cost")}</th>
                  )}
                  <th className="py-2 pr-3 text-right font-medium">{t("orders.items.total")}</th>
                  {canManage && <th className="py-2" />}
                </tr>
              </thead>
              <tbody>
                {order.items.map((item) => (
                  <ItemRow
                    key={item.id}
                    orderId={order.id}
                    item={item}
                    canManage={canManage}
                    showCost={showCost}
                    money={moneyObject}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}

        {canManage && (
          <div className="border-t border-border pt-4">
            {!adding ? (
              <Button type="button" variant="outline" size="sm" onClick={() => setAdding(true)}>
                <Plus className="size-4" />
                {t("orders.items.add")}
              </Button>
            ) : (
              <div className="space-y-3">
                <OrderLineItemsEditor lines={lines} onChange={setLines} />
                {addError && <p className="text-sm text-destructive">{addError}</p>}
                <div className="flex justify-end gap-2">
                  <Button type="button" variant="ghost" size="sm" onClick={() => setAdding(false)}>
                    {t("orders.items.remove")}
                  </Button>
                  <Button type="button" size="sm" onClick={submitAdd} disabled={addItems.isPending}>
                    {addItems.isPending && <Spinner />}
                    {t("orders.items.save")}
                  </Button>
                </div>
              </div>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function ItemRow({
  orderId,
  item,
  canManage,
  showCost,
  money,
}: {
  orderId: string;
  item: OrderItem;
  canManage: boolean;
  showCost: boolean;
  money: (m: OrderItem["unit_price"] | null) => string;
}) {
  const { t } = useTranslation();
  const confirm = useConfirm();
  const update = useUpdateOrderItem(orderId);
  const updateCost = useUpdateOrderItemCost(orderId);
  const remove = useDeleteOrderItem(orderId);

  const [editing, setEditing] = React.useState(false);
  const [qty, setQty] = React.useState(String(item.quantity));
  const [unit, setUnit] = React.useState<OrderItemUnit>(item.unit_type);
  const [editingCost, setEditingCost] = React.useState(false);
  const [cost, setCost] = React.useState(item.cost_per_unit ? String(item.cost_per_unit.minor) : "");

  async function saveEdit() {
    await update.mutateAsync({ itemId: item.id, input: { quantity: Number(qty), unit_type: unit } });
    setEditing(false);
  }
  async function saveCost() {
    const trimmed = cost.trim();
    await updateCost.mutateAsync({ itemId: item.id, costPerUnit: trimmed === "" ? null : Number(trimmed) });
    setEditingCost(false);
  }
  async function handleDelete() {
    const ok = await confirm({
      title: t("orders.items.deleteConfirmTitle"),
      description: t("orders.items.deleteConfirmBody"),
      confirmLabel: t("orders.items.remove"),
      tone: "danger",
    });
    if (!ok) return;
    await remove.mutateAsync(item.id);
  }

  return (
    <tr className="border-b border-border last:border-0">
      <td className="py-2 pr-3">
        <span className="font-medium">{item.name}</span>
        {item.sku && <span className="text-muted-foreground"> ({item.sku})</span>}
      </td>
      <td className="py-2 pr-3 text-right tabular-nums">
        {editing ? (
          <div className="flex items-center justify-end gap-1">
            <Input
              type="number"
              min={1}
              value={qty}
              onChange={(e) => setQty(e.target.value)}
              className="h-8 w-16"
              aria-label={t("orders.items.quantity")}
            />
            <Select
              value={unit}
              onChange={(e) => setUnit(e.target.value as OrderItemUnit)}
              className="h-8 w-24"
              aria-label={t("orders.items.unitType")}
            >
              <option value="bottles">{t("orders.items.unitTypes.bottles")}</option>
              <option value="cases">{t("orders.items.unitTypes.cases")}</option>
            </Select>
          </div>
        ) : (
          <>
            {item.quantity} {t(`orders.items.unitTypes.${item.unit_type}`)}
          </>
        )}
      </td>
      <td className="py-2 pr-3 text-right tabular-nums">{money(item.unit_price)}</td>
      {showCost && (
        <td className="py-2 pr-3 text-right tabular-nums">
          {editingCost ? (
            <div className="flex items-center justify-end gap-1">
              <Input
                type="number"
                min={0}
                value={cost}
                onChange={(e) => setCost(e.target.value)}
                className="h-8 w-20"
                aria-label={t("orders.items.cost")}
              />
              <Button type="button" size="icon" variant="ghost" onClick={saveCost} aria-label={t("orders.items.save")}>
                <Check className="size-4" />
              </Button>
            </div>
          ) : (
            <button
              type="button"
              className="hover:underline"
              onClick={() => canManage && setEditingCost(true)}
              disabled={!canManage}
            >
              {item.cost_per_unit ? money(item.cost_per_unit) : "—"}
            </button>
          )}
        </td>
      )}
      <td className="py-2 pr-3 text-right tabular-nums font-medium">{money(item.total)}</td>
      {canManage && (
        <td className="py-2 text-right">
          <div className="flex justify-end gap-1">
            {editing ? (
              <>
                <Button type="button" size="icon" variant="ghost" onClick={saveEdit} aria-label={t("orders.items.save")}>
                  <Check className="size-4" />
                </Button>
                <Button type="button" size="icon" variant="ghost" onClick={() => setEditing(false)} aria-label={t("orders.items.cancel")}>
                  <X className="size-4" />
                </Button>
              </>
            ) : (
              <>
                <Button type="button" size="icon" variant="ghost" onClick={() => setEditing(true)} aria-label={t("orders.items.edit")}>
                  <Pencil className="size-4" />
                </Button>
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  className="text-destructive"
                  onClick={handleDelete}
                  aria-label={t("orders.items.remove")}
                >
                  <Trash2 className="size-4" />
                </Button>
              </>
            )}
          </div>
        </td>
      )}
    </tr>
  );
}
