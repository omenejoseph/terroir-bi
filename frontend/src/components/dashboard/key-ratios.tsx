"use client";

import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { cn } from "@/lib/utils";
import type { DashboardSummary } from "@/lib/types";
import { ChartCard } from "@/components/dashboard/charts";
import { InfoHint } from "@/components/ui/info-hint";

type Ratios = DashboardSummary["key_ratios"];

/** One ratio's display + benchmark colouring, mirroring the prototype config. */
type RatioCfg = {
  key: string; // i18n slug under dashboard.ratios
  value: number | null; // numeric value used for colour banding (null for trend/money)
  display: string; // formatted value
  benchmark: string | null; // benchmark line (null → "Trend")
  trend?: boolean;
  invert?: boolean; // lower is better (cost ratios)
  greenMin?: number;
  amberMin?: number;
  greenMax?: number;
  amberMax?: number;
  amount?: string | null; // extra sub-line (COGS €)
};

/** Green / amber / red banding against the benchmark; trend & unknown stay neutral. */
function ratioColor(cfg: RatioCfg): string {
  if (cfg.trend) return "text-foreground";
  if (cfg.value == null) return "text-muted-foreground";
  if (cfg.invert) {
    if (cfg.greenMax !== undefined && cfg.value <= cfg.greenMax) return "text-green-600 dark:text-green-400";
    if (cfg.amberMax !== undefined && cfg.value <= cfg.amberMax) return "text-amber-600 dark:text-amber-400";
    return "text-red-600 dark:text-red-400";
  }
  if (cfg.greenMin !== undefined && cfg.value >= cfg.greenMin) return "text-green-600 dark:text-green-400";
  if (cfg.amberMin !== undefined && cfg.value >= cfg.amberMin) return "text-amber-600 dark:text-amber-400";
  return "text-red-600 dark:text-red-400";
}

/**
 * Headline financial KPIs with benchmark colouring, mirroring the prototype's
 * key-ratios row: each value is banded green/amber/red against its target, the
 * ⓘ explains the metric + recommendation, and a missing input reads as "Data
 * incomplete" rather than a misleading 0%.
 */
export function KeyRatios({ data, delayMs = 0 }: { data: Ratios; delayMs?: number }) {
  const { t } = useTranslation();
  const { number, moneyObject } = useFormatters();

  const pct = (v: number | null) => (v != null ? `${number(v)}%` : "—");
  const times = (v: number | null) => (v != null ? `${number(v)}×` : "—");
  const money = (m: Ratios["cogs_amount"]) => (m ? moneyObject(m) : "—");

  const items: RatioCfg[] = [
    {
      key: "dtc",
      value: data.dtc_revenue_pct,
      display: pct(data.dtc_revenue_pct),
      benchmark: t("dashboard.ratios.dtcTarget"),
      greenMin: 50,
      amberMin: 30,
    },
    {
      key: "operatingMargin",
      value: data.operating_margin_pct,
      display: pct(data.operating_margin_pct),
      benchmark: t("dashboard.ratios.operatingMarginTarget"),
      greenMin: 20,
      amberMin: 10,
    },
    {
      key: "employeeCost",
      value: data.employee_cost_pct,
      display: pct(data.employee_cost_pct),
      benchmark: t("dashboard.ratios.employeeCostTarget"),
      invert: true,
      greenMax: 25,
      amberMax: 35,
    },
    {
      key: "marketingCost",
      value: data.marketing_cost_pct,
      display: pct(data.marketing_cost_pct),
      benchmark: t("dashboard.ratios.marketingCostTarget"),
      invert: true,
      greenMax: 15,
      amberMax: 20,
    },
    {
      key: "cogs",
      value: data.cogs_pct,
      display: pct(data.cogs_pct),
      benchmark: t("dashboard.ratios.cogsTarget"),
      invert: true,
      greenMax: 40,
      amberMax: 50,
      amount: data.cogs_amount ? money(data.cogs_amount) : null,
    },
    {
      key: "revPerEmployee",
      value: null,
      display: money(data.revenue_per_employee),
      benchmark: null,
      trend: true,
    },
    {
      key: "avgOrderValue",
      value: null,
      display: money(data.avg_order_value),
      benchmark: null,
      trend: true,
    },
    {
      key: "inventoryTurnover",
      value: data.inventory_turnover,
      display: times(data.inventory_turnover),
      benchmark: t("dashboard.ratios.inventoryTurnoverTarget"),
      greenMin: 1,
      amberMin: 0.5,
    },
  ];

  return (
    <ChartCard title={t("dashboard.ratios.title")} delayMs={delayMs}>
      <div className="grid grid-cols-2 gap-x-4 gap-y-5 sm:grid-cols-4">
        {items.map((it) => {
          const incomplete = !it.trend && it.value == null;
          return (
            <div key={it.key} className="space-y-0.5">
              <p className="flex items-center gap-1 text-xs text-muted-foreground">
                {t(`dashboard.ratios.${it.key}`)}
                <InfoHint
                  text={t(`dashboard.ratios.hints.${it.key}`)}
                  detail={t(`dashboard.ratios.recs.${it.key}`)}
                />
              </p>
              <p className={cn("text-lg font-bold tabular-nums", ratioColor(it))}>{it.display}</p>
              {it.amount && <p className="text-[11px] tabular-nums text-muted-foreground">{it.amount}</p>}
              {incomplete ? (
                <p className="text-[11px] italic text-amber-600 dark:text-amber-400">
                  {t("dashboard.ratios.dataIncomplete")}
                </p>
              ) : (
                <p className="text-[11px] text-muted-foreground">
                  {it.trend ? t("dashboard.ratios.trend") : it.benchmark}
                </p>
              )}
            </div>
          );
        })}
      </div>
    </ChartCard>
  );
}
