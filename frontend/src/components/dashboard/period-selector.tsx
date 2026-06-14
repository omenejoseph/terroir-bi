"use client";

import * as React from "react";

import { cn } from "@/lib/utils";
import { useTranslation } from "@/i18n/context";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  DASHBOARD_PERIODS,
  type DashboardPeriod,
  formatPeriodRange,
  resolvePeriodWindow,
  toISODate,
} from "@/lib/dashboard-period";

const LABEL_KEY: Record<Exclude<DashboardPeriod, "custom">, string> = {
  today: "dashboard.period.today",
  yesterday: "dashboard.period.yesterday",
  mtd: "dashboard.period.mtd",
  qtd: "dashboard.period.qtd",
  ytd: "dashboard.period.ytd",
  month: "dashboard.period.thisMonth",
  "last-month": "dashboard.period.lastMonth",
  "last-year": "dashboard.period.lastYear",
  all: "dashboard.period.all",
};

/**
 * Period picker: a row of preset chips plus a custom-range panel. The caller owns
 * the selected period; the resolved date range is shown beneath the chips.
 */
export function PeriodSelector({
  period,
  customFrom,
  customTo,
  onChange,
  className,
}: {
  period: DashboardPeriod;
  customFrom?: string;
  customTo?: string;
  onChange: (period: DashboardPeriod, from?: string, to?: string) => void;
  className?: string;
}) {
  const { t, locale } = useTranslation();
  const [customOpen, setCustomOpen] = React.useState(period === "custom");

  const todayIso = toISODate(new Date());
  const [draftFrom, setDraftFrom] = React.useState(customFrom ?? todayIso);
  const [draftTo, setDraftTo] = React.useState(customTo ?? todayIso);

  const rangeLabel = formatPeriodRange(resolvePeriodWindow(period, customFrom, customTo), locale);

  const chip = (active: boolean) =>
    cn(
      "rounded-full px-3 py-1 text-xs font-medium transition-colors",
      active
        ? "bg-primary text-primary-foreground"
        : "bg-muted text-muted-foreground hover:text-foreground",
    );

  return (
    <div className={cn("space-y-2", className)}>
      <div className="flex flex-wrap gap-1.5">
        {DASHBOARD_PERIODS.map((p) => (
          <button
            key={p}
            type="button"
            aria-pressed={period === p}
            onClick={() => {
              setCustomOpen(false);
              onChange(p);
            }}
            className={chip(period === p)}
          >
            {t(LABEL_KEY[p])}
          </button>
        ))}
        <button
          type="button"
          aria-pressed={period === "custom"}
          onClick={() => setCustomOpen((open) => !open)}
          className={chip(period === "custom")}
        >
          {t("dashboard.period.custom")}
        </button>
      </div>

      {customOpen && (
        <div className="flex flex-wrap items-end gap-2 rounded-lg border border-border/60 bg-card p-3">
          <label className="flex flex-col gap-1 text-xs text-muted-foreground">
            {t("dashboard.period.from")}
            <Input
              type="date"
              value={draftFrom}
              max={draftTo}
              onChange={(e) => setDraftFrom(e.target.value)}
              className="h-8 w-auto"
            />
          </label>
          <label className="flex flex-col gap-1 text-xs text-muted-foreground">
            {t("dashboard.period.to")}
            <Input
              type="date"
              value={draftTo}
              min={draftFrom}
              onChange={(e) => setDraftTo(e.target.value)}
              className="h-8 w-auto"
            />
          </label>
          <Button
            size="sm"
            onClick={() => {
              onChange("custom", draftFrom, draftTo);
              setCustomOpen(false);
            }}
          >
            {t("dashboard.period.apply")}
          </Button>
        </div>
      )}

      {rangeLabel && <p className="text-xs text-muted-foreground">{rangeLabel}</p>}
    </div>
  );
}
