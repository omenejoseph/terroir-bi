"use client";

import * as React from "react";
import { Check, Pencil, Plus, Trash2, X } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import {
  useAddOrderItems,
  useDeleteOrderItem,
  useUpdateOrderItem,
  useUpdateOrderItemCost,
  useUpdateShipping,
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
import { ItemThumb } from "@/components/ui/item-thumb";
import { useConfirm } from "@/components/ui/confirm";
import {
  blankCatalogLine,
  linesToItems,
  OrderLineItemsEditor,
  type DraftLine,
} from "@/components/orders/order-line-items-editor";

/** A gift line — priced at zero; shown as "Gratis" and excluded from cost/profit. */
const isGratis = (item: OrderItem) => item.unit_price.minor === 0;

/** Line cost = snapshotted per-unit cost × quantity; null when cost is unknown. */
function lineCost(item: OrderItem): OrderItem["total"] | null {
  if (!item.cost_per_unit) return null;
  return { ...item.cost_per_unit, minor: item.cost_per_unit.minor * item.quantity };
}

/** Group items by inventory group (e.g. "Wine", "Water"); ungrouped key is null. */
function groupOrderItems(items: OrderItem[]): [string | null, OrderItem[]][] {
  const map = new Map<string | null, OrderItem[]>();
  for (const item of items) {
    const g = item.group ?? null;
    if (!map.has(g)) map.set(g, []);
    map.get(g)!.push(item);
  }
  return [...map.entries()];
}

export function OrderItemsSection({ order, canManage }: { order: Order; canManage: boolean }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject } = useFormatters();
  const showCost = can("financials.view");

  const currency = order.total_amount.currency;
  const groups = groupOrderItems(order.items);
  // Footer totals: revenue = Σ line totals; cost/profit exclude gratis lines.
  const revenueMinor = order.items.reduce((s, i) => s + i.total.minor, 0);
  const costMinor = order.items.reduce(
    (s, i) => (isGratis(i) ? s : s + (lineCost(i)?.minor ?? 0)),
    0,
  );
  const profitMinor = order.items.reduce(
    (s, i) => (isGratis(i) || !i.profit ? s : s + i.profit.minor),
    0,
  );
  // Columns: item, qty, unit price, [cost], total, [profit], [actions].
  const colCount = 3 + (showCost ? 2 : 0) + (canManage ? 1 : 0);

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
                    {showCost && (
                      <th className="py-2 pr-3 text-right font-medium">{t("orders.items.profit")}</th>
                    )}
                    {canManage && <th className="py-2" />}
                  </tr>
                </thead>
                <tbody>
                  {groups.map(([group, items]) => (
                    <React.Fragment key={group ?? "__ungrouped"}>
                      <tr>
                        <td
                          colSpan={colCount}
                          className="pt-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"
                        >
                          {group ?? t("orders.items.ungrouped")}
                        </td>
                      </tr>
                      {items.map((item) => (
                        <ItemRow
                          key={item.id}
                          orderId={order.id}
                          item={item}
                          canManage={canManage}
                          showCost={showCost}
                          money={moneyObject}
                        />
                      ))}
                    </React.Fragment>
                  ))}
                </tbody>
                <tfoot className="border-t border-border">
                  <tr>
                    <td colSpan={3} className="pt-3 text-right font-medium">
                      {t("orders.items.totalExclVat")}
                    </td>
                    {showCost && (
                      <td className="pt-3 pr-3 text-right tabular-nums text-muted-foreground">
                        {moneyObject({ minor: costMinor, currency })}
                      </td>
                    )}
                    <td className="pt-3 pr-3 text-right text-base font-bold tabular-nums">
                      {moneyObject({ minor: revenueMinor, currency })}
                    </td>
                    {showCost && (
                      <td className="pt-3 pr-3 text-right font-bold tabular-nums text-success">
                        {moneyObject({ minor: profitMinor, currency })}
                      </td>
                    )}
                    {canManage && <td />}
                  </tr>
                  <tr>
                    <td
                      colSpan={showCost ? 4 : 3}
                      className="pt-1.5 text-right text-xs text-muted-foreground"
                    >
                      {t("orders.items.logistics")}
                    </td>
                    <td className="pt-1.5 pr-3 text-right">
                      <LogisticsCell order={order} canManage={canManage} money={moneyObject} />
                    </td>
                    {showCost && <td />}
                    {canManage && <td />}
                  </tr>
                </tfoot>
              </table>
            </div>

            {/* Mobile: each item as a stacked label/value card — no sideways scroll. */}
            <div className="space-y-3 sm:hidden">
              {groups.map(([group, items]) => (
                <div key={group ?? "__ungrouped"} className="space-y-3">
                  <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {group ?? t("orders.items.ungrouped")}
                  </p>
                  <ul className="space-y-3">
                    {items.map((item) => (
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
                </div>
              ))}

              {/* Totals + logistics summary. */}
              <div className="space-y-1.5 border-t border-border pt-3 text-sm">
                <div className="flex items-center justify-between">
                  <span className="font-medium">{t("orders.items.totalExclVat")}</span>
                  <span className="text-base font-bold tabular-nums">
                    {moneyObject({ minor: revenueMinor, currency })}
                  </span>
                </div>
                {showCost && (
                  <div className="flex items-center justify-between text-muted-foreground">
                    <span>{t("orders.items.cost")}</span>
                    <span className="tabular-nums">{moneyObject({ minor: costMinor, currency })}</span>
                  </div>
                )}
                {showCost && (
                  <div className="flex items-center justify-between">
                    <span className="text-muted-foreground">{t("orders.items.profit")}</span>
                    <span className="font-bold tabular-nums text-success">
                      {moneyObject({ minor: profitMinor, currency })}
                    </span>
                  </div>
                )}
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">{t("orders.items.logistics")}</span>
                  <LogisticsCell order={order} canManage={canManage} money={moneyObject} />
                </div>
              </div>
            </div>
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
        <div className="flex items-center gap-3">
          <ItemThumb url={item.image_url} alt={item.name} />
          <div className="min-w-0">
            <span className="font-medium">{item.name}</span>
            {item.unit_size ? (
              <span className="ml-1 text-xs font-normal text-muted-foreground">({item.unit_size})</span>
            ) : null}
            {item.sku && <span className="text-muted-foreground"> ({item.sku})</span>}
          </div>
        </div>
      </td>
      <td className="py-2 pr-3 text-right tabular-nums">
        {row.editing ? (
          <QtyEditor item={item} qty={row.qty} setQty={row.setQty} unit={row.unit} setUnit={row.setUnit} />
        ) : (
          <>
            {item.quantity} {t(`orders.items.unitTypes.${item.unit_type}`)}
            {item.unit_type === "cases" && item.bottles_per_case ? (
              <span className="ml-1 text-xs text-muted-foreground">
                ({t("orders.unit.bottles", { count: item.quantity * item.bottles_per_case })})
              </span>
            ) : null}
          </>
        )}
      </td>
      <td className="py-2 pr-3 text-right tabular-nums">
        {isGratis(item) ? (
          <span className="font-semibold text-success">{t("orders.items.gratis")}</span>
        ) : (
          money(item.unit_price)
        )}
      </td>
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
              {isGratis(item) || !item.cost_per_unit ? (
                <span className="text-muted-foreground">—</span>
              ) : (
                money(item.cost_per_unit)
              )}
            </button>
          )}
        </td>
      )}
      <td className="py-2 pr-3 text-right tabular-nums font-medium">
        {isGratis(item) ? (
          <span className="font-semibold text-success">{t("orders.items.gratis")}</span>
        ) : (
          money(item.total)
        )}
      </td>
      {showCost && (
        <td className="py-2 pr-3 text-right tabular-nums font-medium">
          {isGratis(item) || !item.profit ? (
            <span className="text-muted-foreground">—</span>
          ) : (
            <span className={item.profit.minor >= 0 ? "text-success" : "text-destructive"}>
              {money(item.profit)}
            </span>
          )}
        </td>
      )}
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
        <div className="flex min-w-0 items-start gap-3">
          <ItemThumb url={item.image_url} alt={item.name} />
          <div className="min-w-0">
            <span className="font-medium">{item.name}</span>
            {item.unit_size ? (
              <span className="ml-1 text-xs font-normal text-muted-foreground">({item.unit_size})</span>
            ) : null}
            {item.sku && <span className="text-muted-foreground"> ({item.sku})</span>}
          </div>
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
                {item.unit_type === "cases" && item.bottles_per_case ? (
                  <span className="ml-1 text-xs text-muted-foreground">
                    ({t("orders.unit.bottles", { count: item.quantity * item.bottles_per_case })})
                  </span>
                ) : null}
              </>
            )}
          </dd>
        </div>

        <div className="space-y-0.5">
          <dt className="text-xs uppercase tracking-wide text-muted-foreground">
            {t("orders.items.unitPrice")}
          </dt>
          <dd className="tabular-nums">
            {isGratis(item) ? (
              <span className="font-semibold text-success">{t("orders.items.gratis")}</span>
            ) : (
              money(item.unit_price)
            )}
          </dd>
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
                  {isGratis(item) || !item.cost_per_unit ? (
                    <span className="text-muted-foreground">—</span>
                  ) : (
                    money(item.cost_per_unit)
                  )}
                </button>
              )}
            </dd>
          </div>
        )}

        <div className="space-y-0.5">
          <dt className="text-xs uppercase tracking-wide text-muted-foreground">
            {t("orders.items.total")}
          </dt>
          <dd className="font-medium tabular-nums">
            {isGratis(item) ? (
              <span className="font-semibold text-success">{t("orders.items.gratis")}</span>
            ) : (
              money(item.total)
            )}
          </dd>
        </div>

        {showCost && (
          <div className="space-y-0.5">
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">
              {t("orders.items.profit")}
            </dt>
            <dd className="font-medium tabular-nums">
              {isGratis(item) || !item.profit ? (
                <span className="text-muted-foreground">—</span>
              ) : (
                <span className={item.profit.minor >= 0 ? "text-success" : "text-destructive"}>
                  {money(item.profit)}
                </span>
              )}
            </dd>
          </div>
        )}
      </dl>
    </li>
  );
}

/** Inline logistics (freight) cost in the items footer — add/edit for managers. */
function LogisticsCell({
  order,
  canManage,
  money,
}: {
  order: Order;
  canManage: boolean;
  money: Money;
}) {
  const { t } = useTranslation();
  const update = useUpdateShipping(order.id);
  const [editing, setEditing] = React.useState(false);
  const [draft, setDraft] = React.useState("");
  const ship = order.shipping_cost;

  async function save() {
    const trimmed = draft.trim();
    await update.mutateAsync({ shipping_cost: trimmed === "" ? null : (majorToMinor(trimmed) ?? 0) });
    setEditing(false);
  }

  if (editing) {
    return (
      <div className="flex items-center justify-end gap-1">
        <Input
          type="number"
          min={0}
          step="0.01"
          autoFocus
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          className="h-8 w-24"
          aria-label={t("orders.items.logistics")}
        />
        <Button type="button" size="icon" variant="ghost" onClick={save} aria-label={t("orders.items.save")}>
          <Check className="size-4" />
        </Button>
        <Button
          type="button"
          size="icon"
          variant="ghost"
          onClick={() => setEditing(false)}
          aria-label={t("orders.items.cancel")}
        >
          <X className="size-4" />
        </Button>
      </div>
    );
  }

  if (ship && ship.minor > 0) {
    if (!canManage) return <span className="tabular-nums text-destructive">−{money(ship)}</span>;
    return (
      <button
        type="button"
        className="tabular-nums text-destructive hover:underline"
        onClick={() => {
          setDraft(minorToMajorInput(ship.minor));
          setEditing(true);
        }}
      >
        −{money(ship)}
      </button>
    );
  }

  if (!canManage) return <span className="text-muted-foreground">—</span>;
  return (
    <button
      type="button"
      className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:underline"
      onClick={() => {
        setDraft("");
        setEditing(true);
      }}
    >
      <Plus className="size-3" />
      {t("orders.items.addLogistics")}
    </button>
  );
}
