"use client";

import * as React from "react";

import { useAuth } from "@/lib/auth/context";
import {
  useAdjustStock,
  useSetMovementReconciliation,
  useStockAnalytics,
  useStockMovements,
} from "@/hooks/use-inventory";
import { useFormatters } from "@/lib/format";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/i18n/context";
import {
  MANUAL_STOCK_MOVEMENTS,
  STOCK_PERIODS,
  type InventoryItem,
  type StockMovementType,
  type StockPeriod,
} from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { Sparkline } from "@/components/dashboard/charts";
import { VintageCoverageWidget } from "@/components/inventory/vintage-coverage";

/** Bar/dot colour per exit channel (movement type) for the breakdown graph. */
const CHANNEL_DOT: Record<string, string> = {
  sales: "bg-emerald-500",
  production: "bg-blue-500",
  manual: "bg-zinc-400",
};

/** Pill colour per movement type in the history table. */
const TYPE_VARIANT: Record<StockMovementType, "success" | "info" | "warning" | "purple" | "secondary"> = {
  MANUAL_IN: "success",
  PRODUCTION_IN: "purple",
  ORDER_DEDUCT: "info",
  PRODUCTION_OUT: "purple",
  MANUAL_OUT: "warning",
  ADJUSTMENT: "secondary",
};

export function StockTab({ item, canManage }: { item: InventoryItem; canManage: boolean }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject, number } = useFormatters();
  const canFinancials = can("financials.view");

  const [period, setPeriod] = React.useState<StockPeriod>("30d");
  const analyticsQ = useStockAnalytics(item.id, period);
  const a = analyticsQ.data;

  const periodTabs = STOCK_PERIODS.map((p) => ({ value: p, label: t(`inventory.stock.period.${p}`) }));

  // Derived figures the prototype shows (computed from the analytics already loaded).
  const cur =
    a?.current.cost_per_bottle?.currency ?? a?.current.selling_per_bottle?.currency ?? "EUR";
  const stockB = a?.current.stock_bottles ?? 0;
  const costM = a?.current.cost_per_bottle?.minor ?? null;
  const sellM = a?.current.selling_per_bottle?.minor ?? null;
  const meanM = a?.realized.mean_price?.minor ?? null;
  const productionValue = costM != null ? { minor: costM * stockB, currency: cur } : null;
  const listMarginPct =
    sellM != null && sellM > 0 && costM != null ? ((sellM - costM) / sellM) * 100 : null;
  const listProfit = sellM != null && costM != null ? { minor: sellM - costM, currency: cur } : null;
  const potentialProfit =
    meanM != null && costM != null ? { minor: (meanM - costM) * stockB, currency: cur } : null;
  const totalExited = (a?.channels ?? []).reduce((s, c) => s + c.bottles, 0);
  const perBottle = (m: Parameters<typeof moneyObject>[0]) => `${moneyObject(m)}${t("inventory.stock.perBottle")}`;
  const dash = "—";

  return (
    <div className="space-y-4">
      {/* Current stock */}
      <Card>
        <CardContent className="space-y-4 pt-6">
          <div>
            <p className="text-sm text-muted-foreground">{t("inventory.stock.currentTitle")}</p>
            {/* Single inline row, mirroring the prototype: value · unit · (cases) · Min. */}
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
              <span className="text-4xl font-bold tabular-nums">
                {a ? number(a.current.stock_bottles) : "…"}
              </span>
              <span className="text-xl font-medium text-muted-foreground">{item.unit}</span>
              {a && a.current.bottles_per_case > 1 && (
                <span className="text-base font-semibold">
                  {t("inventory.stock.cases", {
                    count: number(Math.floor(a.current.stock_bottles / a.current.bottles_per_case)),
                  })}
                </span>
              )}
              {a?.current.min_stock_bottles != null && (
                <span className="ml-4 text-base font-medium text-muted-foreground">
                  {t("inventory.stock.min", { count: number(a.current.min_stock_bottles) })}
                </span>
              )}
            </div>
          </div>

          {canFinancials && (
            <>
              {/* Cost basis */}
              <div className="border-t border-border pt-4">
                <p className="mb-2 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                  {t("inventory.stock.costBasis")}
                </p>
                <div className="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3">
                  <Stat
                    label={t("inventory.stock.costPerBottle")}
                    value={a?.current.cost_per_bottle ? perBottle(a.current.cost_per_bottle) : dash}
                  />
                  <Stat
                    label={t("inventory.stock.productionValue")}
                    value={productionValue ? moneyObject(productionValue) : dash}
                  />
                </div>
              </div>

              {/* Selling — list vs realized */}
              <div className="border-t border-border pt-4">
                <p className="mb-2 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                  {t("inventory.stock.selling")}
                </p>
                <div className="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3">
                  <Stat
                    label={t("inventory.stock.marginList")}
                    value={
                      listMarginPct != null && listProfit
                        ? `${number(Number(listMarginPct.toFixed(1)))}% · ${perBottle(listProfit)}`
                        : dash
                    }
                    tone={listProfit ? (listProfit.minor >= 0 ? "pos" : "neg") : undefined}
                  />
                  <Stat
                    label={t("inventory.stock.margin12m")}
                    value={
                      a?.realized.margin_percent != null
                        ? `${number(Number(a.realized.margin_percent))}% · ${perBottle(a.realized.margin_amount)}`
                        : dash
                    }
                    tone={
                      a?.realized.margin_percent != null
                        ? Number(a.realized.margin_percent) >= 0
                          ? "pos"
                          : "neg"
                        : undefined
                    }
                  />
                  <Stat
                    label={t("inventory.stock.meanPrice12m")}
                    value={a?.realized.mean_price ? perBottle(a.realized.mean_price) : dash}
                  />
                  <Stat
                    label={t("inventory.stock.meanRebate")}
                    value={
                      a?.realized.rebate_percent != null
                        ? `${number(Number(a.realized.rebate_percent))}% · ${perBottle(a.realized.rebate_amount)}`
                        : dash
                    }
                    tone="warn"
                  />
                  <Stat
                    label={t("inventory.stock.salesValue")}
                    value={a ? moneyObject(a.realized.sales_value) : dash}
                  />
                  <Stat
                    label={t("inventory.stock.potentialProfit")}
                    value={potentialProfit ? moneyObject(potentialProfit) : dash}
                  />
                </div>
              </div>
            </>
          )}
        </CardContent>
      </Card>

      {/* Period selector */}
      <Tabs tabs={periodTabs} value={period} onChange={(v) => setPeriod(v as StockPeriod)} />

      {/* Warehouse exits */}
      <Card>
        <CardContent className="space-y-4 pt-6">
          <div className="flex items-start justify-between gap-3">
            <div>
              <h3 className="text-sm font-semibold">{t("inventory.stock.exitsTitle")}</h3>
              <p className="text-xs text-muted-foreground">{t("inventory.stock.exitsSubtitle")}</p>
            </div>
            {a && a.exits.spark.some((v) => v > 0) && <Sparkline data={a.exits.spark} />}
          </div>
          <p className="text-3xl font-semibold tabular-nums">
            {a ? number(a.exits.bottles_exited) : "…"}
            <span className="ml-1 text-base font-normal text-muted-foreground">
              {t("inventory.stock.bottlesExited")}
            </span>
            {a && a.exits.movements_count > 0 && (
              <span className="ml-2 text-sm font-normal text-muted-foreground">
                · {t("inventory.stock.movements", { count: number(a.exits.movements_count) })}
              </span>
            )}
          </p>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            {canFinancials && (
              <>
                <Detail label={t("inventory.stock.costOfExits")}>
                  {a?.exits.cost_of_exits ? moneyObject(a.exits.cost_of_exits) : dash}
                </Detail>
                <Detail label={t("inventory.stock.revenueRealized")}>
                  {a?.exits.revenue_realized ? moneyObject(a.exits.revenue_realized) : dash}
                </Detail>
                <Detail label={t("inventory.stock.meanMargin")}>
                  {a?.exits.mean_margin_percent != null
                    ? `${number(Number(a.exits.mean_margin_percent))}%`
                    : dash}
                </Detail>
              </>
            )}
            <Detail label={t("inventory.stock.velocity")}>
              {a && a.exits.bottles_exited > 0
                ? t("inventory.stock.velocityValue", { count: a.exits.velocity_per_day })
                : dash}
            </Detail>
            <Detail label={t("inventory.stock.daysLeft")}>
              {a?.exits.days_of_stock_left != null ? number(a.exits.days_of_stock_left) : dash}
            </Detail>
          </div>
          {canFinancials && a?.exits.internal && (
            <p className="text-xs text-muted-foreground">
              {t("inventory.stock.internalNote", {
                bottles: number(a.exits.internal.bottles),
                cost: moneyObject(a.exits.internal.cost),
                revenue: moneyObject(a.exits.internal.revenue),
              })}
            </p>
          )}
        </CardContent>
      </Card>

      {/* Exit by channel */}
      <Card>
        <CardContent className="space-y-3 pt-6">
          <div>
            <h3 className="text-sm font-semibold">{t("inventory.stock.channelsTitle")}</h3>
            <p className="text-xs text-muted-foreground">{t("inventory.stock.channelsSubtitle")}</p>
          </div>
          {!a || a.channels.length === 0 ? (
            <p className="py-2 text-sm text-muted-foreground">{t("inventory.stock.noExits")}</p>
          ) : (
            <div className="space-y-3">
              {a.channels.map((c) => {
                const share = totalExited > 0 ? Math.round((c.bottles / totalExited) * 100) : 0;
                return (
                  <div key={c.channel} className="space-y-1">
                    <div className="flex items-center justify-between gap-2 text-sm">
                      <span className="flex items-center gap-2">
                        <span className={cn("size-2.5 rounded-full", CHANNEL_DOT[c.channel] ?? CHANNEL_DOT.manual)} />
                        {t(`inventory.stock.channel.${c.channel}`)}
                      </span>
                      <span className="flex items-baseline gap-2 tabular-nums">
                        <span className="font-medium">{number(c.bottles)}</span>
                        <span className="text-xs text-muted-foreground">({share}%)</span>
                      </span>
                    </div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                      <div
                        className={cn("h-full rounded-full", CHANNEL_DOT[c.channel] ?? CHANNEL_DOT.manual)}
                        style={{ width: `${share}%` }}
                      />
                    </div>
                  </div>
                );
              })}
              <div className="flex items-center justify-between border-t border-border pt-2 text-sm font-semibold">
                <span>{t("inventory.stock.totalExited")}</span>
                <span className="tabular-nums">{number(totalExited)}</span>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Vintage transition (multi-vintage wines only) — under exit by channel */}
      {a?.vintage_coverage && <VintageCoverageWidget data={a.vintage_coverage} />}

      {canManage && <QuickStockEntry item={item} />}

      <MovementHistory itemId={item.id} canManage={canManage} />
    </div>
  );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-0.5">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="font-medium tabular-nums">{children}</p>
    </div>
  );
}

/** Compact label/value stat for the Cost basis / Selling grids (mirrors the prototype). */
function Stat({
  label,
  value,
  tone,
}: {
  label: string;
  value: React.ReactNode;
  tone?: "pos" | "neg" | "warn";
}) {
  const color =
    tone === "pos"
      ? "text-success"
      : tone === "neg"
        ? "text-destructive"
        : tone === "warn"
          ? "text-amber-600 dark:text-amber-400"
          : "";
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className={cn("text-sm font-semibold tabular-nums", color)}>{value}</p>
    </div>
  );
}

function QuickStockEntry({ item }: { item: InventoryItem }) {
  const { t } = useTranslation();
  const adjust = useAdjustStock();
  const [moveType, setMoveType] = React.useState<StockMovementType>("MANUAL_IN");
  const [qty, setQty] = React.useState("");
  const [note, setNote] = React.useState("");
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
        input: {
          type: moveType,
          quantity: signed,
          note: note.trim() || null,
          is_reconciliation: reconcile,
        },
      });
      setQty("");
      setNote("");
      setReconcile(false);
    } catch {
      setError(t("inventory.stock.errorGeneric"));
    }
  }

  return (
    <Card>
      <CardContent className="space-y-3 pt-6">
        <h3 className="text-sm font-semibold">{t("inventory.stock.quickEntry")}</h3>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr_2fr_auto] sm:items-end">
          <div className="space-y-1">
            <Label htmlFor="qse-type">{t("inventory.stock.typeLabel")}</Label>
            <Select id="qse-type" value={moveType} onChange={(e) => setMoveType(e.target.value as StockMovementType)}>
              {MANUAL_STOCK_MOVEMENTS.map((m) => (
                <option key={m} value={m}>
                  {t(`inventory.stock.type.${m}`)}
                </option>
              ))}
            </Select>
          </div>
          <div className="space-y-1">
            <Label htmlFor="qse-qty">{t("inventory.stock.quantityLabel")}</Label>
            <Input id="qse-qty" type="number" value={qty} onChange={(e) => setQty(e.target.value)} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="qse-note">{t("inventory.stock.noteLabel")}</Label>
            <Input
              id="qse-note"
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder={t("inventory.stock.notePlaceholder")}
            />
          </div>
          <Button type="button" onClick={apply} disabled={adjust.isPending}>
            {adjust.isPending && <Spinner />}
            {t("inventory.stock.add")}
          </Button>
        </div>
        <label className="flex items-start gap-2 text-sm">
          <Checkbox
            className="mt-0.5"
            checked={reconcile}
            onChange={(e) => setReconcile(e.target.checked)}
          />
          <span>
            {t("inventory.stock.reconciliationLabel")}
            <span className="block text-xs text-muted-foreground">
              {t("inventory.stock.reconciliationHelp")}
            </span>
          </span>
        </label>
        {error && <p className="text-sm text-destructive">{error}</p>}
      </CardContent>
    </Card>
  );
}

// Only operator-entered manual movements can be reclassified after the fact;
// system rows (orders, production) are not re-taggable. Mirrors the prototype.
function isTaggable(type: StockMovementType): boolean {
  return type === "MANUAL_IN" || type === "MANUAL_OUT";
}

function MovementHistory({ itemId, canManage }: { itemId: string; canManage: boolean }) {
  const { t } = useTranslation();
  const { dateTime } = useFormatters();
  const movementsQ = useStockMovements(itemId);
  const reconcile = useSetMovementReconciliation();
  const movements = movementsQ.data ?? [];

  return (
    <Card>
      <CardContent className="space-y-3 pt-6">
        <h3 className="text-sm font-semibold">{t("inventory.stock.historyTitle")}</h3>
        {movementsQ.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : movements.length === 0 ? (
          <p className="py-2 text-center text-sm text-muted-foreground">
            {t("inventory.stock.historyEmpty")}
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-muted-foreground">
                <tr>
                  <th className="py-2 pr-3 font-medium">{t("inventory.movements.colDate")}</th>
                  <th className="py-2 pr-3 font-medium">{t("inventory.movements.colType")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("inventory.movements.colQuantity")}</th>
                  <th className="py-2 pr-3 font-medium">{t("inventory.movements.colNote")}</th>
                  <th className="py-2 pr-3 font-medium">{t("inventory.movements.colReference")}</th>
                  <th className="py-2 font-medium">{t("inventory.movements.colBy")}</th>
                </tr>
              </thead>
              <tbody>
                {movements.map((m) => {
                  const positive = !String(m.quantity).startsWith("-");
                  return (
                    <tr key={m.id} className="border-b border-border last:border-0">
                      <td className="py-2 pr-3 text-muted-foreground whitespace-nowrap">
                        {m.created_at ? dateTime(m.created_at) : "—"}
                      </td>
                      <td className="py-2 pr-3">
                        <span className="flex flex-wrap items-center gap-1.5">
                          <Badge variant={TYPE_VARIANT[m.type] ?? "secondary"}>
                            {t(`inventory.movements.label.${m.type}`)}
                          </Badge>
                          {m.is_reconciliation && (
                            <Badge variant="outline">{t("inventory.stock.reconciliationShort")}</Badge>
                          )}
                        </span>
                      </td>
                      <td className={`py-2 pr-3 text-right tabular-nums ${positive ? "text-success" : "text-destructive"}`}>
                        {positive ? "+" : ""}
                        {m.quantity}
                      </td>
                      <td className="py-2 pr-3 text-muted-foreground">{m.note ?? "—"}</td>
                      <td className="py-2 pr-3 text-muted-foreground">{m.reference ?? "—"}</td>
                      <td className="py-2 text-muted-foreground">
                        <div className="flex flex-col gap-0.5">
                          <span>{m.created_by?.name ?? "—"}</span>
                          {canManage && isTaggable(m.type) && (
                            <button
                              type="button"
                              disabled={reconcile.isPending}
                              onClick={() =>
                                reconcile.mutate({
                                  movementId: m.id,
                                  isReconciliation: !m.is_reconciliation,
                                })
                              }
                              className="text-left text-xs text-muted-foreground underline-offset-2 hover:underline disabled:opacity-50"
                            >
                              {m.is_reconciliation
                                ? t("inventory.stock.unmarkCorrection")
                                : t("inventory.stock.markCorrection")}
                            </button>
                          )}
                        </div>
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
