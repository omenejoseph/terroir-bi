"use client";

import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Order, OrderStatus } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";

const STATUS_VARIANT: Record<OrderStatus, "secondary" | "outline" | "success"> = {
  RECEIVED: "secondary",
  IN_PROCESS: "outline",
  READY_TO_SHIP: "outline",
  SHIPPED: "success",
};

export function OrderHistorySection({ order }: { order: Order }) {
  const { t } = useTranslation();
  const { dateTime } = useFormatters();

  return (
    <Card>
      <CardContent className="pt-6">
        {order.status_history.length === 0 ? (
          <p className="py-4 text-center text-sm text-muted-foreground">{t("orders.history.empty")}</p>
        ) : (
          <ol className="space-y-4">
            {order.status_history.map((entry, i) => (
              <li key={i} className="flex gap-3">
                <div className="mt-1 flex flex-col items-center">
                  <span className="size-2 rounded-full bg-primary" />
                  {i < order.status_history.length - 1 && (
                    <span className="mt-1 w-px flex-1 bg-border" />
                  )}
                </div>
                <div className="space-y-1 pb-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={STATUS_VARIANT[entry.status]}>
                      {t(`orders.status.${entry.status}`)}
                    </Badge>
                    {entry.created_at && (
                      <span className="text-xs text-muted-foreground">{dateTime(entry.created_at)}</span>
                    )}
                    {entry.changed_by && (
                      <span className="text-xs text-muted-foreground">
                        {t("orders.history.by", { name: entry.changed_by.name })}
                      </span>
                    )}
                  </div>
                  {entry.note && <p className="text-sm text-muted-foreground">{entry.note}</p>}
                </div>
              </li>
            ))}
          </ol>
        )}
      </CardContent>
    </Card>
  );
}
