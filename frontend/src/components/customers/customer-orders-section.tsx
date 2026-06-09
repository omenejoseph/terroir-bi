"use client";

import Link from "next/link";
import { CalendarClock, Target, TrendingUp, Activity } from "lucide-react";

import { useCustomerOrderAnalytics } from "@/hooks/use-customers";
import { useOrders } from "@/hooks/use-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { OrderStatus } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { StatCard } from "@/components/dashboard/stat-card";

const STATUS_VARIANT: Record<OrderStatus, "secondary" | "outline" | "success"> = {
  RECEIVED: "secondary",
  IN_PROCESS: "outline",
  READY_TO_SHIP: "outline",
  SHIPPED: "success",
};

export function CustomerOrdersSection({
  customerId,
  canViewFinancials,
}: {
  customerId: string;
  canViewFinancials: boolean;
}) {
  const { t } = useTranslation();
  const { moneyObject, date, number } = useFormatters();
  const analyticsQ = useCustomerOrderAnalytics(customerId, canViewFinancials);
  const ordersQ = useOrders({ customer_id: customerId });

  const a = analyticsQ.data;
  const orders = ordersQ.data?.data ?? [];

  const yoy = a ? Number(a.yoy_growth_percent) : 0;
  const yoyLabel = a ? `${yoy > 0 ? "+" : ""}${number(yoy)}%` : "—";

  return (
    <div className="space-y-6">
      {canViewFinancials && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            label={t("customers.orders.yoyGrowth")}
            value={analyticsQ.isLoading ? "…" : yoyLabel}
            icon={TrendingUp}
            accent="bg-emerald-500/10 text-emerald-500"
          />
          <StatCard
            label={t("customers.orders.annualProjection")}
            value={analyticsQ.isLoading ? "…" : moneyObject(a?.annual_projection ?? null)}
            icon={Target}
            accent="bg-sky-500/10 text-sky-500"
            delayMs={50}
          />
          <StatCard
            label={t("customers.orders.expectedNext")}
            value={
              analyticsQ.isLoading
                ? "…"
                : a?.expected_next_order_date
                  ? date(a.expected_next_order_date)
                  : t("customers.orders.never")
            }
            icon={CalendarClock}
            accent="bg-amber-500/10 text-amber-500"
            delayMs={100}
          />
          <StatCard
            label={t("customers.orders.nextQuarter")}
            value={analyticsQ.isLoading ? "…" : moneyObject(a?.next_quarter_projection ?? null)}
            icon={Activity}
            accent="bg-violet-500/10 text-violet-500"
            delayMs={150}
          />
        </div>
      )}

      <Card>
        <CardContent className="pt-6">
          <h3 className="mb-3 text-sm font-semibold">{t("customers.orders.historyTitle")}</h3>
          {ordersQ.isLoading ? (
            <div className="flex justify-center py-8">
              <Spinner className="size-5 text-muted-foreground" />
            </div>
          ) : orders.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("customers.orders.historyEmpty")}</p>
          ) : (
            <ul className="divide-y divide-border">
              {orders.map((order) => (
                <li key={order.id}>
                  <Link
                    href={`/orders/${order.id}`}
                    className="flex items-center justify-between gap-3 py-2.5 transition-colors hover:text-foreground"
                  >
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium">{order.order_number}</p>
                      {order.created_at && (
                        <p className="truncate text-xs text-muted-foreground">
                          {date(order.created_at)}
                        </p>
                      )}
                    </div>
                    <div className="flex items-center gap-3">
                      <Badge variant={STATUS_VARIANT[order.status]}>
                        {t(`orders.status.${order.status}`)}
                      </Badge>
                      <span className="text-sm font-medium tabular-nums">
                        {moneyObject(order.total_amount)}
                      </span>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
