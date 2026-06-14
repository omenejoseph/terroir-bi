"use client";

import Link from "next/link";
import { Building2, ChevronRight } from "lucide-react";

import { useTranslation } from "@/i18n/context";
import type { Order } from "@/lib/types";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

/** Who the order is for — links through to the customer record. */
export function OrderCustomerCard({ order }: { order: Order }) {
  const { t } = useTranslation();
  const customer = order.customer;

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-semibold">{t("orders.customerCard")}</CardTitle>
      </CardHeader>
      <CardContent>
        {customer ? (
          <Link
            href={`/customers/${customer.id}`}
            className="-mx-2 flex items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-muted/40"
          >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
              <Building2 className="size-4" />
            </span>
            <span className="min-w-0 flex-1 truncate font-medium hover:underline">
              {customer.company_name}
            </span>
            <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
          </Link>
        ) : (
          <p className="text-sm text-muted-foreground">{t("orders.noCustomer")}</p>
        )}
      </CardContent>
    </Card>
  );
}
