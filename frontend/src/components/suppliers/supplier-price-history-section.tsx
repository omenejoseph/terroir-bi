"use client";

import { ArrowRight } from "lucide-react";

import { useSupplierPriceChanges } from "@/hooks/use-suppliers";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";

/** Audited log of cost changes on the supplier's price list (newest first). */
export function SupplierPriceHistorySection({ supplierId }: { supplierId: string }) {
  const { t } = useTranslation();
  const { moneyObject, dateTime } = useFormatters();
  const changesQ = useSupplierPriceChanges(supplierId);
  const changes = changesQ.data ?? [];

  return (
    <Card>
      <CardContent className="space-y-3 pt-6">
        <h2 className="text-sm font-semibold">{t("suppliers.history.title")}</h2>

        {changesQ.isLoading ? (
          <div className="flex py-6 justify-center">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : changes.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("suppliers.history.empty")}</p>
        ) : (
          <ul className="divide-y divide-border">
            {changes.map((c) => (
              <li key={c.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                <div className="min-w-0">
                  <p className="truncate font-medium">{c.description}</p>
                  <p className="text-xs text-muted-foreground">
                    {c.created_at ? dateTime(c.created_at) : ""}
                    {c.unit ? ` · ${c.unit}` : ""}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-1.5 tabular-nums">
                  {c.old_price ? (
                    <>
                      <span className="text-muted-foreground line-through">
                        {moneyObject(c.old_price)}
                      </span>
                      <ArrowRight className="size-3 text-muted-foreground" />
                      <span className="font-medium">{moneyObject(c.new_price)}</span>
                    </>
                  ) : (
                    <span className="font-medium">{moneyObject(c.new_price)}</span>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
