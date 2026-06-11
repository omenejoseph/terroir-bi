"use client";

import * as React from "react";
import Link from "next/link";
import { ArrowLeft } from "lucide-react";

import { useCustomerAnalytics } from "@/hooks/use-customers";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";
import { DashboardBodySkeleton } from "@/components/skeletons";

export default function CustomerAnalyticsPage() {
  const { t } = useTranslation();
  const { number, moneyObject, date, relativeDays } = useFormatters();
  const { data, isLoading } = useCustomerAnalytics();

  return (
    <div className="space-y-6">
      <Link
        href="/customers"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("customers.title")}
      </Link>

      <h1 className="text-2xl font-semibold tracking-tight">{t("customers.analytics.title")}</h1>

      {isLoading || !data ? (
        <DashboardBodySkeleton />
      ) : (
        <>
          {/* Summary cards */}
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <SummaryCard
              label={t("customers.analytics.activeCustomers")}
              value={number(data.summary.active_customers)}
              sub={t("customers.analytics.activeCustomersSub")}
            />
            <SummaryCard
              label={t("customers.analytics.revenue12m")}
              value={moneyObject(data.summary.revenue_12m)}
              sub={t("customers.analytics.revenue12mSub")}
            />
            <SummaryCard
              label={t("customers.analytics.topCustomer")}
              value={data.summary.top_customer?.company_name ?? "—"}
              sub={
                data.summary.top_customer
                  ? moneyObject(data.summary.top_customer.revenue_12m)
                  : undefined
              }
            />
          </div>

          {/* Per-customer table */}
          <Card>
            <CardContent className="pt-6">
              {data.customers.length === 0 ? (
                <p className="py-6 text-center text-sm text-muted-foreground">
                  {t("customers.analytics.empty")}
                </p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="border-b border-border text-left text-xs uppercase text-muted-foreground">
                      <tr>
                        <th className="py-2 pr-3 font-medium">{t("customers.analytics.cols.customer")}</th>
                        <th className="py-2 pr-3 text-right font-medium">{t("customers.analytics.cols.revenue12m")}</th>
                        <th className="py-2 pr-3 text-right font-medium">{t("customers.analytics.cols.allTime")}</th>
                        <th className="py-2 pr-3 text-right font-medium">{t("customers.analytics.cols.orders12m")}</th>
                        <th className="py-2 pr-3 text-right font-medium">{t("customers.analytics.cols.avgOrder")}</th>
                        <th className="py-2 pr-3 font-medium">{t("customers.analytics.cols.lastOrder")}</th>
                        <th className="py-2 pr-3 font-medium">{t("customers.analytics.cols.typicalGap")}</th>
                        <th className="py-2 font-medium">{t("customers.analytics.cols.expectedBy")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.customers.map((c) => (
                        <tr key={c.customer_id} className="border-b border-border/60 last:border-0">
                          <td className="py-2 pr-3">
                            <span className="font-medium">{c.company_name}</span>
                            {c.contact_name && (
                              <span className="block text-xs text-muted-foreground">{c.contact_name}</span>
                            )}
                          </td>
                          <td className="py-2 pr-3 text-right tabular-nums">{moneyObject(c.revenue_12m)}</td>
                          <td className="py-2 pr-3 text-right tabular-nums text-muted-foreground">
                            {moneyObject(c.revenue_all_time)}
                          </td>
                          <td className="py-2 pr-3 text-right tabular-nums">{number(c.order_count_12m)}</td>
                          <td className="py-2 pr-3 text-right tabular-nums">{moneyObject(c.avg_order_value)}</td>
                          <td className="py-2 pr-3 text-muted-foreground">
                            {c.days_since_last_order != null ? relativeDays(c.days_since_last_order) : "—"}
                          </td>
                          <td className="py-2 pr-3 text-muted-foreground">
                            {c.median_gap_days != null
                              ? t("customers.analytics.gapDays", { count: number(Math.round(c.median_gap_days)) })
                              : "—"}
                          </td>
                          <td className="py-2 text-muted-foreground">
                            {c.expected_next_order_date ? date(c.expected_next_order_date) : "—"}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}

function SummaryCard({
  label,
  value,
  sub,
}: {
  label: string;
  value: React.ReactNode;
  sub?: React.ReactNode;
}) {
  return (
    <Card className="animate-fade-up border-border/60">
      <CardContent className="space-y-1 pt-5">
        <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className="truncate text-2xl font-semibold tabular-nums">{value}</p>
        {sub && <p className="text-xs text-muted-foreground">{sub}</p>}
      </CardContent>
    </Card>
  );
}
