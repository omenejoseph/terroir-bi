"use client";

import * as React from "react";
import { ArrowDown, ArrowUp } from "lucide-react";

import { cn } from "@/lib/utils";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { DashboardSummary } from "@/lib/types";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

/** Period-over-period delta % vs the prior window; null when there's no base. */
function deltaPct(current: number, previous: number | null): number | null {
  if (previous == null || previous === 0) return null;
  return ((current - previous) / Math.abs(previous)) * 100;
}

/** Compact up/down trend pill, matching the revenue summary cards. */
function TrendPill({ delta }: { delta: number | null }) {
  const up = delta != null && delta >= 0;
  const down = delta != null && delta < 0;
  return (
    <span
      className={cn(
        "inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-medium",
        up && "bg-success/10 text-success",
        down && "bg-destructive/10 text-destructive",
        delta == null && "bg-muted text-muted-foreground",
      )}
    >
      {up && <ArrowUp className="size-3" />}
      {down && <ArrowDown className="size-3" />}
      {delta != null ? `${delta > 0 ? "+" : ""}${delta.toFixed(1)}%` : "—"}
    </span>
  );
}

const CHANNELS = [
  { key: "wholesale", type: "WHOLESALE", dot: "bg-blue-500", note: null },
  { key: "retail", type: "RETAIL", dot: "bg-green-500", note: null },
  { key: "agency", type: "AGENCY", dot: "bg-purple-500", note: "paidOnly" },
  { key: "shipshop", type: "SHIPSHOP", dot: "bg-amber-500", note: "consignment" },
  { key: "other", type: "OTHER", dot: "bg-zinc-400", note: null },
] as const;

/**
 * Revenue split by customer sales channel for the selected period. A labelled
 * amount per channel plus a thin share bar; channels are derived from the
 * customer's type (wholesale / retail / agency / shipshop / other).
 */
export function RevenueChannels({
  data,
  delayMs = 0,
}: {
  data: DashboardSummary["revenue_by_channel"];
  delayMs?: number;
}) {
  const { t } = useTranslation();
  const { money2 } = useFormatters();
  const total = data.total.current;

  return (
    <Card style={{ animationDelay: `${delayMs}ms` }} className="animate-fade-up border-border/60">
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-semibold">{t("dashboard.channels.title")}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3 pt-0">
        {/* Total across all channels, with the trend vs the prior window. */}
        <div className="border-b border-border pb-3">
          <div className="flex items-center justify-between gap-2">
            <span className="text-sm font-medium">{t("dashboard.summary.total")}</span>
            <span className="flex items-center gap-2">
              <TrendPill delta={deltaPct(total, data.total.previous)} />
              <span className="text-lg font-bold tabular-nums">{money2(total)}</span>
            </span>
          </div>
          <p className="text-xs text-muted-foreground">{t("dashboard.summary.allSources")}</p>
        </div>

        {CHANNELS.map(({ key, type, dot, note }) => {
          const value = data[key].current;
          const share = total > 0 ? Math.round((value / total) * 100) : 0;
          return (
            <div key={key} className="space-y-1">
              <div className="flex items-center justify-between gap-2 text-sm">
                <span className="flex items-center gap-2">
                  <span className={cn("size-2.5 rounded-full", dot)} />
                  {t(`customers.type.${type}`)}
                  <span className="text-xs tabular-nums text-muted-foreground">{share}%</span>
                </span>
                <span className="flex items-center gap-2">
                  <TrendPill delta={deltaPct(value, data[key].previous)} />
                  <span className="font-medium tabular-nums">{money2(value)}</span>
                </span>
              </div>
              <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                <div className={cn("h-full rounded-full", dot)} style={{ width: `${share}%` }} />
              </div>
              {note && (
                <p className="text-[11px] text-muted-foreground">{t(`dashboard.channels.${note}`)}</p>
              )}
            </div>
          );
        })}
      </CardContent>
    </Card>
  );
}
