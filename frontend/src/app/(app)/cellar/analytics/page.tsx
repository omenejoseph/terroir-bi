"use client";

import { Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";

import { useTranslation } from "@/i18n/context";
import { useCellarAnalytics } from "@/hooks/use-cellar";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { CellarPageHeader } from "@/components/cellar/cellar-page-header";

const COLORS = ["#7c3aed", "#2563eb", "#16a34a", "#d97706", "#dc2626", "#0891b2", "#c026d3", "#65a30d"];

export default function CellarAnalyticsPage() {
  const { t } = useTranslation();
  const { data, isLoading } = useCellarAnalytics();

  return (
    <div className="space-y-6">
      <CellarPageHeader title={t("cellar.analyticsPage.title")} />
      {isLoading || !data ? (
        <div className="flex h-32 items-center justify-center">
          <Spinner />
        </div>
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          <ChartCard title={t("cellar.analyticsPage.byVintage")}>
            <ResponsiveContainer width="100%" height={240}>
              <BarChart data={data.by_vintage}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                <YAxis tick={{ fontSize: 11 }} />
                <Tooltip />
                <Bar dataKey="volume" fill="#7c3aed" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </ChartCard>

          <ChartCard title={t("cellar.analyticsPage.byType")}>
            <ResponsiveContainer width="100%" height={240}>
              <PieChart>
                <Pie data={data.by_type} dataKey="volume" nameKey="label" outerRadius={90} label>
                  {data.by_type.map((_, i) => (
                    <Cell key={i} fill={COLORS[i % COLORS.length]} />
                  ))}
                </Pie>
                <Tooltip />
              </PieChart>
            </ResponsiveContainer>
          </ChartCard>

          <ChartCard title={t("cellar.analyticsPage.byGrape")}>
            <ResponsiveContainer width="100%" height={240}>
              <BarChart data={data.by_grape} layout="vertical">
                <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                <XAxis type="number" tick={{ fontSize: 11 }} />
                <YAxis type="category" dataKey="label" width={110} tick={{ fontSize: 11 }} />
                <Tooltip />
                <Bar dataKey="volume" fill="#2563eb" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </ChartCard>

          <ChartCard title={t("cellar.analyticsPage.pipeline")}>
            <ResponsiveContainer width="100%" height={240}>
              <BarChart data={data.pipeline}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                <XAxis dataKey="status" tick={{ fontSize: 11 }} />
                <YAxis tick={{ fontSize: 11 }} />
                <Tooltip />
                <Bar dataKey="volume" fill="#16a34a" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </ChartCard>
        </div>
      )}
    </div>
  );
}

function ChartCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardContent className="p-4">
        <h3 className="mb-3 text-sm font-semibold">{title}</h3>
        {children}
      </CardContent>
    </Card>
  );
}
