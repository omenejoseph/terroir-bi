"use client";

import * as React from "react";
import Link from "next/link";
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import {
  ArrowLeft,
  CalendarClock,
  FileText,
  Gauge,
  HandCoins,
  Hourglass,
  Wallet,
} from "lucide-react";

import { useInflowAnalytics } from "@/hooks/use-inflows";
import { addDays, addMonths, endOfMonth, endOfQuarter, startOfMonth, startOfQuarter } from "@/lib/calendar";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Money } from "@/lib/types";
import { Spinner } from "@/components/ui/spinner";
import { Input } from "@/components/ui/input";
import { StatCard } from "@/components/dashboard/stat-card";
import { ChartCard, DonutChart } from "@/components/dashboard/charts";
import { cn } from "@/lib/utils";

const PRESETS = [
  "today",
  "yesterday",
  "7d",
  "thisMonth",
  "lastMonth",
  "thisQuarter",
  "lastQuarter",
  "thisYear",
  "lastYear",
  "custom",
] as const;
type Preset = (typeof PRESETS)[number];

const iso = (d: Date): string =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

function presetRange(p: Preset, now = new Date()): { from?: string; to?: string } {
  switch (p) {
    case "today":
      return { from: iso(now), to: iso(now) };
    case "yesterday": {
      const y = addDays(now, -1);
      return { from: iso(y), to: iso(y) };
    }
    case "7d":
      return { from: iso(addDays(now, -6)), to: iso(now) };
    case "thisMonth":
      return { from: iso(startOfMonth(now)), to: iso(endOfMonth(now)) };
    case "lastMonth": {
      const lm = addMonths(now, -1);
      return { from: iso(startOfMonth(lm)), to: iso(endOfMonth(lm)) };
    }
    case "thisQuarter":
      return { from: iso(startOfQuarter(now)), to: iso(endOfQuarter(now)) };
    case "lastQuarter": {
      const lq = addMonths(now, -3);
      return { from: iso(startOfQuarter(lq)), to: iso(endOfQuarter(lq)) };
    }
    case "thisYear":
      return { from: iso(new Date(now.getFullYear(), 0, 1)), to: iso(now) };
    case "lastYear":
      return {
        from: iso(new Date(now.getFullYear() - 1, 0, 1)),
        to: iso(new Date(now.getFullYear() - 1, 11, 31)),
      };
    case "custom":
      return {};
  }
}

const DONUT_COLORS = [
  "var(--color-primary)",
  "#10b981",
  "#f59e0b",
  "#6366f1",
  "#ec4899",
  "#06b6d4",
  "#84cc16",
];

export default function InflowAnalyticsPage() {
  const { t } = useTranslation();
  const { moneyObject, number } = useFormatters();

  const [preset, setPreset] = React.useState<Preset>("thisMonth");
  const [customFrom, setCustomFrom] = React.useState("");
  const [customTo, setCustomTo] = React.useState("");

  const range =
    preset === "custom" ? { from: customFrom || undefined, to: customTo || undefined } : presetRange(preset);
  const { data, isLoading } = useInflowAnalytics(range);

  const eur = (minor: number) => Math.round(minor / 100);
  const signed = (m: Money) => `${m.minor >= 0 ? "+" : ""}${moneyObject(m)}`;

  return (
    <div className="space-y-6">
      <div className="space-y-3">
        <Link
          href="/inflows"
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
        >
          <ArrowLeft className="size-4" />
          {t("inflows.back")}
        </Link>
        <h1 className="text-2xl font-semibold tracking-tight">{t("inflows.analytics.title")}</h1>
      </div>

      <div className="flex flex-wrap items-center gap-1.5">
        {PRESETS.map((p) => (
          <button
            key={p}
            type="button"
            aria-pressed={preset === p}
            onClick={() => setPreset(p)}
            className={cn(
              "rounded-full border px-3 py-1 text-xs font-medium transition-colors",
              preset === p
                ? "border-primary bg-primary/10 text-primary"
                : "border-border text-muted-foreground hover:text-foreground",
            )}
          >
            {t(`inflows.analytics.range.${p}`)}
          </button>
        ))}
        {preset === "custom" && (
          <div className="flex items-center gap-2">
            <Input type="date" aria-label={t("inflows.analytics.from")} value={customFrom} onChange={(e) => setCustomFrom(e.target.value)} className="w-40" />
            <Input type="date" aria-label={t("inflows.analytics.to")} value={customTo} onChange={(e) => setCustomTo(e.target.value)} className="w-40" />
          </div>
        )}
      </div>

      {isLoading || !data ? (
        <div className="flex items-center justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <StatCard
              label={t("inflows.analytics.cards.invoiced")}
              value={<Sub main={moneyObject(data.invoiced.total)} sub={t("inflows.analytics.nInvoices", { count: data.invoiced.count })} />}
              icon={FileText}
              accent="bg-sky-500/10 text-sky-500"
            />
            <StatCard
              label={t("inflows.analytics.cards.collected")}
              value={<Sub main={moneyObject(data.collected.total)} sub={t("inflows.analytics.nPayments", { count: data.collected.count })} />}
              icon={HandCoins}
              accent="bg-emerald-500/10 text-emerald-500"
              delayMs={40}
            />
            <StatCard
              label={t("inflows.analytics.cards.pending")}
              value={<Sub main={moneyObject(data.pending.total)} sub={t("inflows.analytics.nInvoices", { count: data.pending.count })} />}
              icon={Hourglass}
              accent="bg-amber-500/10 text-amber-500"
              delayMs={80}
            />
            <StatCard
              label={t("inflows.analytics.cards.netCashFlow")}
              value={<Sub main={signed(data.net_cash_flow.net)} sub={t("inflows.analytics.costsValue", { value: moneyObject(data.net_cash_flow.costs) })} />}
              icon={Wallet}
              accent="bg-violet-500/10 text-violet-500"
              delayMs={120}
            />
            <StatCard
              label={t("inflows.analytics.cards.avgDaysToCollect")}
              value={
                <Sub
                  main={data.avg_days_to_collect.days !== null ? number(data.avg_days_to_collect.days) : "—"}
                  sub={t("inflows.analytics.nCollected", { count: data.avg_days_to_collect.count })}
                />
              }
              icon={CalendarClock}
              accent="bg-teal-500/10 text-teal-500"
              delayMs={160}
            />
            <StatCard
              label={t("inflows.analytics.cards.avgInflow")}
              value={moneyObject(data.avg_inflow.avg)}
              icon={Gauge}
              accent="bg-rose-500/10 text-rose-500"
              delayMs={200}
            />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <ChartCard title={t("inflows.analytics.charts.trends")}>
              {data.over_time.length === 0 ? (
                <Empty label={t("inflows.analytics.noData")} />
              ) : (
                <div className="h-[240px] w-full">
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data.over_time.map((r) => ({ label: r.month, value: eur(r.total.minor) }))} margin={{ top: 6, right: 6, left: -18, bottom: 0 }}>
                      <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
                      <XAxis dataKey="label" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                      <YAxis tick={{ fontSize: 11 }} axisLine={false} tickLine={false} width={40} />
                      <Tooltip formatter={(v: number) => `€${number(v)}`} />
                      <Bar dataKey="value" fill="var(--color-primary)" radius={[3, 3, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              )}
            </ChartCard>

            <ChartCard title={t("inflows.analytics.charts.cashFlow")}>
              {data.cash_flow.length === 0 ? (
                <Empty label={t("inflows.analytics.noData")} />
              ) : (
                <div className="h-[240px] w-full">
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                      data={data.cash_flow.map((m) => ({ label: m.month, inflows: eur(m.inflows.minor), costs: eur(m.costs.minor) }))}
                      margin={{ top: 6, right: 6, left: -18, bottom: 0 }}
                    >
                      <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
                      <XAxis dataKey="label" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                      <YAxis tick={{ fontSize: 11 }} axisLine={false} tickLine={false} width={40} />
                      <Tooltip formatter={(v: number) => `€${number(v)}`} />
                      <Legend wrapperStyle={{ fontSize: 11 }} />
                      <Bar name={t("inflows.analytics.cashIn")} dataKey="inflows" fill="var(--color-primary)" radius={[3, 3, 0, 0]} />
                      <Bar name={t("inflows.analytics.cashOut")} dataKey="costs" fill="var(--color-muted-foreground)" radius={[3, 3, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              )}
            </ChartCard>
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <ChartCard title={t("inflows.analytics.charts.category")}>
              {data.by_category.length === 0 ? (
                <Empty label={t("inflows.analytics.noData")} />
              ) : (
                <DonutChart
                  data={data.by_category.map((c, i) => ({ key: c.name, value: c.total.minor, color: DONUT_COLORS[i % DONUT_COLORS.length] }))}
                  centerValue={moneyObject(data.invoiced.total)}
                  centerLabel={t("inflows.analytics.cards.invoiced")}
                />
              )}
            </ChartCard>

            <ChartCard title={t("inflows.analytics.charts.customerRevenue")}>
              {data.by_customer.length === 0 ? (
                <Empty label={t("inflows.analytics.noData")} />
              ) : (
                <BarList
                  rows={data.by_customer.slice(0, 6).map((c) => ({
                    label: c.company_name ?? t("inflows.noCustomer"),
                    value: c.total.minor,
                    display: moneyObject(c.total),
                  }))}
                />
              )}
            </ChartCard>
          </div>
        </>
      )}
    </div>
  );
}

function Sub({ main, sub }: { main: React.ReactNode; sub: string }) {
  return (
    <>
      {main}
      <span className="mt-0.5 block text-xs font-normal text-muted-foreground">{sub}</span>
    </>
  );
}

function Empty({ label }: { label: string }) {
  return <div className="flex h-[200px] items-center justify-center text-sm text-muted-foreground">{label}</div>;
}

function BarList({ rows }: { rows: { label: string; value: number; display: string }[] }) {
  const max = Math.max(1, ...rows.map((r) => r.value));
  return (
    <div className="space-y-3 py-2">
      {rows.map((r) => (
        <div key={r.label} className="space-y-1">
          <div className="flex items-center justify-between gap-3 text-sm">
            <span className="truncate">{r.label}</span>
            <span className="shrink-0 tabular-nums text-muted-foreground">{r.display}</span>
          </div>
          <div className="h-2 overflow-hidden rounded-full bg-muted">
            <div className="h-full rounded-full bg-primary" style={{ width: `${(r.value / max) * 100}%` }} />
          </div>
        </div>
      ))}
    </div>
  );
}
