"use client";

import * as React from "react";
import Link from "next/link";
import {
  Area,
  AreaChart,
  Bar,
  CartesianGrid,
  ComposedChart,
  Legend,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import {
  AlertTriangle,
  ArrowDownToLine,
  ArrowUpFromLine,
  BarChart3,
  Flame,
  Minus,
  Scale,
  TrendingDown,
  TrendingUp,
} from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useCashFlow, useCashFlowAnalysis } from "@/hooks/use-finance";
import { type DashboardPeriod, resolvePeriodWindow, toISODate } from "@/lib/dashboard-period";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { CashFlowAnalysis, CashFlowOutstandingEntry, Money } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { ChartCard } from "@/components/dashboard/charts";
import { CashFlowChart } from "@/components/finance/cash-flow-chart";
import { PeriodSelector } from "@/components/dashboard/period-selector";
import { cn } from "@/lib/utils";

export default function CashFlowPage() {
  const { t } = useTranslation();
  const [period, setPeriod] = React.useState<DashboardPeriod>("ytd");
  const [customRange, setCustomRange] = React.useState<{ from?: string; to?: string }>({});

  const window = resolvePeriodWindow(period, customRange.from, customRange.to);
  const range = {
    from: window.since ? toISODate(window.since) : undefined,
    to: window.until ? toISODate(window.until) : undefined,
  };

  const analysisQ = useCashFlowAnalysis(range);
  const forecastQ = useCashFlow();

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("cashFlow.analysis.title")}</h1>
        <p className="text-sm text-muted-foreground">{t("cashFlow.subtitle")}</p>
      </header>

      <PeriodSelector
        period={period}
        customFrom={customRange.from}
        customTo={customRange.to}
        onChange={(next, from, to) => {
          setPeriod(next);
          setCustomRange({ from, to });
        }}
      />

      {analysisQ.isLoading && (
        <div className="flex items-center justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      )}

      {analysisQ.isError && (
        <Card>
          <CardContent className="py-8 text-center text-sm text-destructive">
            {analysisQ.error instanceof ApiError && analysisQ.error.status === 403
              ? t("cashFlow.errorForbidden")
              : t("cashFlow.errorGeneric")}
          </CardContent>
        </Card>
      )}

      {!analysisQ.isLoading && !analysisQ.isError && analysisQ.data && (
        <Analysis data={analysisQ.data} forecastMonths={forecastQ.data} />
      )}
    </div>
  );
}

function Analysis({
  data,
  forecastMonths,
}: {
  data: CashFlowAnalysis;
  forecastMonths: ReturnType<typeof useCashFlow>["data"];
}) {
  const { t } = useTranslation();
  const { moneyObject, money2, moneyAxis } = useFormatters();
  const eur = (minor: number) => Math.round(minor / 100);

  return (
    <div className="space-y-6">
      {/* Row 1 — actual cash movement. */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard
          label={t("cashFlow.analysis.cards.cashIn")}
          value={moneyObject(data.cash_in.total)}
          valueClass="text-emerald-600"
          icon={ArrowDownToLine}
          sub={t("cashFlow.analysis.received", { count: data.cash_in.count })}
          trend={<TrendPill change={data.cash_in.change} goodUp />}
        />
        <KpiCard
          label={t("cashFlow.analysis.cards.cashOut")}
          value={moneyObject(data.cash_out.total)}
          valueClass="text-destructive"
          icon={ArrowUpFromLine}
          sub={t("cashFlow.analysis.paid", { count: data.cash_out.count })}
          trend={<TrendPill change={data.cash_out.change} goodUp={false} />}
        >
          {data.top_outflow_categories.slice(0, 3).map((c) => (
            <div key={c.category} className="flex items-center justify-between text-[10px] text-muted-foreground">
              <span className="mr-2 truncate">{c.category}</span>
              <span className="shrink-0 font-medium tabular-nums">{moneyObject(c.amount)}</span>
            </div>
          ))}
        </KpiCard>
        <KpiCard
          label={t("cashFlow.analysis.cards.netCashFlow")}
          value={signed(data.net.total, moneyObject)}
          valueClass={data.net.total.minor >= 0 ? "text-emerald-600" : "text-destructive"}
          icon={BarChart3}
          sub={t("cashFlow.analysis.inMinusOut")}
          trend={<TrendPill change={data.net.change} goodUp />}
        />
        <KpiCard
          label={t("cashFlow.analysis.cards.burnRate")}
          value={moneyObject(data.burn_rate_monthly)}
          icon={Flame}
          sub={t("cashFlow.analysis.avgMonthlyCosts")}
        />
      </div>

      {/* Row 2 — outstanding balances + working capital. */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
        <KpiCard
          label={t("cashFlow.analysis.cards.outstandingReceivables")}
          value={moneyObject(data.outstanding_receivables.total)}
          valueClass={data.outstanding_receivables.total.minor > 0 ? "text-amber-600" : undefined}
          icon={TrendingUp}
          sub={t("cashFlow.analysis.owedToUs", { count: data.outstanding_receivables.count })}
        />
        <KpiCard
          label={t("cashFlow.analysis.cards.outstandingPayables")}
          value={moneyObject(data.outstanding_payables.total)}
          valueClass={data.outstanding_payables.total.minor > 0 ? "text-amber-600" : undefined}
          icon={TrendingDown}
          sub={t("cashFlow.analysis.weOwe", { count: data.outstanding_payables.count })}
        />
        <KpiCard
          label={t("cashFlow.analysis.cards.netWorkingCapital")}
          value={signed(data.net_working_capital, moneyObject)}
          valueClass={data.net_working_capital.minor >= 0 ? "text-emerald-600" : "text-destructive"}
          icon={Scale}
          sub={t("cashFlow.analysis.receivablesMinusPayables")}
        />
      </div>

      {/* Cash flow over time (bars + net line). */}
      <ChartCard title={t("cashFlow.analysis.charts.overTime")}>
        {data.over_time.length === 0 ? (
          <Empty label={t("cashFlow.analysis.noData")} />
        ) : (
          <div className="h-[260px] w-full">
            <ResponsiveContainer width="100%" height="100%">
              <ComposedChart
                data={data.over_time.map((r) => ({
                  period: r.period,
                  cashIn: eur(r.cash_in.minor),
                  cashOut: eur(r.cash_out.minor),
                  net: eur(r.net.minor),
                }))}
                margin={{ top: 6, right: 6, left: -6, bottom: 0 }}
              >
                <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
                <XAxis dataKey="period" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} minTickGap={24} />
                <YAxis tick={{ fontSize: 11 }} axisLine={false} tickLine={false} width={48} tickFormatter={(n: number) => moneyAxis(n * 100)} />
                <Tooltip formatter={(v: number) => money2(Number(v) * 100)} />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                <Bar name={t("cashFlow.analysis.series.cashIn")} dataKey="cashIn" fill="#10b981" radius={[3, 3, 0, 0]} />
                <Bar name={t("cashFlow.analysis.series.cashOut")} dataKey="cashOut" fill="#f43f5e" radius={[3, 3, 0, 0]} />
                <Line name={t("cashFlow.analysis.series.net")} type="monotone" dataKey="net" stroke="var(--color-primary)" strokeWidth={2} dot={false} />
              </ComposedChart>
            </ResponsiveContainer>
          </div>
        )}
      </ChartCard>

      {/* Cumulative cash flow. */}
      <ChartCard title={t("cashFlow.analysis.charts.cumulative")}>
        {data.over_time.length === 0 ? (
          <Empty label={t("cashFlow.analysis.noData")} />
        ) : (
          <div className="h-[240px] w-full">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart
                data={data.over_time.map((r) => ({ period: r.period, cumulative: eur(r.cumulative_net.minor) }))}
                margin={{ top: 6, right: 6, left: -6, bottom: 0 }}
              >
                <defs>
                  <linearGradient id="cf-cumulative" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="var(--color-primary)" stopOpacity={0.3} />
                    <stop offset="100%" stopColor="var(--color-primary)" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
                <XAxis dataKey="period" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} minTickGap={24} />
                <YAxis tick={{ fontSize: 11 }} axisLine={false} tickLine={false} width={48} tickFormatter={(n: number) => moneyAxis(n * 100)} />
                <Tooltip formatter={(v: number) => money2(Number(v) * 100)} />
                <Area
                  name={t("cashFlow.analysis.series.cumulative")}
                  type="monotone"
                  dataKey="cumulative"
                  stroke="var(--color-primary)"
                  strokeWidth={2}
                  fill="url(#cf-cumulative)"
                />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        )}
      </ChartCard>

      {/* Forecast — 12-month averages, the projection chart, and the pending pipeline. */}
      {forecastMonths && (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <KpiCard
              label={t("cashFlow.summary.avgRevenue")}
              value={moneyObject(forecastMonths.summary.avg_monthly_revenue)}
              valueClass="text-emerald-600"
              icon={ArrowDownToLine}
              sub={t("cashFlow.summary.twelveMonthAvg")}
            />
            <KpiCard
              label={t("cashFlow.summary.avgCosts")}
              value={moneyObject(forecastMonths.summary.avg_monthly_costs)}
              valueClass="text-destructive"
              icon={ArrowUpFromLine}
              sub={t("cashFlow.summary.twelveMonthAvg")}
            />
            <KpiCard
              label={t("cashFlow.summary.avgNet")}
              value={signed(forecastMonths.summary.avg_monthly_net, moneyObject)}
              valueClass={forecastMonths.summary.avg_monthly_net.minor >= 0 ? "text-emerald-600" : "text-destructive"}
              icon={BarChart3}
              sub={t("cashFlow.summary.revenueMinusCosts")}
            />
            <KpiCard
              label={t("cashFlow.summary.growth")}
              value={`${Number(forecastMonths.summary.revenue_growth_percent) >= 0 ? "+" : ""}${forecastMonths.summary.revenue_growth_percent}%`}
              valueClass={Number(forecastMonths.summary.revenue_growth_percent) >= 0 ? "text-emerald-600" : "text-destructive"}
              icon={Number(forecastMonths.summary.revenue_growth_percent) >= 0 ? TrendingUp : TrendingDown}
              sub={t("cashFlow.summary.threeMonthTrend")}
            />
          </div>

          <ChartCard title={t("cashFlow.analysis.charts.forecast")} subtitle={t("cashFlow.analysis.forecastSub")}>
            <CashFlowChart
              months={[...forecastMonths.historical, ...forecastMonths.forecast]}
              formatValue={(n) => money2(n)}
              formatAxis={(n) => moneyAxis(n)}
            />
          </ChartCard>

          {/* Pending pipeline — expected money in / out and the resulting net. */}
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
            <KpiCard
              label={t("cashFlow.pending.receivable")}
              value={moneyObject(forecastMonths.pending.receivable)}
              valueClass="text-emerald-600"
              icon={ArrowDownToLine}
              sub={t("cashFlow.pending.countSuffix", { count: forecastMonths.pending.receivable_count })}
            />
            <KpiCard
              label={t("cashFlow.pending.payable")}
              value={moneyObject(forecastMonths.pending.payable)}
              valueClass="text-destructive"
              icon={ArrowUpFromLine}
              sub={t("cashFlow.pending.countSuffix", { count: forecastMonths.pending.payable_count })}
            />
            <KpiCard
              label={t("cashFlow.pending.net")}
              value={signed(forecastMonths.pending.net, moneyObject)}
              valueClass={forecastMonths.pending.net.minor >= 0 ? "text-emerald-600" : "text-destructive"}
              icon={Scale}
              sub={t("cashFlow.pending.title")}
            />
          </div>
        </div>
      )}

      {/* Category comparison + payment discipline. */}
      <div className="grid gap-4 lg:grid-cols-2">
        <ChartCard title={t("cashFlow.analysis.charts.categoryComparison")}>
          {data.by_category.length === 0 ? (
            <Empty label={t("cashFlow.analysis.noData")} />
          ) : (
            <CategoryComparison rows={data.by_category} />
          )}
        </ChartCard>
        <ChartCard title={t("cashFlow.analysis.discipline.title")}>
          <PaymentDiscipline discipline={data.payment_discipline} />
        </ChartCard>
      </div>

      {/* Top receivables + payables. */}
      <div className="grid gap-4 lg:grid-cols-2">
        <ChartCard
          title={t("cashFlow.analysis.tables.topReceivables")}
          subtitle={t("cashFlow.analysis.tables.whoOwesUs")}
        >
          <OutstandingTable rows={data.top_receivables} emptyLabel={t("cashFlow.analysis.tables.noReceivables")} />
        </ChartCard>
        <ChartCard
          title={t("cashFlow.analysis.tables.topPayables")}
          subtitle={t("cashFlow.analysis.tables.whoWeOwe")}
        >
          <OutstandingTable rows={data.top_payables} emptyLabel={t("cashFlow.analysis.tables.noPayables")} />
        </ChartCard>
      </div>

      {/* Full outflow-by-category breakdown. */}
      {data.top_outflow_categories.length > 0 && (
        <ChartCard title={t("cashFlow.analysis.charts.outflowByCategory")}>
          <OutflowBreakdown rows={data.top_outflow_categories} total={data.cash_out.total.minor} />
        </ChartCard>
      )}
    </div>
  );
}

/** A KPI tile: label + icon header, colored value, sub-line, optional trend + extra rows. */
function KpiCard({
  label,
  value,
  valueClass,
  icon: Icon,
  sub,
  trend,
  children,
}: {
  label: string;
  value: string;
  valueClass?: string;
  icon: React.ComponentType<{ className?: string }>;
  sub: string;
  trend?: React.ReactNode;
  children?: React.ReactNode;
}) {
  return (
    <Card className="border-border/60">
      <CardContent className="p-4">
        <div className="flex items-center justify-between gap-2">
          <span className="text-xs font-medium text-muted-foreground">{label}</span>
          <Icon className="size-3.5 text-muted-foreground" />
        </div>
        <div className={cn("mt-1 text-lg font-bold tabular-nums md:text-2xl", valueClass)}>{value}</div>
        <div className="mt-0.5 flex items-center justify-between gap-2">
          <span className="text-[10px] text-muted-foreground md:text-xs">{sub}</span>
          {trend}
        </div>
        {children && <div className="mt-1.5 space-y-0.5">{children}</div>}
      </CardContent>
    </Card>
  );
}

/** Period-over-period trend pill. `goodUp` flips the colour semantics (e.g. cash-out up is bad). */
function TrendPill({ change, goodUp }: { change: number | null; goodUp: boolean }) {
  if (change === null) return null;
  if (Math.abs(change) < 1) {
    return (
      <span className="flex items-center gap-0.5 text-[10px] text-muted-foreground md:text-xs">
        <Minus className="size-3" />
        0%
      </span>
    );
  }
  const up = change > 0;
  const good = goodUp ? up : !up;
  return (
    <span className={cn("flex items-center gap-0.5 text-[10px] font-medium tabular-nums md:text-xs", good ? "text-emerald-600" : "text-destructive")}>
      {up ? <TrendingUp className="size-3" /> : <TrendingDown className="size-3" />}
      {up ? "+" : ""}
      {change.toFixed(1)}%
    </span>
  );
}

/** Cash-in vs cash-out per category, as twin proportion bars. */
function CategoryComparison({ rows }: { rows: CashFlowAnalysis["by_category"] }) {
  const { t } = useTranslation();
  const { moneyObject } = useFormatters();
  const max = Math.max(1, ...rows.map((r) => Math.max(r.cash_in.minor, r.cash_out.minor)));

  return (
    <div className="space-y-3 py-1">
      {rows.slice(0, 8).map((r) => (
        <div key={r.category} className="space-y-1">
          <div className="flex items-center justify-between gap-2 text-xs">
            <span className="truncate font-medium">{r.category}</span>
            <span className="shrink-0 tabular-nums text-muted-foreground">
              <span className="text-emerald-600">{moneyObject(r.cash_in)}</span>
              {" / "}
              <span className="text-destructive">{moneyObject(r.cash_out)}</span>
            </span>
          </div>
          <div className="flex gap-1">
            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted" aria-label={t("cashFlow.analysis.series.cashIn")}>
              <div className="h-full rounded-full bg-emerald-500" style={{ width: `${(r.cash_in.minor / max) * 100}%` }} />
            </div>
            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted" aria-label={t("cashFlow.analysis.series.cashOut")}>
              <div className="h-full rounded-full bg-rose-500" style={{ width: `${(r.cash_out.minor / max) * 100}%` }} />
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}

/** Four payment-discipline metrics: collection + payment speed and on-time rates. */
function PaymentDiscipline({ discipline }: { discipline: CashFlowAnalysis["payment_discipline"] }) {
  const { t } = useTranslation();
  const d = discipline;
  const days = (n: number | null) => (n !== null ? t("cashFlow.analysis.discipline.days", { count: n }) : "—");
  const pct = (n: number | null) => (n !== null ? t("cashFlow.analysis.discipline.onTime", { pct: n }) : "—");

  return (
    <div className="grid grid-cols-2 gap-4 py-1">
      <Metric label={t("cashFlow.analysis.discipline.avgDaysToCollect")} value={days(d.avg_days_to_collect)} hint={pct(d.collected_on_time_percent)} />
      <Metric label={t("cashFlow.analysis.discipline.avgDaysToPay")} value={days(d.avg_days_to_pay)} hint={pct(d.paid_on_time_percent)} />
    </div>
  );
}

function Metric({ label, value, hint }: { label: string; value: string; hint: string }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="mt-1 text-xl font-semibold tabular-nums">{value}</p>
      <p className="text-[10px] text-muted-foreground">{hint}</p>
    </div>
  );
}

/** Top outstanding receivables / payables, newest-largest first. */
function OutstandingTable({ rows, emptyLabel }: { rows: CashFlowOutstandingEntry[]; emptyLabel: string }) {
  const { t } = useTranslation();
  const { moneyObject, date } = useFormatters();

  if (rows.length === 0) return <Empty label={emptyLabel} />;

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
          <tr>
            <th className="py-2 pr-3 font-medium">{t("cashFlow.analysis.tables.counterparty")}</th>
            <th className="py-2 pr-3 font-medium">{t("cashFlow.analysis.tables.due")}</th>
            <th className="py-2 text-right font-medium">{t("cashFlow.analysis.tables.amount")}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id} className="border-b border-border last:border-0">
              <td className="py-2.5 pr-3">
                <span className="font-medium">{r.counterparty ?? t("cashFlow.analysis.tables.unassigned")}</span>
                {(r.description || r.reference) && (
                  <span className="block truncate text-xs text-muted-foreground">{r.description ?? r.reference}</span>
                )}
              </td>
              <td className="py-2.5 pr-3 text-xs">
                {r.due_date ? (
                  <span className={r.is_overdue ? "font-medium text-destructive" : "text-muted-foreground"}>
                    {date(r.due_date)}
                    {r.is_overdue && (
                      <span className="ml-1 inline-flex items-center gap-0.5">
                        <AlertTriangle className="size-3" />
                        {t("cashFlow.analysis.tables.daysOutstanding", { count: r.days_outstanding })}
                      </span>
                    )}
                  </span>
                ) : (
                  <span className="text-muted-foreground">—</span>
                )}
              </td>
              <td className="py-2.5 text-right font-medium tabular-nums">{moneyObject(r.amount)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Outflow-by-category bars with share %, count, and a footer total. */
function OutflowBreakdown({ rows, total }: { rows: CashFlowAnalysis["top_outflow_categories"]; total: number }) {
  const { t } = useTranslation();
  const { moneyObject, money2 } = useFormatters();

  return (
    <div>
      <div className="space-y-1.5">
        {rows.map((r) => {
          const pct = total > 0 ? (r.amount.minor / total) * 100 : 0;
          return (
            <div key={r.category} className="flex items-center gap-2">
              <div className="min-w-0 flex-1">
                <div className="flex items-center justify-between text-xs md:text-sm">
                  <span className="truncate font-medium">{r.category}</span>
                  <span className="ml-2 shrink-0 tabular-nums">
                    {moneyObject(r.amount)}
                    <span className="ml-1 text-muted-foreground">({pct.toFixed(1)}%)</span>
                  </span>
                </div>
                <div className="mt-0.5 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                  <div className="h-full rounded-full bg-rose-400" style={{ width: `${Math.min(pct, 100)}%` }} />
                </div>
              </div>
              <span className="w-8 shrink-0 text-right text-[10px] text-muted-foreground">
                {t("cashFlow.analysis.outflowCount", { count: r.count })}
              </span>
            </div>
          );
        })}
      </div>
      <div className="mt-3 flex justify-between border-t border-border pt-2 text-xs font-medium md:text-sm">
        <span>{t("cashFlow.analysis.total")}</span>
        <span className="tabular-nums">{money2(total)}</span>
      </div>
    </div>
  );
}

function Empty({ label }: { label: string }) {
  return <div className="flex h-[160px] items-center justify-center text-sm text-muted-foreground">{label}</div>;
}

/** Money with an explicit leading "+" for non-negative values (matches the prototype). */
function signed(m: Money, fmt: (m: Money) => string): string {
  return `${m.minor >= 0 ? "+" : ""}${fmt(m)}`;
}
