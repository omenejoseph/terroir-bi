"use client";

import Link from "next/link";
import { ArrowRight, Wallet } from "lucide-react";

import { useInflows } from "@/hooks/use-inflows";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";

/**
 * Cross-link card on the order detail: cash inflows tied to this order. Hidden
 * when none are tied. Reuses GET /inflows?order_id=… (no dedicated endpoint).
 */
export function OrderInflowsCard({ orderId }: { orderId: string }) {
  const { t } = useTranslation();
  const { moneyObject } = useFormatters();
  const { data } = useInflows({ order_id: orderId });
  const inflows = data?.data ?? [];

  if (inflows.length === 0) return null;

  const receivedMinor = inflows
    .filter((i) => i.status === "RECEIVED")
    .reduce((sum, i) => sum + (i.is_credit_note ? -i.amount.minor : i.amount.minor), 0);

  return (
    <Card>
      <CardContent className="flex items-center justify-between gap-3 py-4">
        <div className="flex items-center gap-3">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-500">
            <Wallet className="size-4" />
          </span>
          <div>
            <p className="text-sm font-medium">{t("orders.inflows.title")}</p>
            <p className="text-sm text-muted-foreground">
              {t("orders.inflows.summary", {
                total: moneyObject({ minor: receivedMinor, currency: "EUR", formatted: "" }),
                count: inflows.length,
              })}
            </p>
          </div>
        </div>
        <Link
          href={`/inflows?order_id=${orderId}`}
          className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary hover:underline"
        >
          {t("orders.inflows.viewInflows")}
          <ArrowRight className="size-4" />
        </Link>
      </CardContent>
    </Card>
  );
}
