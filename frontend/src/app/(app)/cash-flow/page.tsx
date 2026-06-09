"use client";

import * as React from "react";
import Link from "next/link";

import { ApiError } from "@/lib/api/client";
import { useArAging, useCashFlow } from "@/hooks/use-finance";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { ArAging, CashFlow } from "@/lib/types";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { ChartCard } from "@/components/dashboard/charts";
import { CashFlowChart } from "@/components/finance/cash-flow-chart";

type ViewTab = "forecast" | "receivables";

export default function CashFlowPage() {
  const { t } = useTranslation();
  const [tab, setTab] = React.useState<ViewTab>("forecast");

  const cashFlowQ = useCashFlow();
  const agingQ = useArAging();

  const tabs = [
    { value: "forecast", label: t("cashFlow.tabs.forecast") },
    { value: "receivables", label: t("cashFlow.tabs.receivables") },
  ];

  const isLoading = tab === "forecast" ? cashFlowQ.isLoading : agingQ.isLoading;
  const isError = tab === "forecast" ? cashFlowQ.isError : agingQ.isError;
  const error = tab === "forecast" ? cashFlowQ.error : agingQ.error;

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("cashFlow.title")}</h1>
        <p className="text-sm text-muted-foreground">{t("cashFlow.subtitle")}</p>
      </header>

      <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as ViewTab)} />

      {isLoading && (
        <div className="flex items-center justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      )}

      {isError && (
        <Card>
          <CardContent className="py-8 text-center text-sm text-destructive">
            {error instanceof ApiError && error.status === 403
              ? t("cashFlow.errorForbidden")
              : t("cashFlow.errorGeneric")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && tab === "forecast" && cashFlowQ.data && (
        <ForecastView data={cashFlowQ.data} />
      )}

      {!isLoading && !isError && tab === "receivables" && agingQ.data && (
        <ReceivablesView data={agingQ.data} />
      )}
    </div>
  );
}

function ForecastView({ data }: { data: CashFlow }) {
  const { t } = useTranslation();
  const { moneyObject, moneyAxis, money2 } = useFormatters();

  const months = [...data.historical, ...data.forecast];

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <SummaryCard label={t("cashFlow.summary.avgRevenue")} value={moneyObject(data.summary.avg_monthly_revenue)} />
        <SummaryCard label={t("cashFlow.summary.avgCosts")} value={moneyObject(data.summary.avg_monthly_costs)} />
        <SummaryCard label={t("cashFlow.summary.avgNet")} value={moneyObject(data.summary.avg_monthly_net)} />
        <SummaryCard label={t("cashFlow.summary.growth")} value={`${data.summary.revenue_growth_percent}%`} />
      </div>

      <ChartCard title={t("cashFlow.chart.title")}>
        <CashFlowChart
          months={months}
          formatValue={(n) => money2(n)}
          formatAxis={(n) => moneyAxis(n)}
        />
      </ChartCard>

      <Card>
        <CardContent className="pt-6">
          <h3 className="text-sm font-semibold">{t("cashFlow.pending.title")}</h3>
          <div className="mt-3 grid gap-4 sm:grid-cols-3">
            <PendingItem
              label={t("cashFlow.pending.receivable")}
              value={moneyObject(data.pending.receivable)}
              count={t("cashFlow.pending.countSuffix", { count: data.pending.receivable_count })}
            />
            <PendingItem
              label={t("cashFlow.pending.payable")}
              value={moneyObject(data.pending.payable)}
              count={t("cashFlow.pending.countSuffix", { count: data.pending.payable_count })}
            />
            <PendingItem label={t("cashFlow.pending.net")} value={moneyObject(data.pending.net)} />
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

function ReceivablesView({ data }: { data: ArAging }) {
  const { t } = useTranslation();
  const { moneyObject } = useFormatters();

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <SummaryCard
          label={t("cashFlow.aging.totalOutstanding")}
          value={moneyObject(data.total_outstanding)}
        />
        <SummaryCard label={t("cashFlow.aging.current")} value={moneyObject(data.buckets.current)} />
        <SummaryCard label={t("cashFlow.aging.b31_60")} value={moneyObject(data.buckets["31_60"])} />
        <SummaryCard label={t("cashFlow.aging.b61_90")} value={moneyObject(data.buckets["61_90"])} />
        <SummaryCard label={t("cashFlow.aging.b90_plus")} value={moneyObject(data.buckets["90_plus"])} />
      </div>

      <Card>
        <CardContent className="space-y-3 pt-6">
          <h3 className="text-sm font-semibold">{t("cashFlow.aging.byCustomer")}</h3>
          {data.by_customer.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("cashFlow.aging.empty")}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                  <tr>
                    <th className="py-2 pr-3 font-medium">{t("cashFlow.aging.customer")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("cashFlow.aging.orders")}</th>
                    <th className="py-2 text-right font-medium">{t("cashFlow.aging.outstanding")}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.by_customer.map((row) => (
                    <tr key={row.customer_id} className="border-b border-border last:border-0">
                      <td className="py-2.5 pr-3">
                        <Link
                          href={`/customers/${row.customer_id}`}
                          className="font-medium text-primary hover:underline"
                        >
                          {row.company_name ?? row.customer_id}
                        </Link>
                      </td>
                      <td className="py-2.5 pr-3 text-right tabular-nums">{row.orders}</td>
                      <td className="py-2.5 text-right tabular-nums">{moneyObject(row.outstanding)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
  return (
    <Card>
      <CardContent className="pt-6">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className="mt-1 text-xl font-semibold tabular-nums">{value}</p>
      </CardContent>
    </Card>
  );
}

function PendingItem({ label, value, count }: { label: string; value: string; count?: string }) {
  return (
    <div>
      <p className="text-sm text-muted-foreground">{label}</p>
      <p className="mt-1 text-lg font-semibold tabular-nums">{value}</p>
      {count && <p className="text-xs text-muted-foreground">{count}</p>}
    </div>
  );
}
