"use client";

import * as React from "react";
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";

import { useTranslation } from "@/i18n/context";
import { ANALYSIS_PARAMETERS, type AnalysisParameter, type AnalysisTrendPoint } from "@/lib/types";

/**
 * Small-multiples line charts: one mini chart per measurement parameter that has
 * data, plotted over the analysis dates. Read-only visual of a lot's evolution.
 */
export function ParameterTrends({ points }: { points: AnalysisTrendPoint[] }) {
  const { t } = useTranslation();

  const data = React.useMemo<(AnalysisTrendPoint & { label: string })[]>(
    () =>
      points.map((p) => ({
        ...p,
        label: p.date ? new Date(p.date).toLocaleDateString() : "",
      })),
    [points],
  );

  // Only chart parameters that have at least one numeric reading.
  const active = React.useMemo(
    () =>
      ANALYSIS_PARAMETERS.filter((param) =>
        data.some((d) => typeof d[param] === "number" && d[param] !== null),
      ),
    [data],
  );

  if (points.length === 0 || active.length === 0) {
    return <p className="text-sm text-muted-foreground">{t("cellar.analysis.noData")}</p>;
  }

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {active.map((param) => (
        <div key={param} className="rounded-lg border border-border p-3">
          <div className="mb-1 text-xs font-medium text-muted-foreground">
            {t(`cellar.analysis.param.${param as AnalysisParameter}`)}
          </div>
          <ResponsiveContainer width="100%" height={120}>
            <LineChart data={data} margin={{ top: 4, right: 8, bottom: 0, left: -20 }}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
              <XAxis dataKey="label" tick={{ fontSize: 10 }} hide={data.length > 6} />
              <YAxis tick={{ fontSize: 10 }} width={32} domain={["auto", "auto"]} />
              <Tooltip />
              <Line type="monotone" dataKey={param} stroke="#7c3aed" strokeWidth={2} dot={{ r: 2 }} connectNulls />
            </LineChart>
          </ResponsiveContainer>
        </div>
      ))}
    </div>
  );
}
