"use client";

import * as React from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, Wallet, CalendarRange, History, Clock } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useCustomer, useCustomerOrderAnalytics } from "@/hooks/use-customers";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { StatCard } from "@/components/dashboard/stat-card";
import { CustomerForm } from "@/components/customers/customer-form";
import { CustomerConsignmentSection } from "@/components/customers/customer-consignment-section";
import { CustomPricingSection } from "@/components/customers/custom-pricing-section";
import { CustomerOrdersSection } from "@/components/customers/customer-orders-section";
import { OrderLinkSection } from "@/components/customers/order-link-section";

type DetailTab = "overview" | "pricing" | "orders";

export default function CustomerDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id;
  const { t } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();
  const { moneyObject, date } = useFormatters();

  const canFinancials = can("financials.view");
  const { data: customer, isLoading, isError } = useCustomer(id);
  const analyticsQ = useCustomerOrderAnalytics(id, canFinancials);
  const a = analyticsQ.data;

  const [tab, setTab] = React.useState<DetailTab>("overview");

  const tabs = [
    { value: "overview", label: t("customers.detailTabs.overview") },
    { value: "pricing", label: t("customers.detailTabs.pricing") },
    { value: "orders", label: t("customers.detailTabs.orders") },
  ];

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <Link
        href="/customers"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("customers.back")}
      </Link>

      {isLoading ? (
        <div className="flex justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : isError || !customer ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("customers.notFound")}
          </CardContent>
        </Card>
      ) : (
        <>
          <header className="flex flex-wrap items-center justify-between gap-3">
            <h1 className="text-2xl font-semibold tracking-tight">{customer.company_name}</h1>
            <Badge variant={customer.is_active ? "success" : "secondary"}>
              {customer.is_active ? t("common.status.active") : t("common.status.inactive")}
            </Badge>
          </header>

          {canFinancials && (
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <StatCard
                label={t("customers.orders.totalRevenue")}
                value={analyticsQ.isLoading ? "…" : moneyObject(a?.total_revenue ?? null)}
                icon={Wallet}
                accent="bg-emerald-500/10 text-emerald-500"
              />
              <StatCard
                label={t("customers.orders.thisYear")}
                value={analyticsQ.isLoading ? "…" : moneyObject(a?.this_year ?? null)}
                icon={CalendarRange}
                accent="bg-sky-500/10 text-sky-500"
                delayMs={50}
              />
              <StatCard
                label={t("customers.orders.lastYear")}
                value={analyticsQ.isLoading ? "…" : moneyObject(a?.last_year ?? null)}
                icon={History}
                accent="bg-slate-500/10 text-slate-500"
                delayMs={100}
              />
              <StatCard
                label={t("customers.orders.lastOrder")}
                value={
                  analyticsQ.isLoading
                    ? "…"
                    : a?.last_order_date
                      ? date(a.last_order_date)
                      : t("customers.orders.never")
                }
                icon={Clock}
                accent="bg-amber-500/10 text-amber-500"
                delayMs={150}
              />
            </div>
          )}

          <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as DetailTab)} />

          {tab === "overview" && (
            <div className="space-y-6">
              <CustomerForm
                customer={customer}
                onSaved={() => router.push("/customers")}
                onCancel={() => router.push("/customers")}
                onDeleted={() => router.push("/customers")}
              />
              {can("orders.view") && <CustomerConsignmentSection customerId={customer.id} />}
              {can("customers.tokens") && <OrderLinkSection customer={customer} />}
            </div>
          )}

          {tab === "pricing" && (
            <CustomPricingSection customerId={customer.id} canManage={can("pricing.manage")} />
          )}

          {tab === "orders" && (
            <CustomerOrdersSection customerId={customer.id} canViewFinancials={canFinancials} />
          )}
        </>
      )}
    </div>
  );
}
