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
import { majorToMinor, minorToMajorInput } from "@/lib/money";
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
          <>
            {/* Desktop / tablet: a real table. */}
            <div className="hidden overflow-x-auto sm:block">
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

            {/* Mobile: each item as a stacked label/value card — no sideways scroll. */}
            <ul className="space-y-3 sm:hidden">
              {order.items.map((item) => (
                <ItemCard
                  key={item.id}
                  orderId={order.id}
                  item={item}
                  canManage={canManage}
                  showCost={showCost}
                  money={moneyObject}
                />
              ))}
            </ul>
          </>
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
                <OrderLineItemsEditor
                  lines={lines}
                  onChange={setLines}
                  customerId={order.customer?.id}
                />
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

type Money = (m: OrderItem["unit_price"] | null) => string;

/**
 * Edit state + mutations for one line item, shared by the desktop row and the
 * mobile card so the two layouts never drift. Qty/unit and cost edit
 * independently, mirroring the table's two inline-edit affordances.
 */
function useOrderItemRow(orderId: string, item: OrderItem) {
  const { t } = useTranslation();
  const confirm = useConfirm();
  const update = useUpdateOrderItem(orderId);
  const updateCost = useUpdateOrderItemCost(orderId);
  const remove = useDeleteOrderItem(orderId);

  const [editing, setEditing] = React.useState(false);
  const [qty, setQty] = React.useState(String(item.quantity));
  const [unit, setUnit] = React.useState<OrderItemUnit>(item.unit_type);
  const [editingCost, setEditingCost] = React.useState(false);
  // Cost per unit is shown/edited in major units (€); converted to minor on save.
  const [cost, setCost] = React.useState(minorToMajorInput(item.cost_per_unit?.minor));

  async function saveEdit() {
    await update.mutateAsync({ itemId: item.id, input: { quantity: Number(qty), unit_type: unit } });
    setEditing(false);
  }
  async function saveCost() {
    await updateCost.mutateAsync({ itemId: item.id, costPerUnit: majorToMinor(cost) });
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

  return {
    editing,
    setEditing,
    qty,
    setQty,
    unit,
    setUnit,
    editingCost,
    setEditingCost,
    cost,
    setCost,
    saveEdit,
    saveCost,
    handleDelete,
  };
}

/** The qty + unit editor, shared by both layouts. */
function QtyEditor({
  item,
  qty,
  setQty,
  unit,
  setUnit,
}: {
  item: OrderItem;
  qty: string;
  setQty: (v: string) => void;
  unit: OrderItemUnit;
  setUnit: (v: OrderItemUnit) => void;
}) {
  const { t } = useTranslation();
  return (
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
        // Catalog lines are locked to the item's sales unit.
        disabled={item.inventory_item_id !== null}
      >
        {item.inventory_item_id !== null ? (
          <option value={unit}>{t(`orders.items.unitTypes.${unit}`)}</option>
        ) : (
          <>
            <option value="bottles">{t("orders.items.unitTypes.bottles")}</option>
            <option value="cases">{t("orders.items.unitTypes.cases")}</option>
          </>
        )}
      </Select>
    </div>
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
  money: Money;
}) {
  const { t } = useTranslation();
  const row = useOrderItemRow(orderId, item);

  return (
    <tr className="border-b border-border last:border-0">
      <td className="py-2 pr-3">
        <span className="font-medium">{item.name}</span>
        {item.sku && <span className="text-muted-foreground"> ({item.sku})</span>}
      </td>
      <td className="py-2 pr-3 text-right tabular-nums">
        {row.editing ? (
          <QtyEditor item={item} qty={row.qty} setQty={row.setQty} unit={row.unit} setUnit={row.setUnit} />
        ) : (
          <>
            {item.quantity} {t(`orders.items.unitTypes.${item.unit_type}`)}
          </>
        )}
      </td>
      <td className="py-2 pr-3 text-right tabular-nums">{money(item.unit_price)}</td>
      {showCost && (
        <td className="py-2 pr-3 text-right tabular-nums">
          {row.editingCost ? (
            <div className="flex items-center justify-end gap-1">
              <Input
                type="number"
                min={0}
                step="0.01"
                value={row.cost}
                onChange={(e) => row.setCost(e.target.value)}
                className="h-8 w-20"
                aria-label={t("orders.items.cost")}
              />
              <Button type="button" size="icon" variant="ghost" onClick={row.saveCost} aria-label={t("orders.items.save")}>
                <Check className="size-4" />
              </Button>
            </div>
          ) : (
            <button
              type="button"
              className="hover:underline"
              onClick={() => canManage && row.setEditingCost(true)}
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
            {row.editing ? (
              <>
                <Button type="button" size="icon" variant="ghost" onClick={row.saveEdit} aria-label={t("orders.items.save")}>
                  <Check className="size-4" />
                </Button>
                <Button type="button" size="icon" variant="ghost" onClick={() => row.setEditing(false)} aria-label={t("orders.items.cancel")}>
                  <X className="size-4" />
                </Button>
              </>
            ) : (
              <>
                <Button type="button" size="icon" variant="ghost" onClick={() => row.setEditing(true)} aria-label={t("orders.items.edit")}>
                  <Pencil className="size-4" />
                </Button>
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  className="text-destructive"
                  onClick={row.handleDelete}
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

/** Mobile equivalent of ItemRow — a stacked label/value card. */
function ItemCard({
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
  money: Money;
}) {
  const { t } = useTranslation();
  const row = useOrderItemRow(orderId, item);

  return (
    <li className="rounded-lg border border-border p-3">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <span className="font-medium">{item.name}</span>
          {item.sku && <span className="text-muted-foreground"> ({item.sku})</span>}
        </div>
        {canManage && (
          <div className="flex shrink-0 gap-1">
            {row.editing ? (
              <>
                <Button type="button" size="icon" variant="ghost" onClick={row.saveEdit} aria-label={t("orders.items.save")}>
                  <Check className="size-4" />
                </Button>
                <Button type="button" size="icon" variant="ghost" onClick={() => row.setEditing(false)} aria-label={t("orders.items.cancel")}>
                  <X className="size-4" />
                </Button>
              </>
            ) : (
              <>
                <Button type="button" size="icon" variant="ghost" onClick={() => row.setEditing(true)} aria-label={t("orders.items.edit")}>
                  <Pencil className="size-4" />
                </Button>
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  className="text-destructive"
                  onClick={row.handleDelete}
                  aria-label={t("orders.items.remove")}
                >
                  <Trash2 className="size-4" />
                </Button>
              </>
            )}
          </div>
        )}
      </div>

      <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
        <div className="space-y-0.5">
          <dt className="text-xs uppercase tracking-wide text-muted-foreground">
            {t("orders.items.quantity")}
          </dt>
          <dd className="tabular-nums">
            {row.editing ? (
              <QtyEditor item={item} qty={row.qty} setQty={row.setQty} unit={row.unit} setUnit={row.setUnit} />
            ) : (
              <>
                {item.quantity} {t(`orders.items.unitTypes.${item.unit_type}`)}
              </>
            )}
          </dd>
        </div>

        <div className="space-y-0.5">
          <dt className="text-xs uppercase tracking-wide text-muted-foreground">
            {t("orders.items.unitPrice")}
          </dt>
          <dd className="tabular-nums">{money(item.unit_price)}</dd>
        </div>

        {showCost && (
          <div className="space-y-0.5">
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">
              {t("orders.items.cost")}
            </dt>
            <dd className="tabular-nums">
              {row.editingCost ? (
                <div className="flex items-center gap-1">
                  <Input
                    type="number"
                    min={0}
                    step="0.01"
                    value={row.cost}
                    onChange={(e) => row.setCost(e.target.value)}
                    className="h-8 w-20"
                    aria-label={t("orders.items.cost")}
                  />
                  <Button type="button" size="icon" variant="ghost" onClick={row.saveCost} aria-label={t("orders.items.save")}>
                    <Check className="size-4" />
                  </Button>
                </div>
              ) : (
                <button
                  type="button"
                  className="hover:underline"
                  onClick={() => canManage && row.setEditingCost(true)}
                  disabled={!canManage}
                >
                  {item.cost_per_unit ? money(item.cost_per_unit) : "—"}
                </button>
              )}
            </dd>
          </div>
        )}

        <div className="space-y-0.5">
          <dt className="text-xs uppercase tracking-wide text-muted-foreground">
            {t("orders.items.total")}
          </dt>
          <dd className="font-medium tabular-nums">{money(item.total)}</dd>
        </div>
      </dl>
    </li>
  );
}
