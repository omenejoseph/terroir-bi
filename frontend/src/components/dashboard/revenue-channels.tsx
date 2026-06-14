"use client";

import * as React from "react";

import { cn } from "@/lib/utils";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { DashboardSummary } from "@/lib/types";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

const CHANNELS = [
  { key: "wholesale", type: "WHOLESALE", dot: "bg-blue-500" },
  { key: "retail", type: "RETAIL", dot: "bg-green-500" },
  { key: "agency", type: "AGENCY", dot: "bg-purple-500" },
  { key: "shipshop", type: "SHIPSHOP", dot: "bg-amber-500" },
  { key: "other", type: "OTHER", dot: "bg-zinc-400" },
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
  const total = data.total;

  return (
    <Card style={{ animationDelay: `${delayMs}ms` }} className="animate-fade-up border-border/60">
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-semibold">{t("dashboard.channels.title")}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3 pt-0">
        {CHANNELS.map(({ key, type, dot }) => {
          const value = data[key];
          const share = total > 0 ? Math.round((value / total) * 100) : 0;
          return (
            <div key={key} className="space-y-1">
              <div className="flex items-center justify-between gap-2 text-sm">
                <span className="flex items-center gap-2">
                  <span className={cn("size-2.5 rounded-full", dot)} />
                  {t(`customers.type.${type}`)}
                </span>
                <span className="font-medium tabular-nums">{money2(value)}</span>
              </div>
              <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                <div className={cn("h-full rounded-full", dot)} style={{ width: `${share}%` }} />
              </div>
            </div>
          );
        })}
      </CardContent>
    </Card>
  );
}
