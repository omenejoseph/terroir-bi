"use client";

import * as React from "react";
import Link from "next/link";

import { useSupplierOrders } from "@/hooks/use-suppliers";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { Spinner } from "@/components/ui/spinner";

/**
 * A supplier's purchase orders, shown as a tab on the supplier detail. Read-only
 * summary (number, status, dates, total, item count); managing orders still
 * happens on the dedicated purchase-orders page, linked at the foot.
 */
export function SupplierOrdersSection({ supplierId }: { supplierId: string }) {
  const { t } = useTranslation();
  const { moneyObject, date } = useFormatters();
  const ordersQ = useSupplierOrders({ supplier_id: supplierId });
  const orders = ordersQ.data?.data ?? [];

  if (ordersQ.isLoading) {
    return (
      <div className="flex justify-center py-8">
        <Spinner />
      </div>
    );
  }

  if (orders.length === 0) {
    return <p className="py-6 text-sm text-muted-foreground">{t("suppliers.ordersSection.empty")}</p>;
  }

  return (
    <div className="space-y-3">
      <ul className="divide-y divide-border rounded-lg border border-border/60">
        {orders.map((o) => (
          <li key={o.id} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
            <div className="min-w-0">
              <Link href="/suppliers/orders" className="font-medium hover:underline">
                {o.order_number}
              </Link>
              <p className="text-xs text-muted-foreground">
                {t(`supplierOrders.status.${o.status}`)}
                {o.expected_at ? ` · ${t("supplierOrders.colExpected")}: ${date(o.expected_at)}` : ""}
                {" · "}
                {t("suppliers.ordersSection.itemsCount", { count: o.items?.length ?? 0 })}
              </p>
            </div>
            <span className="shrink-0 font-medium tabular-nums">{moneyObject(o.total_amount)}</span>
          </li>
        ))}
      </ul>
      <div className="flex justify-end">
        <Link href="/suppliers/orders" className="text-xs text-muted-foreground hover:text-foreground hover:underline">
          {t("suppliers.ordersSection.viewAll")}
        </Link>
      </div>
    </div>
  );
}
