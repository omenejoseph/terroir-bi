"use client";

import * as React from "react";
import {
  Area,
  CartesianGrid,
  ComposedChart,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { CalendarDays, Coins, Layers, Minus, RefreshCw, Target, TrendingDown, TrendingUp, Users } from "lucide-react";

import { useDemandForecast } from "@/hooks/use-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { cn } from "@/lib/utils";
import type { DemandForecast, ForecastProduct, Money } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { ChartCard, Sparkline } from "@/components/dashboard/charts";
import { InfoHint } from "@/components/ui/info-hint";

/** "YYYY-MM" → short "Mon YY" label (locale-aware). */
function monthShort(key: string): string {
  const d = new Date(`${key}-01T00:00:00`);
  return d.toLocaleDateString(undefined, { month: "short", year: "2-digit" });
}

const pct = (part: number, whole: number) => (whole > 0 ? Math.round((part / whole) * 100) : 0);

export function DemandForecast() {
  const { t } = useTranslation();
  const { data, isLoading, isFetching, refetch } = useDemandForecast();
  const [tab, setTab] = React.useState<"overview" | "products" | "customers">("overview");

  if (isLoading || !data) {
    return (
      <div className="flex items-center justify-center py-20">
        <Spinner className="size-6 text-muted-foreground" />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Tabs
          tabs={[
            { value: "overview", label: t("orders.forecast.tabs.overview") },
            { value: "products", label: `${t("orders.forecast.tabs.products")} (${data.products.length})` },
            { value: "customers", label: `${t("orders.forecast.tabs.customers")} (${data.customers.length})` },
          ]}
          value={tab}
          onChange={(v) => setTab(v as typeof tab)}
        />
        <Button variant="outline" size="sm" onClick={() => void refetch()} disabled={isFetching}>
          {isFetching ? <Spinner className="size-3.5" /> : <RefreshCw className="size-3.5" />}
          {t("orders.forecast.refresh")}
        </Button>
      </div>

      {tab === "overview" && <OverviewTab data={data} />}
      {tab === "products" && <ProductsTab data={data} />}
      {tab === "customers" && <CustomersTab data={data} />}
    </div>
  );
}

// ── Overview ─────────────────────────────────────────────────────────────────

function OverviewTab({ data }: { data: DemandForecast }) {
  const { t } = useTranslation();
  const { moneyObject, number } = useFormatters();
  const currency = data.totals.last12m.revenue.currency;
  const yoyPct = data.yoy_factor != null ? Math.round((data.yoy_factor - 1) * 100) : null;
  const annual = data.annual_revenue_projection;
  const cm = data.current_month;
  // Each category's share of all categories' projected bottles.
  const grandTotalBottles = data.category_breakdown.reduce((s, g) => s + g.projected_total_bottles, 0);

  // Combined revenue series: 12m history + 3m forecast, bridged so the line connects.
  const chartData = [
    ...data.revenue_history_12m.map((h) => ({
      month: monthShort(h.month),
      revenue: h.revenue.minor,
      lastYear: h.last_year.minor,
      forecast: null as number | null,
    })),
    ...data.revenue_forecast_next_3m.map((f) => ({
      month: monthShort(f.month),
      revenue: null as number | null,
      lastYear: f.last_year_revenue?.minor ?? null,
      forecast: f.expected?.minor ?? null,
    })),
  ];
  // Bridge: seed the forecast at the last actual point so the dashed line joins.
  const lastHist = data.revenue_history_12m.at(-1);
  if (lastHist && chartData[data.revenue_history_12m.length - 1]) {
    chartData[data.revenue_history_12m.length - 1].forecast = lastHist.revenue.minor;
  }

  const money = (m: Money | null) => (m ? moneyObject(m) : "—");

  return (
    <div className="space-y-4">
      {/* KPI tiles */}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Tile
          icon={Coins}
          accent="bg-emerald-500/10 text-emerald-500"
          label={t("orders.forecast.thisMonth")}
          value={money(cm.revenue_so_far)}
          hint={t("orders.forecast.hints.thisMonth")}
          subs={[
            cm.projected_full_month
              ? `${t("orders.forecast.projected")}: ${money(cm.projected_full_month)}`
              : t("orders.forecast.noProjection"),
            cm.last_year_same_month ? `${t("orders.forecast.lastYear")}: ${money(cm.last_year_same_month)}` : null,
          ]}
        />
        <Tile
          icon={yoyPct != null && yoyPct >= 0 ? TrendingUp : yoyPct != null ? TrendingDown : Minus}
          accent={yoyPct != null && yoyPct >= 0 ? "bg-emerald-500/10 text-emerald-500" : "bg-amber-500/10 text-amber-500"}
          label={t("orders.forecast.yoy")}
          value={yoyPct != null ? `${yoyPct > 0 ? "+" : ""}${yoyPct}%` : "—"}
          hint={t("orders.forecast.hints.yoy")}
          subs={[t("orders.forecast.yoySub")]}
        />
        <Tile
          icon={Target}
          accent="bg-violet-500/10 text-violet-500"
          label={`${t("orders.forecast.annualProjection")} ${annual.year}`}
          value={money(annual.projected_total)}
          hint={t("orders.forecast.hints.annual")}
          subs={[
            `${t("orders.forecast.ytd")}: ${money(annual.ytd)} (${pct(annual.ytd.minor, annual.projected_total.minor)}%) · ${t("orders.forecast.remaining")}: ${money(annual.projected_remaining)}`,
            annual.has_history_gap ? t("orders.forecast.partialHistory") : null,
          ]}
        />
        <Tile
          icon={Users}
          accent="bg-sky-500/10 text-sky-500"
          label={t("orders.forecast.last12m")}
          value={money(data.totals.last12m.revenue)}
          hint={t("orders.forecast.hints.last12m")}
          subs={[
            `${number(data.totals.last12m.bottles)} ${t("orders.forecast.bottles")} · ${data.totals.last12m.order_count} ${t("orders.forecast.orders")} · ${data.totals.last12m.customers} ${t("orders.forecast.customers")}`,
          ]}
        />
      </div>

      {/* Revenue history + forecast */}
      <ChartCard title={t("orders.forecast.revenueChartTitle")}>
        <ResponsiveContainer width="100%" height={260}>
          <ComposedChart data={chartData} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
            <XAxis dataKey="month" tick={{ fontSize: 11 }} stroke="var(--color-muted-foreground)" />
            <YAxis
              tick={{ fontSize: 11 }}
              stroke="var(--color-muted-foreground)"
              tickFormatter={(v: number) => (v >= 100000 ? `${Math.round(v / 100000)}k` : `${Math.round(v / 100)}`)}
              width={44}
            />
            <Tooltip
              formatter={(v) => (v == null ? "—" : moneyObject({ minor: Number(v), currency }))}
              contentStyle={{ fontSize: 12, borderRadius: 8, border: "1px solid var(--color-border)" }}
            />
            <Area
              type="monotone"
              dataKey="revenue"
              name={t("orders.forecast.revenue")}
              stroke="#10b981"
              fill="#10b98133"
              strokeWidth={2}
              connectNulls
            />
            <Line
              type="monotone"
              dataKey="forecast"
              name={t("orders.forecast.forecast")}
              stroke="#10b981"
              strokeDasharray="6 4"
              strokeWidth={2}
              dot={false}
              connectNulls
            />
            <Line
              type="monotone"
              dataKey="lastYear"
              name={t("orders.forecast.lastYear")}
              stroke="#94a3b8"
              strokeDasharray="2 4"
              strokeWidth={1.5}
              dot={false}
              connectNulls
            />
          </ComposedChart>
        </ResponsiveContainer>
      </ChartCard>

      {/* Historical volume snapshot */}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {(
          [
            ["last3m", t("orders.forecast.last3m"), "border-l-sky-400"],
            ["last6m", t("orders.forecast.last6m"), "border-l-blue-400"],
            ["last12m", t("orders.forecast.last12m"), "border-l-indigo-400"],
          ] as const
        ).map(([key, label, border]) => {
          const w = data.totals[key];
          return (
            <Card key={key} className={cn("border-l-4", border)}>
              <CardContent className="space-y-1 p-4">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="text-xl font-semibold tabular-nums">{moneyObject(w.revenue)}</p>
                <p className="text-xs text-muted-foreground">
                  {number(w.bottles)} {t("orders.forecast.bottles")} · {w.order_count} {t("orders.forecast.orders")} ·{" "}
                  {w.customers} {t("orders.forecast.customers")}
                </p>
              </CardContent>
            </Card>
          );
        })}
      </div>

      {/* Category breakdown */}
      {data.category_breakdown.length > 0 && (
        <ChartCard
          title={t("orders.forecast.categoryTitle")}
          action={<InfoHint text={t("orders.forecast.hints.categoryShare")} />}
        >
          <div className="space-y-4">
            {data.category_breakdown.map((g) => (
              <div key={g.group} className="space-y-1.5">
                <div className="flex items-center justify-between text-sm">
                  <span className="font-medium">
                    {g.group} <span className="text-xs text-muted-foreground">· {g.products} {t("orders.forecast.products")}</span>
                  </span>
                  <span className="flex items-baseline gap-1.5 tabular-nums text-muted-foreground">
                    <span className="font-semibold text-foreground">{number(g.projected_total_bottles)}</span>
                    <span className="text-xs">{t("orders.forecast.btl")}</span>
                    <span className="text-[10px]">({pct(g.projected_total_bottles, grandTotalBottles)}%)</span>
                  </span>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-muted">
                  <div
                    className="h-full rounded-full bg-violet-500/30"
                    style={{ width: "100%" }}
                  >
                    <div
                      className="h-full rounded-full bg-violet-500"
                      style={{ width: `${pct(g.ytd_bottles, g.projected_total_bottles)}%` }}
                    />
                  </div>
                </div>
                <p className="text-[11px] text-muted-foreground">
                  {t("orders.forecast.ytd")}: {number(g.ytd_bottles)} ({pct(g.ytd_bottles, g.projected_total_bottles)}%) ·{" "}
                  {t("orders.forecast.remaining")}: {number(g.projected_remaining_bottles)}
                  {g.has_history_gap && (
                    <span className="ml-1 italic text-amber-600 dark:text-amber-400">{t("orders.forecast.partialLY")}</span>
                  )}
                </p>

                {/* Per-subcategory breakdown within the group (only when it splits). */}
                {g.subcategories.length > 1 && (
                  <div className="space-y-1.5 pl-3 pt-1">
                    {g.subcategories.map((sc) => (
                      <div key={sc.subcategory ?? "—"} className="space-y-0.5">
                        <div className="flex items-center justify-between text-[11px] text-muted-foreground">
                          <span>{sc.subcategory ?? t("orders.forecast.uncategorized")}</span>
                          <span className="tabular-nums">
                            {number(sc.projected_total_bottles)} ({pct(sc.projected_total_bottles, g.projected_total_bottles)}%)
                          </span>
                        </div>
                        <div className="h-1 overflow-hidden rounded-full bg-muted">
                          <div
                            className="h-full rounded-full bg-violet-500/55"
                            style={{ width: `${pct(sc.projected_total_bottles, g.projected_total_bottles)}%` }}
                          />
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>
        </ChartCard>
      )}

      {/* Next 3 months revenue forecast */}
      <ChartCard title={t("orders.forecast.next3mTitle")}>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          {data.revenue_forecast_next_3m.map((f) => (
            <div key={f.month} className="rounded-lg border border-border/60 bg-violet-500/5 p-3">
              <p className="text-xs text-muted-foreground">{monthShort(f.month)}</p>
              <p className="text-lg font-bold tabular-nums">{f.expected ? moneyObject(f.expected) : "—"}</p>
              <p className="text-[11px] text-muted-foreground">
                {f.last_year_revenue
                  ? `${t("orders.forecast.lastYear")}: ${moneyObject(f.last_year_revenue)}`
                  : t("orders.forecast.noHistory")}
              </p>
            </div>
          ))}
        </div>
        <p className="mt-2 text-[11px] text-muted-foreground">{t("orders.forecast.next3mNote")}</p>
      </ChartCard>
    </div>
  );
}

function Tile({
  icon: Icon,
  accent,
  label,
  value,
  hint,
  subs,
}: {
  icon: React.ComponentType<{ className?: string }>;
  accent: string;
  label: string;
  value: string;
  hint: string;
  subs: (string | null)[];
}) {
  return (
    <Card className="border-border/60">
      <CardContent className="p-4">
        <div className="flex items-start justify-between gap-2">
          <p className="flex items-center gap-1 text-sm text-muted-foreground">
            {label}
            <InfoHint text={hint} />
          </p>
          <span className={cn("flex size-8 shrink-0 items-center justify-center rounded-lg", accent)}>
            <Icon className="size-4" />
          </span>
        </div>
        <p className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">{value}</p>
        {subs.filter(Boolean).map((s, i) => (
          <p key={i} className={cn("mt-1 text-[11px]", i === 0 ? "text-muted-foreground" : "text-muted-foreground")}>
            {s}
          </p>
        ))}
      </CardContent>
    </Card>
  );
}

// ── Vintage sell-through bar ───────────────────────────────────────────────────

export function SellThroughBar({ product }: { product: ForecastProduct }) {
  const { t } = useTranslation();
  const total = product.total_produced;
  if (total <= 0) return <span className="text-xs text-muted-foreground">—</span>;

  let soldPct = (product.sold / total) * 100;
  let committedPct = (product.to_be_sold / total) * 100;
  if (soldPct + committedPct > 100) {
    const overflow = soldPct + committedPct - 100;
    if (committedPct >= overflow) committedPct -= overflow;
    else {
      soldPct = Math.max(0, soldPct - (overflow - committedPct));
      committedPct = 0;
    }
  }
  const remainingPct = Math.max(0, 100 - soldPct - committedPct);
  const oversold = product.remaining <= 0 && product.to_be_sold > 0;

  return (
    <div className="space-y-1">
      <div className="flex h-2 overflow-hidden rounded-full bg-muted">
        <div className="h-full bg-emerald-500" style={{ width: `${soldPct}%` }} />
        <div className="h-full bg-amber-400" style={{ width: `${committedPct}%` }} />
      </div>
      <p className="text-[10px] tabular-nums text-muted-foreground">
        {Math.round(soldPct)}% {t("orders.forecast.shipped")} · {Math.round(committedPct)}% {t("orders.forecast.onOrder")} ·{" "}
        {Math.round(remainingPct)}% {t("orders.forecast.onShelf")}
        {oversold && (
          <span className="ml-1 rounded bg-amber-200 px-1 text-[9px] font-bold uppercase text-amber-800">
            {t("orders.forecast.oversold")}
          </span>
        )}
      </p>
    </div>
  );
}

// ── Products ─────────────────────────────────────────────────────────────────

function ProductsTab({ data }: { data: DemandForecast }) {
  const { t } = useTranslation();
  const { number, date } = useFormatters();
  const [search, setSearch] = React.useState("");

  if (data.products.length === 0) {
    return (
      <Card>
        <CardContent className="py-12 text-center text-sm text-muted-foreground">
          {t("orders.forecast.noProducts")}
        </CardContent>
      </Card>
    );
  }

  // Client-side filter on name / SKU / group — the full product list is already loaded.
  const term = search.trim().toLowerCase();
  const products = term
    ? data.products.filter((p) =>
        [p.name, p.sku, p.group].some((f) => (f ?? "").toLowerCase().includes(term)),
      )
    : data.products;

  return (
    <div className="space-y-3">
      <Input
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        placeholder={t("orders.forecast.searchProduct")}
        className="w-full sm:max-w-xs"
      />
      <Card>
        <CardContent className="overflow-x-auto p-0">
          <table className="w-full min-w-[820px] text-sm">
            <thead>
              <tr className="border-b border-border text-left text-xs text-muted-foreground">
                <th className="p-3">{t("orders.forecast.colProduct")}</th>
                <th className="p-3">{t("orders.forecast.colTrend")}</th>
                <th className="p-3 text-right">{t("orders.forecast.col3m")}</th>
                <th className="p-3 text-right">{t("orders.forecast.col6m")}</th>
                <th className="p-3 text-right">{t("orders.forecast.col12m")}</th>
                <th className="p-3">{t("orders.forecast.colSellThrough")}</th>
                <th className="p-3">{t("orders.forecast.colAnnual")}</th>
                <th className="p-3">{t("orders.forecast.colBiggestMonth")}</th>
                <th className="p-3">{t("orders.forecast.colNext3m")}</th>
              </tr>
            </thead>
            <tbody>
              {products.length === 0 && (
                <tr>
                  <td colSpan={9} className="p-6 text-center text-sm text-muted-foreground">
                    {t("orders.forecast.noProductMatches")}
                  </td>
                </tr>
              )}
              {products.map((p) => (
              <tr key={p.id} className="border-b border-border/50 align-top">
                <td className="p-3">
                  <p className="font-medium">{p.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {p.sku}
                    {p.group ? ` · ${p.group}` : ""}
                  </p>
                  {p.last_sold_date && (
                    <p className="text-[10px] text-muted-foreground">
                      {t("orders.forecast.lastSold")}: {date(p.last_sold_date)}
                    </p>
                  )}
                </td>
                <td className="w-28 p-3">
                  {p.history_12m.some((h) => h.bottles > 0) ? (
                    <Sparkline data={p.history_12m.map((h) => h.bottles)} />
                  ) : (
                    <span className="text-xs text-muted-foreground">—</span>
                  )}
                </td>
                <td className="p-3 text-right tabular-nums">
                  {number(p.last3m.bottles)}
                  <span className="block text-[10px] text-muted-foreground">
                    {p.last3m.orders}o · {p.last3m.customers}c
                  </span>
                </td>
                <td className="p-3 text-right tabular-nums">
                  {number(p.last6m.bottles)}
                  <span className="block text-[10px] text-muted-foreground">
                    {p.last6m.orders}o · {p.last6m.customers}c
                  </span>
                </td>
                <td className="p-3 text-right tabular-nums">
                  {number(p.last12m.bottles)}
                  <span className="block text-[10px] text-muted-foreground">
                    {p.last12m.orders}o · {p.last12m.customers}c
                  </span>
                </td>
                <td className="w-40 p-3">
                  <SellThroughBar product={p} />
                </td>
                <td className="w-44 p-3">
                  <p className="font-semibold tabular-nums">
                    {number(p.annual_projection.projected_total_bottles)} {t("orders.forecast.btl")}
                  </p>
                  <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                      className="h-full rounded-full bg-indigo-500"
                      style={{ width: `${pct(p.annual_projection.ytd_bottles, p.annual_projection.projected_total_bottles)}%` }}
                    />
                  </div>
                  <p className="mt-0.5 text-[10px] text-muted-foreground">
                    {t("orders.forecast.ytd")} {number(p.annual_projection.ytd_bottles)}
                    {p.annual_projection.has_history_gap && (
                      <span className="ml-1 italic text-amber-600 dark:text-amber-400">{t("orders.forecast.partialLY")}</span>
                    )}
                  </p>
                </td>
                <td className="p-3 text-xs">
                  {p.biggest_month ? (
                    <span className="tabular-nums">
                      {monthShort(p.biggest_month.month)} · {number(p.biggest_month.bottles)}
                    </span>
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </td>
                <td className="p-3">
                  <div className="flex gap-1.5">
                    {p.expected_next_3m.map((m) => (
                      <div
                        key={m.month}
                        className={cn(
                          "rounded border-l-2 px-1.5 py-1 text-center",
                          m.expected != null ? "border-l-indigo-400 bg-indigo-500/5" : "border-l-transparent bg-muted/50",
                        )}
                      >
                        <p className="text-[9px] text-muted-foreground">{monthShort(m.month)}</p>
                        <p className="text-xs font-semibold tabular-nums">{m.expected != null ? number(m.expected) : "—"}</p>
                        <p className="text-[9px] text-muted-foreground">{t("orders.forecast.lyShort")}: {number(m.last_year_bottles)}</p>
                      </div>
                    ))}
                  </div>
                </td>
              </tr>
              ))}
            </tbody>
          </table>
        </CardContent>
      </Card>
    </div>
  );
}

// ── Customers ──────────────────────────────────────────────────────────────────

function CustomersTab({ data }: { data: DemandForecast }) {
  const { t } = useTranslation();
  const { moneyObject, number, date } = useFormatters();
  const [search, setSearch] = React.useState("");

  if (data.customers.length === 0) {
    return (
      <Card>
        <CardContent className="py-12 text-center text-sm text-muted-foreground">
          {t("orders.forecast.noCustomers")}
        </CardContent>
      </Card>
    );
  }

  // Client-side filter — the full customer list is already loaded with the forecast.
  const term = search.trim().toLowerCase();
  const customers = term
    ? data.customers.filter((c) => (c.name ?? "").toLowerCase().includes(term))
    : data.customers;

  // Bar scale stays anchored to the full list so widths don't jump while filtering.
  const maxRev = Math.max(1, ...data.customers.map((c) => c.revenue_last_12m.minor));

  return (
    <div className="space-y-3">
      <Input
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        placeholder={t("orders.forecast.searchCustomer")}
        className="w-full sm:max-w-xs"
      />
      <Card>
        <CardContent className="overflow-x-auto p-0">
          <table className="w-full min-w-[760px] text-sm">
            <thead>
              <tr className="border-b border-border text-left text-xs text-muted-foreground">
                <th className="p-3">{t("orders.forecast.colCustomer")}</th>
                <th className="p-3">{t("orders.forecast.colRev12m")}</th>
                <th className="p-3 text-right">{t("orders.forecast.colYtd")}</th>
                <th className="p-3 text-right">{t("orders.forecast.colOrders")}</th>
                <th className="p-3 text-right">{t("orders.forecast.colGap")}</th>
                <th className="p-3 text-right">{t("orders.forecast.colLastOrder")}</th>
                <th className="p-3">{t("orders.forecast.colExpectedBy")}</th>
              </tr>
            </thead>
            <tbody>
              {customers.length === 0 && (
                <tr>
                  <td colSpan={7} className="p-6 text-center text-sm text-muted-foreground">
                    {t("orders.forecast.noCustomerMatches")}
                  </td>
                </tr>
              )}
              {customers.map((c) => (
              <tr key={c.id} className="border-b border-border/50">
                <td className="p-3">
                  <p className="font-medium">{c.name ?? "—"}</p>
                  <p className="text-[10px] text-muted-foreground">
                    {t("orders.forecast.allTime")}: {moneyObject(c.total_value)}
                  </p>
                </td>
                <td className="min-w-[200px] p-3">
                  <p className="font-bold tabular-nums">{moneyObject(c.revenue_last_12m)}</p>
                  <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                      className="h-full rounded-full bg-emerald-500"
                      style={{ width: `${pct(c.revenue_last_12m.minor, maxRev)}%` }}
                    />
                  </div>
                </td>
                <td className="p-3 text-right font-bold tabular-nums">{moneyObject(c.revenue_ytd)}</td>
                <td className="p-3 text-right tabular-nums">{c.order_count}</td>
                <td className="p-3 text-right tabular-nums">
                  {c.median_gap_days != null ? `${c.median_gap_days}${t("orders.forecast.daysShort")}` : "—"}
                </td>
                <td className="p-3 text-right">
                  {c.last_order_date ? (
                    <>
                      {date(c.last_order_date)}
                      {c.days_since_last_order != null && (
                        <span className="block text-[10px] text-muted-foreground">
                          {c.days_since_last_order}
                          {t("orders.forecast.daysShort")} {t("orders.forecast.ago")}
                        </span>
                      )}
                    </>
                  ) : (
                    "—"
                  )}
                </td>
                <td className="p-3 text-xs">{c.expected_by_date ? date(c.expected_by_date) : "—"}</td>
              </tr>
              ))}
            </tbody>
          </table>
        </CardContent>
      </Card>
    </div>
  );
}
