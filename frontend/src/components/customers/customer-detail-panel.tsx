"use client";

import * as React from "react";
import { CalendarRange, Clock, History, Pencil, Power, Wallet } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useCustomerOrderAnalytics, useUpdateCustomer } from "@/hooks/use-customers";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Customer } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { useConfirm } from "@/components/ui/confirm";
import { StatCard } from "@/components/dashboard/stat-card";
import { CustomerConsignmentSection } from "@/components/customers/customer-consignment-section";
import { CustomerDetails } from "@/components/customers/customer-details";
import { CustomerForm } from "@/components/customers/customer-form";
import { CustomerOrdersSection } from "@/components/customers/customer-orders-section";
import { CustomPricingSection } from "@/components/customers/custom-pricing-section";
import { OrderLinkSection } from "@/components/customers/order-link-section";

type DetailTab = "overview" | "pricing" | "orders";

/**
 * The full customer "360" — summary revenue cards + Overview / Custom pricing /
 * Orders tabs. Shared by the inline expand on the list and the detail page so
 * both surfaces show the same thing.
 */
export function CustomerDetailPanel({
  customer,
  onDeleted,
}: {
  customer: Customer;
  onDeleted?: () => void;
}) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject, date } = useFormatters();
  const confirm = useConfirm();
  const update = useUpdateCustomer();

  const canManage = can("customers.manage");
  const canFinancials = can("financials.view");
  const analyticsQ = useCustomerOrderAnalytics(customer.id, canFinancials);
  const a = analyticsQ.data;

  const [tab, setTab] = React.useState<DetailTab>("overview");
  const [editing, setEditing] = React.useState(false);

  async function toggleActive() {
    const deactivating = customer.is_active;
    const ok = await confirm({
      title: deactivating ? t("customers.deactivate.title") : t("customers.activate.title"),
      description: deactivating
        ? t("customers.deactivate.body", { name: customer.company_name })
        : t("customers.activate.body", { name: customer.company_name }),
      confirmLabel: deactivating ? t("customers.deactivate.action") : t("customers.activate.action"),
      tone: "danger",
    });
    if (!ok) return;
    await update.mutateAsync({ id: customer.id, input: { is_active: !customer.is_active } });
  }

  const tabs = [
    { value: "overview", label: t("customers.detailTabs.overview") },
    { value: "pricing", label: t("customers.detailTabs.pricing") },
    { value: "orders", label: t("customers.detailTabs.orders") },
  ];

  return (
    <div className="space-y-4">
      {canFinancials && (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
        <div className="space-y-4">
          {can("customers.tokens") && <OrderLinkSection customer={customer} />}
          {editing ? (
            <CustomerForm
              customer={customer}
              onSaved={() => setEditing(false)}
              onCancel={() => setEditing(false)}
              onDeleted={onDeleted}
              bare
            />
          ) : (
            <div className="space-y-4">
              <CustomerDetails customer={customer} />
              {canManage && (
                <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-3">
                  <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                    <Pencil className="size-3.5" />
                    {t("customers.edit")}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    className={
                      customer.is_active
                        ? "text-destructive hover:bg-destructive/10 hover:text-destructive"
                        : undefined
                    }
                    onClick={toggleActive}
                    disabled={update.isPending}
                  >
                    {update.isPending ? <Spinner /> : <Power className="size-3.5" />}
                    {customer.is_active
                      ? t("customers.deactivate.action")
                      : t("customers.activate.action")}
                  </Button>
                </div>
              )}
              {can("orders.view") && <CustomerConsignmentSection customerId={customer.id} />}
            </div>
          )}
        </div>
      )}

      {tab === "pricing" && (
        <CustomPricingSection customerId={customer.id} canManage={can("pricing.manage")} />
      )}

      {tab === "orders" && (
        <CustomerOrdersSection customerId={customer.id} canViewFinancials={canFinancials} />
      )}
    </div>
  );
}
