"use client";

import * as React from "react";
import Link from "next/link";
import { Check, ChevronDown, ChevronUp, Loader2, Radar } from "lucide-react";

import { cn } from "@/lib/utils";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { useMarkContacted, useReorderRadar } from "@/hooks/use-customers";
import type { ReorderRadarRow } from "@/lib/types";
import { Card, CardContent, CardHeader } from "@/components/ui/card";

const COLLAPSED_COUNT = 5;

const STATUS_STYLES: Record<
  ReorderRadarRow["status"],
  { labelKey: string; dot: string; chip: string }
> = {
  due: {
    labelKey: "dashboard.reorderRadar.due",
    dot: "bg-amber-500",
    chip: "border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/40 dark:bg-amber-950/40 dark:text-amber-400",
  },
  overdue: {
    labelKey: "dashboard.reorderRadar.overdue",
    dot: "bg-orange-500",
    chip: "border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-900/40 dark:bg-orange-950/40 dark:text-orange-400",
  },
  at_risk: {
    labelKey: "dashboard.reorderRadar.atRisk",
    dot: "bg-red-500",
    chip: "border-red-200 bg-red-50 text-red-700 dark:border-red-900/40 dark:bg-red-950/40 dark:text-red-400",
  },
};

/**
 * Reorder radar: flags accounts that have gone quiet relative to their own usual
 * ordering cadence (due / overdue / at-risk), ranked by value-weighted urgency.
 * Self-contained — fetches its own data and offers a "contacted" mute action.
 */
export function ReorderRadar({ delayMs = 0 }: { delayMs?: number }) {
  const { t } = useTranslation();
  const { moneyObject } = useFormatters();
  const { data, isLoading } = useReorderRadar();
  const markContacted = useMarkContacted();
  const [expanded, setExpanded] = React.useState(false);

  if (isLoading) return null;

  const rows = data?.rows ?? [];
  const counts = data?.counts ?? { due: 0, overdue: 0, at_risk: 0 };

  if (rows.length === 0) {
    return (
      <Card style={{ animationDelay: `${delayMs}ms` }} className="animate-fade-up border-border/60">
        <CardContent className="flex items-center gap-3 py-4">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-success/10 text-success">
            <Check className="size-4" />
          </span>
          <div>
            <p className="text-sm font-medium">
              {t("dashboard.reorderRadar.title")} — {t("dashboard.reorderRadar.allClear")}
            </p>
            <p className="text-xs text-muted-foreground">{t("dashboard.reorderRadar.allClearHint")}</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  const visible = expanded ? rows : rows.slice(0, COLLAPSED_COUNT);

  return (
    <Card style={{ animationDelay: `${delayMs}ms` }} className="animate-fade-up border-border/60">
      <CardHeader className="flex flex-row items-start justify-between gap-2 pb-3">
        <div className="flex items-center gap-2">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-amber-600 dark:text-amber-400">
            <Radar className="size-4" />
          </span>
          <div>
            <p className="text-sm font-semibold leading-none">{t("dashboard.reorderRadar.title")}</p>
            <p className="mt-1 text-xs text-muted-foreground">{t("dashboard.reorderRadar.subtitle")}</p>
          </div>
        </div>
        <div className="flex flex-wrap items-center justify-end gap-1.5">
          {counts.due > 0 && (
            <span className={cn("rounded-full border px-2 py-0.5 text-[11px]", STATUS_STYLES.due.chip)}>
              {counts.due} {t("dashboard.reorderRadar.due")}
            </span>
          )}
          {counts.overdue > 0 && (
            <span className={cn("rounded-full border px-2 py-0.5 text-[11px]", STATUS_STYLES.overdue.chip)}>
              {counts.overdue} {t("dashboard.reorderRadar.overdue")}
            </span>
          )}
          {counts.at_risk > 0 && (
            <span className={cn("rounded-full border px-2 py-0.5 text-[11px]", STATUS_STYLES.at_risk.chip)}>
              {counts.at_risk} {t("dashboard.reorderRadar.atRisk")}
            </span>
          )}
        </div>
      </CardHeader>
      <CardContent className="pt-0">
        <ul className="divide-y divide-border">
          {visible.map((r) => {
            const style = STATUS_STYLES[r.status];
            const busy = markContacted.isPending && markContacted.variables?.id === r.customer_id;
            return (
              <li key={r.customer_id} className="flex items-center gap-3 py-2.5">
                <span className={cn("size-2 shrink-0 rounded-full", style.dot)} aria-hidden />
                <Link href={`/customers/${r.customer_id}`} className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium hover:underline">{r.company_name}</p>
                  <p className="text-xs text-muted-foreground">
                    {t("dashboard.reorderRadar.lastOrder", { days: Math.round(r.days_since_last) })}
                    {" · "}
                    {t("dashboard.reorderRadar.usuallyEvery", { days: Math.round(r.median_gap_days) })}
                  </p>
                </Link>
                <div className="shrink-0 text-right">
                  <span className={cn("rounded-full border px-2 py-0.5 text-[11px]", style.chip)}>
                    {t(style.labelKey)}
                  </span>
                  <p className="mt-1 text-xs tabular-nums text-muted-foreground">
                    {t("dashboard.reorderRadar.perOrder", { value: moneyObject(r.avg_order_value) })}
                  </p>
                </div>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => markContacted.mutate({ id: r.customer_id, contacted: true })}
                  className="inline-flex shrink-0 items-center gap-1 rounded-md border px-2 py-1 text-[11px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-50"
                >
                  {busy ? <Loader2 className="size-3 animate-spin" /> : <Check className="size-3" />}
                  <span className="hidden sm:inline">{t("dashboard.reorderRadar.contacted")}</span>
                </button>
              </li>
            );
          })}
        </ul>
        {rows.length > COLLAPSED_COUNT && (
          <button
            type="button"
            onClick={() => setExpanded((v) => !v)}
            className="mt-2 flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
          >
            {expanded ? (
              <>
                <ChevronUp className="size-3" /> {t("dashboard.reorderRadar.showFewer")}
              </>
            ) : (
              <>
                <ChevronDown className="size-3" /> {t("dashboard.reorderRadar.showAll", { count: rows.length })}
              </>
            )}
          </button>
        )}
      </CardContent>
    </Card>
  );
}
