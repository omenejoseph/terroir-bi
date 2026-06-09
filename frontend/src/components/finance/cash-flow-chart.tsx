"use client";

import * as React from "react";
import {
  Bar,
  ComposedChart,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import { useTranslation } from "@/i18n/context";
import type { CashFlowMonth } from "@/lib/types";

const AXIS_TICK = { fontSize: 11, fill: "var(--color-muted-foreground)" } as const;

interface Row {
  month: string;
  revenue: number;
  costs: number;
  net: number;
  projection: boolean;
}

/**
 * Combined cash-in / cash-out chart. Revenue + costs render as bars; the net
 * line is split into a solid (historical) and dashed (projected) segment so the
 * forecast is visually distinct. Money values are minor units.
 */
export function CashFlowChart({
  months,
  formatValue,
  formatAxis,
}: {
  months: CashFlowMonth[];
  formatValue: (n: number) => string;
  formatAxis: (n: number) => string;
}) {
  const { t } = useTranslation();

  const data: Row[] = months.map((m) => ({
    month: m.month,
    revenue: m.revenue.minor,
    costs: m.costs.minor,
    net: m.net.minor,
    projection: m.is_projection,
  }));

  // The dashed projected line joins the last historical point for continuity.
  const firstProjection = data.findIndex((d) => d.projection);
  const netActual = data.map((d, i) => (!d.projection || i === firstProjection ? d.net : null));
  const netProjected = data.map((d) => (d.projection ? d.net : null));
  const merged = data.map((d, i) => ({
    ...d,
    netActual: netActual[i],
    netProjected: netProjected[i],
  }));

  return (
    <div className="h-[280px] w-full">
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart data={merged} margin={{ top: 6, right: 6, left: -6, bottom: 0 }}>
          <XAxis dataKey="month" tick={AXIS_TICK} axisLine={false} tickLine={false} minTickGap={24} />
          <YAxis tick={AXIS_TICK} axisLine={false} tickLine={false} width={48} tickFormatter={formatAxis} />
          <Tooltip
            content={({ active, payload, label }) => {
              if (!active || !payload?.length) return null;
              return (
                <div className="rounded-lg border border-border bg-popover px-3 py-2 text-xs shadow-md">
                  <p className="mb-1 font-medium">{label}</p>
                  {payload
                    .filter((p) => p.value != null && p.dataKey !== "netProjected")
                    .map((p) => (
                      <p key={String(p.dataKey)} className="tabular-nums text-muted-foreground">
                        {p.name}: {formatValue(Number(p.value))}
                      </p>
                    ))}
                </div>
              );
            }}
            cursor={{ fill: "var(--color-muted)", opacity: 0.3 }}
          />
          <Bar dataKey="revenue" name={t("cashFlow.chart.revenue")} fill="#10b981" radius={[3, 3, 0, 0]} />
          <Bar dataKey="costs" name={t("cashFlow.chart.costs")} fill="#f43f5e" radius={[3, 3, 0, 0]} />
          <Line
            type="monotone"
            dataKey="netActual"
            name={t("cashFlow.chart.net")}
            stroke="var(--color-primary)"
            strokeWidth={2}
            dot={false}
            connectNulls
          />
          <Line
            type="monotone"
            dataKey="netProjected"
            name={`${t("cashFlow.chart.net")} ${t("cashFlow.chart.projection")}`}
            stroke="var(--color-primary)"
            strokeWidth={2}
            strokeDasharray="5 5"
            strokeOpacity={0.6}
            dot={false}
            connectNulls
          />
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  );
}
