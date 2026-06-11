"use client";

import * as React from "react";
import {
  CalendarClock,
  Coins,
  Gauge,
  Percent,
  Tag,
  TrendingDown,
  Wallet,
} from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useAdjustStock, useStockAnalytics, useStockMovements } from "@/hooks/use-inventory";
import { useFormatters } from "@/lib/format";
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
import { StatCard } from "@/components/dashboard/stat-card";

export function StockTab({ item, canManage }: { item: InventoryItem; canManage: boolean }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject, number } = useFormatters();
  const canFinancials = can("financials.view");

  const [period, setPeriod] = React.useState<StockPeriod>("30d");
  const analyticsQ = useStockAnalytics(item.id, period);
  const a = analyticsQ.data;

  const periodTabs = STOCK_PERIODS.map((p) => ({ value: p, label: t(`inventory.stock.period.${p}`) }));
  const perBottle = (m: Parameters<typeof moneyObject>[0]) => `${moneyObject(m)}${t("inventory.stock.perBottle")}`;
  const dash = "—";

  return (
    <div className="space-y-4">
      {/* Current stock */}
      <Card>
        <CardContent className="pt-6">
          <div className="grid gap-4 sm:grid-cols-[auto_1fr]">
            <div>
              <p className="text-sm text-muted-foreground">{t("inventory.stock.currentTitle")}</p>
              <p className="text-3xl font-semibold tabular-nums">
                {a ? number(a.current.stock_bottles) : "…"}
                <span className="ml-1 text-base font-normal text-muted-foreground">{item.unit}</span>
              </p>
              {a && a.current.bottles_per_case > 1 && (
                <p className="text-xs text-muted-foreground">
                  {t("inventory.stock.cases", {
                    count: number(Math.floor(a.current.stock_bottles / a.current.bottles_per_case)),
                  })}
                </p>
              )}
              {a?.current.min_stock_bottles != null && (
                <p className="mt-1 text-xs text-muted-foreground">
                  {t("inventory.stock.min", { count: number(a.current.min_stock_bottles) })}
                </p>
              )}
            </div>
            {canFinancials && (
              <div className="grid grid-cols-2 gap-3 self-center text-sm">
                <Detail label={t("inventory.stock.costBasis")}>
                  {a?.current.cost_per_bottle ? perBottle(a.current.cost_per_bottle) : dash}
                </Detail>
                <Detail label={t("inventory.stock.selling")}>
                  {a?.current.selling_per_bottle ? perBottle(a.current.selling_per_bottle) : dash}
                </Detail>
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Realized metrics (12m) */}
      {canFinancials && (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            label={t("inventory.stock.margin12m")}
            value={
              a?.realized.margin_percent != null
                ? `${number(Number(a.realized.margin_percent))}% · ${perBottle(a.realized.margin_amount)}`
                : dash
            }
            icon={Percent}
            accent="bg-emerald-500/10 text-emerald-500"
          />
          <StatCard
            label={t("inventory.stock.meanPrice12m")}
            value={a?.realized.mean_price ? perBottle(a.realized.mean_price) : dash}
            icon={Tag}
            accent="bg-sky-500/10 text-sky-500"
            delayMs={50}
          />
          <StatCard
            label={t("inventory.stock.meanRebate")}
            value={
              a?.realized.rebate_percent != null
                ? `${number(Number(a.realized.rebate_percent))}% · ${perBottle(a.realized.rebate_amount)}`
                : dash
            }
            icon={TrendingDown}
            accent="bg-amber-500/10 text-amber-500"
            delayMs={100}
          />
          <StatCard
            label={t("inventory.stock.salesValue")}
            value={a ? moneyObject(a.realized.sales_value) : dash}
            icon={Wallet}
            accent="bg-violet-500/10 text-violet-500"
            delayMs={150}
          />
        </div>
      )}

      {/* Period selector */}
      <Tabs tabs={periodTabs} value={period} onChange={(v) => setPeriod(v as StockPeriod)} />

      {/* Warehouse exits */}
      <Card>
        <CardContent className="space-y-4 pt-6">
          <div>
            <h3 className="text-sm font-semibold">{t("inventory.stock.exitsTitle")}</h3>
            <p className="text-xs text-muted-foreground">{t("inventory.stock.exitsSubtitle")}</p>
          </div>
          <p className="text-3xl font-semibold tabular-nums">
            {a ? number(a.exits.bottles_exited) : "…"}
            <span className="ml-1 text-base font-normal text-muted-foreground">
              {t("inventory.stock.bottlesExited")}
            </span>
          </p>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
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
            <ul className="divide-y divide-border">
              {a.channels.map((c) => (
                <li key={c.channel} className="flex items-center justify-between py-2 text-sm">
                  <span>{t(`inventory.stock.channel.${c.channel}`)}</span>
                  <span className="tabular-nums">{number(c.bottles)}</span>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      {canManage && <QuickStockEntry item={item} />}

      <MovementHistory itemId={item.id} />
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

function MovementHistory({ itemId }: { itemId: string }) {
  const { t } = useTranslation();
  const { dateTime } = useFormatters();
  const movementsQ = useStockMovements(itemId);
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
                  <th className="py-2 pr-3 font-medium">{t("inventory.movements.colType")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("inventory.movements.colQuantity")}</th>
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
                      <td className={`py-2 pr-3 text-right tabular-nums ${positive ? "text-success" : "text-destructive"}`}>
                        {positive ? "+" : ""}
                        {m.quantity}
                      </td>
                      <td className="py-2 pr-3 text-muted-foreground">{m.note ?? m.reference ?? "—"}</td>
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
