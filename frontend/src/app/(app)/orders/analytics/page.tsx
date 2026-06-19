"use client";

import * as React from "react";
import Link from "next/link";
import {
  Area,
  Bar,
  BarChart,
  CartesianGrid,
  ComposedChart,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { AlertTriangle, ArrowDown, ArrowLeft, ArrowUp, Euro, Minus, Percent, Receipt, TrendingUp, Wallet } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useOrderAnalytics } from "@/hooks/use-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { cn } from "@/lib/utils";
import type { DashboardPeriod } from "@/lib/dashboard-period";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { Table } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { ChartCard } from "@/components/dashboard/charts";
import { PeriodSelector } from "@/components/dashboard/period-selector";

/** Margin % → tone, mirroring the prototype (healthy / warning / bleeding). */
function marginTone(pct: string | null): string {
  if (pct == null) return "text-muted-foreground";
  const v = Number(pct);
  if (v >= 35) return "text-emerald-600 dark:text-emerald-400";
  if (v >= 20) return "text-amber-600 dark:text-amber-400";
  return "text-red-600 dark:text-red-400";
}

export default function OrderAnalyticsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject, number, date } = useFormatters();
  const [period, setPeriod] = React.useState<DashboardPeriod>("mtd");
  const [customRange, setCustomRange] = React.useState<{ from?: string; to?: string }>({});
  const [custPage, setCustPage] = React.useState(1);
  const [prodPage, setProdPage] = React.useState(1);

  const allowed = can("financials.view");

  const handlePeriodChange = (next: DashboardPeriod, from?: string, to?: string) => {
    setPeriod(next);
    setCustomRange({ from, to });
    setCustPage(1);
    setProdPage(1);
  };

  const { data, isLoading } = useOrderAnalytics(
    { period, from: customRange.from, to: customRange.to, customers_page: custPage, products_page: prodPage },
    allowed,
  );

  const mg = (pct: string | null) => <span className={cn("font-medium", marginTone(pct))}>{pct != null ? `${pct}%` : "—"}</span>;

  if (!allowed) {
    return (
      <Card>
        <CardContent className="py-12 text-center text-sm text-muted-foreground">
          {t("orders.analytics.forbidden")}
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      <Link
        href="/orders"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("orders.back")}
      </Link>

      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("orders.analytics.title")}</h1>
          <p className="text-sm text-muted-foreground">{t("orders.analytics.subtitle")}</p>
        </div>
        <PeriodSelector
          period={period}
          customFrom={customRange.from}
          customTo={customRange.to}
          onChange={handlePeriodChange}
          className="sm:items-end sm:text-right"
        />
      </header>

      {isLoading || !data ? (
        <div className="flex justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : (
        <>
          {/* KPI strip with period-over-period deltas */}
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <Kpi label={t("orders.analytics.revenue")} icon={Euro} accent="bg-emerald-500/10 text-emerald-600"
              value={moneyObject(data.revenue)} curr={data.revenue.minor} prev={data.previous.revenue.minor} positiveIsGood />
            <Kpi label={t("orders.analytics.cogs")} icon={Wallet} accent="bg-amber-500/10 text-amber-600"
              value={moneyObject(data.cogs)} curr={data.cogs.minor} prev={data.previous.cogs.minor} positiveIsGood={false} />
            <Kpi label={t("orders.analytics.grossProfit")} icon={TrendingUp} accent="bg-primary/10 text-primary"
              value={moneyObject(data.gross_profit)} curr={data.gross_profit.minor} prev={data.previous.gross_profit.minor} positiveIsGood />
            <Kpi label={t("orders.analytics.margin")} icon={Percent} accent="bg-blue-500/10 text-blue-600"
              value={`${data.margin_percent}%`} curr={Number(data.margin_percent)} prev={Number(data.previous.margin_percent)} positiveIsGood />
            <Kpi label={t("orders.analytics.orderCount")} icon={Receipt} accent="bg-violet-500/10 text-violet-600"
              value={number(data.order_count)} curr={data.order_count} prev={data.previous.order_count} positiveIsGood />
          </div>

          {/* Secondary operational stats */}
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Mini label={t("orders.analytics.bottlesSold")} value={number(data.bottles_sold)} hint={t("orders.analytics.bottleEq")} />
            <Mini label={t("orders.analytics.avgGpPerOrder")} value={moneyObject(data.avg_gp_per_order)} hint={t("orders.analytics.perOrder")} />
            <Mini label={t("orders.analytics.avgMarginPct")} value={data.avg_margin_pct_per_order != null ? `${data.avg_margin_pct_per_order}%` : "—"} hint={t("orders.analytics.meanPerOrder")} />
            <Mini label={t("orders.analytics.linesNoCost")} value={number(data.items_with_unknown_cost)}
              hint={data.items_with_unknown_cost > 0 ? t("orders.analytics.mayUnderstate") : t("orders.analytics.coverageClean")}
              amber={data.items_with_unknown_cost > 0} />
          </div>

          {/* Lines without cost — amber, just above the chart it distorts */}
          {data.lines_without_cost.length > 0 && (
            <SimpleTable
              title={t("orders.analytics.linesWithoutCost")}
              icon={<AlertTriangle className="size-4 text-amber-600 dark:text-amber-400" />}
              className="border-amber-300/70 bg-amber-50/50 dark:border-amber-900/50 dark:bg-amber-950/20"
              leftCols={4}
              subtitle={t("orders.analytics.linesWithoutCostSub", { revenue: moneyObject(data.revenue_without_cost) })}
              empty={t("orders.analytics.empty")}
              cols={[t("orders.analytics.colOrder"), t("orders.analytics.colCustomer"), t("orders.analytics.colShipped"), t("orders.analytics.colProduct"), t("orders.analytics.colQty"), t("orders.analytics.colRevenue")]}
              rows={data.lines_without_cost.map((l) => [
                <Link key="o" href={`/orders/${l.order_id}`} className="font-medium text-amber-700 hover:underline dark:text-amber-300">{l.order_number}</Link>,
                l.customer_name ?? "—",
                date(l.date),
                l.product_name ?? "—",
                `${number(l.quantity)} ${l.unit_type}`,
                moneyObject(l.line_revenue),
              ])} />
          )}

          {/* Time series */}
          <ChartCard title={t("orders.analytics.timeseriesTitle")} subtitle={t(`orders.analytics.granularity.${data.granularity}`)}>
            {data.series.length === 0 ? (
              <p className="py-8 text-center text-sm text-muted-foreground">{t("orders.analytics.noData")}</p>
            ) : (
              <ResponsiveContainer width="100%" height={260}>
                <ComposedChart
                  data={data.series.map((s) => ({
                    label: s.label,
                    revenue: s.revenue.minor,
                    cogs: s.cogs.minor,
                    margin: s.margin_percent != null ? Number(s.margin_percent) : null,
                  }))}
                  margin={{ top: 8, right: 8, left: 8, bottom: 0 }}
                >
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
                  <XAxis dataKey="label" tick={{ fontSize: 11 }} stroke="var(--color-muted-foreground)" />
                  <YAxis yAxisId="m" tick={{ fontSize: 11 }} stroke="var(--color-muted-foreground)" width={44}
                    tickFormatter={(v: number) => (v >= 100000 ? `${Math.round(v / 100000)}k` : `${Math.round(v / 100)}`)} />
                  <YAxis yAxisId="p" orientation="right" tick={{ fontSize: 11 }} stroke="var(--color-muted-foreground)" width={36} domain={[0, 100]} unit="%" />
                  <Tooltip
                    formatter={(v, n) => (n === t("orders.analytics.margin") ? `${v ?? "—"}%` : v == null ? "—" : moneyObject({ minor: Number(v), currency: data.revenue.currency }))}
                    contentStyle={{ fontSize: 12, borderRadius: 8, border: "1px solid var(--color-border)" }}
                  />
                  <Area yAxisId="m" type="monotone" dataKey="revenue" name={t("orders.analytics.revenue")} stroke="#e11d48" fill="#e11d4833" strokeWidth={2} />
                  <Area yAxisId="m" type="monotone" dataKey="cogs" name={t("orders.analytics.cogs")} stroke="#64748b" fill="#64748b33" strokeWidth={1.5} />
                  <Line yAxisId="p" type="monotone" dataKey="margin" name={t("orders.analytics.margin")} stroke="#10b981" strokeWidth={2} dot={false} connectNulls />
                </ComposedChart>
              </ResponsiveContainer>
            )}
          </ChartCard>

          {/* Rankings (paginated), full prototype columns */}
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <RankCard title={t("orders.analytics.topCustomers")} meta={data.top_customers.meta} page={custPage} setPage={setCustPage} t={t}
              cols={[t("orders.analytics.colCustomer"), t("orders.analytics.colRevenue"), t("orders.analytics.colCogs"), t("orders.analytics.colGp"), t("orders.analytics.colMargin")]}
              empty={t("orders.analytics.empty")}
              rows={data.top_customers.data.map((c) => [
                <span key="n">{c.company_name ?? "—"}<span className="block text-[10px] text-muted-foreground">{t("orders.analytics.nOrders", { count: c.orders_count })}</span></span>,
                moneyObject(c.revenue),
                moneyObject(c.cogs),
                moneyObject(c.gross_profit),
                mg(c.margin_percent),
              ])} />
            <RankCard title={t("orders.analytics.topProducts")} meta={data.top_products.meta} page={prodPage} setPage={setProdPage} t={t}
              cols={[t("orders.analytics.colProduct"), t("orders.analytics.colQuantity"), t("orders.analytics.colRevenue"), t("orders.analytics.colGp"), t("orders.analytics.colMargin")]}
              empty={t("orders.analytics.empty")}
              rows={data.top_products.data.map((p) => [
                <span key="n">{p.name ?? "—"}{p.vintage ? <span className="block text-[10px] text-muted-foreground">{p.vintage}</span> : null}</span>,
                `${number(p.quantity)} ${t("orders.analytics.btl")}`,
                moneyObject(p.revenue),
                moneyObject(p.gross_profit),
                mg(p.margin_percent),
              ])} />
          </div>

          {/* Channel profitability */}
          <SimpleTable title={t("orders.analytics.channels")} empty={t("orders.analytics.empty")}
            cols={[t("orders.analytics.colChannel"), t("orders.analytics.colOrders"), t("orders.analytics.colQuantity"), t("orders.analytics.colRevenue"), t("orders.analytics.colGp"), t("orders.analytics.colMargin")]}
            rows={data.channels.map((c) => [
              <span key="c">{t(`customers.type.${c.key.toUpperCase()}`)}<span className="block text-[10px] text-muted-foreground">{t("orders.analytics.ofRevenue", { pct: c.revenue_share_pct })}</span></span>,
              number(c.orders_count),
              `${number(c.bottles)} ${t("orders.analytics.btl")}`,
              moneyObject(c.revenue),
              moneyObject(c.gross_profit),
              mg(c.margin_percent),
            ])} />

          {/* Price realization */}
          <ChartCard title={t("orders.analytics.priceRealization")} subtitle={t("orders.analytics.priceRealizationSub")}>
            <div className="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
              <Mini label={t("orders.analytics.atList")} value={moneyObject(data.price_realization.totals.list_revenue)} />
              <Mini label={t("orders.analytics.realized")} value={moneyObject(data.price_realization.totals.realized_revenue)} />
              <Mini label={t("orders.analytics.leakage")} value={moneyObject(data.price_realization.totals.leakage)}
                hint={data.price_realization.totals.realization_pct != null ? t("orders.analytics.ofList", { pct: data.price_realization.totals.realization_pct }) : undefined} amber />
            </div>
            {data.price_realization.rows.length === 0 ? (
              <p className="py-4 text-center text-sm text-muted-foreground">{t("orders.analytics.noPriced")}</p>
            ) : (
              <Table>
                <thead className="border-b border-border text-left text-muted-foreground">
                  <tr>
                    <th className="py-2 pr-3 font-medium">{t("orders.analytics.colProduct")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("orders.analytics.colList")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("orders.analytics.colRealized")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("orders.analytics.colRealizationPct")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("orders.analytics.colLeakage")}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.price_realization.rows.map((r) => (
                    <tr key={r.inventory_item_id} className="border-b border-border last:border-0">
                      <td className="py-2 pr-3">{r.name ?? "—"}<span className="block text-[10px] text-muted-foreground">{number(r.bottles)} {t("orders.analytics.btl")}</span></td>
                      <td className="py-2 pr-3 text-right tabular-nums">{moneyObject(r.list_price)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{moneyObject(r.realized_per_bottle)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{r.realization_pct != null ? `${r.realization_pct}%` : "—"}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">{moneyObject(r.leakage)}</td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </ChartCard>

          {/* Low-margin alerts */}
          <SimpleTable title={t("orders.analytics.lowMargin")} subtitle={t("orders.analytics.lowMarginSub")} empty={t("orders.analytics.lowMarginClear")} leftCols={3}
            cols={[t("orders.analytics.colOrder"), t("orders.analytics.colCustomer"), t("orders.analytics.colShipped"), t("orders.analytics.colRevenue"), t("orders.analytics.colGp"), t("orders.analytics.colMargin")]}
            rows={data.low_margin_orders.map((o) => [
              <Link key="o" href={`/orders/${o.order_id}`} className="hover:underline">{o.order_number}</Link>,
              o.customer_name ?? "—",
              date(o.date),
              moneyObject(o.revenue),
              moneyObject(o.gross_profit),
              <span key="m" className="font-medium text-red-600 dark:text-red-400">{o.margin_percent}%</span>,
            ])} />

          {/* Margin distribution */}
          <ChartCard title={t("orders.analytics.marginDistribution")}>
            <ResponsiveContainer width="100%" height={200}>
              <BarChart data={data.histogram.map((h) => ({ label: h.label, orders: h.orders }))} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
                <XAxis dataKey="label" tick={{ fontSize: 11 }} stroke="var(--color-muted-foreground)" />
                <YAxis tick={{ fontSize: 11 }} stroke="var(--color-muted-foreground)" width={28} allowDecimals={false} />
                <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8, border: "1px solid var(--color-border)" }} />
                <Bar dataKey="orders" name={t("orders.analytics.colOrders")} fill="#9f1239" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </ChartCard>
        </>
      )}
    </div>
  );
}

function Kpi({
  label,
  icon: Icon,
  accent,
  value,
  curr,
  prev,
  positiveIsGood,
}: {
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  accent: string;
  value: string;
  curr: number;
  prev: number;
  positiveIsGood: boolean;
}) {
  const deltaPct = prev > 0 ? Math.round(((curr - prev) / prev) * 1000) / 10 : null;
  const up = deltaPct != null && deltaPct >= 0;
  const good = deltaPct == null ? null : positiveIsGood ? up : !up;
  return (
    <Card className="border-border/60">
      <CardContent className="p-4">
        <div className="flex items-start justify-between gap-2">
          <p className="text-xs text-muted-foreground">{label}</p>
          <span className={cn("flex size-7 shrink-0 items-center justify-center rounded-lg", accent)}>
            <Icon className="size-3.5" />
          </span>
        </div>
        <p className="mt-1.5 text-xl font-semibold tabular-nums">{value}</p>
        <span
          className={cn(
            "mt-1 inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-medium",
            good == null ? "bg-muted text-muted-foreground" : good ? "bg-success/10 text-success" : "bg-destructive/10 text-destructive",
          )}
        >
          {deltaPct == null ? <Minus className="size-3" /> : up ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />}
          {deltaPct == null ? "—" : `${deltaPct > 0 ? "+" : ""}${deltaPct}%`}
        </span>
      </CardContent>
    </Card>
  );
}

function Mini({ label, value, hint, amber }: { label: string; value: string; hint?: string; amber?: boolean }) {
  return (
    <Card className={cn("border-border/60", amber && "ring-1 ring-amber-500/40")}>
      <CardContent className="p-4">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className="mt-1 text-lg font-semibold tabular-nums">{value}</p>
        {hint && <p className={cn("text-[11px]", amber ? "text-amber-600 dark:text-amber-400" : "text-muted-foreground")}>{hint}</p>}
      </CardContent>
    </Card>
  );
}

function RankCard({
  title,
  cols,
  rows,
  empty,
  meta,
  page,
  setPage,
  t,
}: {
  title: string;
  cols: string[];
  rows: React.ReactNode[][];
  empty: string;
  meta: { current_page: number; last_page: number; total: number };
  page: number;
  setPage: (p: number) => void;
  t: (k: string, v?: Record<string, string | number>) => string;
}) {
  return (
    <Card>
      <CardContent className="space-y-3 pt-6">
        <h2 className="text-sm font-semibold">{title}</h2>
        {rows.length === 0 ? (
          <p className="py-4 text-center text-sm text-muted-foreground">{empty}</p>
        ) : (
          <>
            <DataTable cols={cols} rows={rows} leftCols={1} />
            {meta.last_page > 1 && (
              <div className="flex items-center justify-between text-xs text-muted-foreground">
                <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t("orders.prev")}</Button>
                <span>{t("orders.pageOf", { page: meta.current_page, total: meta.last_page })}</span>
                <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>{t("orders.next")}</Button>
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}

function SimpleTable({
  title,
  subtitle,
  cols,
  rows,
  empty,
  leftCols = 1,
  className,
  icon,
}: {
  title: string;
  subtitle?: string;
  cols: string[];
  rows: React.ReactNode[][];
  empty: string;
  leftCols?: number;
  className?: string;
  icon?: React.ReactNode;
}) {
  return (
    <Card className={className}>
      <CardContent className="space-y-3 pt-6">
        <div>
          <h2 className="flex items-center gap-2 text-sm font-semibold">
            {icon}
            {title}
          </h2>
          {subtitle && <p className="text-xs text-muted-foreground">{subtitle}</p>}
        </div>
        {rows.length === 0 ? (
          <p className="py-4 text-center text-sm text-muted-foreground">{empty}</p>
        ) : (
          <DataTable cols={cols} rows={rows} leftCols={leftCols} />
        )}
      </CardContent>
    </Card>
  );
}

/** Shared table: the first `leftCols` columns are left-aligned (labels), the rest right-aligned numerics. */
function DataTable({ cols, rows, leftCols }: { cols: string[]; rows: React.ReactNode[][]; leftCols: number }) {
  const cls = (i: number) => (i < leftCols ? "py-2 pr-3" : "py-2 pr-3 text-right tabular-nums");
  return (
    <Table>
      <thead className="border-b border-border text-left text-muted-foreground">
        <tr>
          {cols.map((c, i) => (
            <th key={i} className={cn("font-medium", cls(i))}>{c}</th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((row, ri) => (
          <tr key={ri} className="border-b border-border last:border-0">
            {row.map((cell, ci) => (
              <td key={ci} className={cls(ci)}>{cell}</td>
            ))}
          </tr>
        ))}
      </tbody>
    </Table>
  );
}
