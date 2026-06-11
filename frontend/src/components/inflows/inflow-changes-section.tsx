"use client";

import * as React from "react";
import { ArrowRight } from "lucide-react";

import { useInflowChanges } from "@/hooks/use-inflows";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { InflowFieldChange } from "@/lib/types";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";

/** Audit trail of edits to an inflow — newest first, formatted per field. */
export function InflowChangesSection({ inflowId }: { inflowId: string }) {
  const { t } = useTranslation();
  const fmt = useFormatters();
  const q = useInflowChanges(inflowId);
  const changes = q.data ?? [];

  function renderValue(field: string, value: InflowFieldChange["old"]): React.ReactNode {
    if (value === null || value === "") return "—";
    switch (field) {
      case "amount":
        return fmt.money2(Number(value));
      case "status":
        return t(`inflows.status.${value}`);
      case "payment_method":
        return t(`inflows.paymentMethods.${value}`);
      case "is_credit_note":
        return value ? t("common.yes") : t("common.no");
      case "date":
      case "due_date":
        return fmt.date(String(value));
      default:
        return String(value);
    }
  }

  const fieldLabel = (field: string) => t(`inflows.history.fields.${field}`);

  return (
    <div className="space-y-3">
      <h2 className="text-sm font-semibold">{t("inflows.history.title")}</h2>
      {q.isLoading ? (
        <div className="flex items-center justify-center py-8">
          <Spinner className="size-5 text-muted-foreground" />
        </div>
      ) : changes.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t("inflows.history.empty")}</p>
      ) : (
        <div className="space-y-2">
          {changes.map((entry) => (
            <Card key={entry.id}>
              <CardContent className="space-y-2 py-3">
                <p className="text-xs text-muted-foreground">
                  {entry.created_at ? fmt.dateTime(entry.created_at) : ""}
                  {entry.changed_by ? ` · ${t("inflows.history.by", { name: entry.changed_by })}` : ""}
                </p>
                <ul className="space-y-1 text-sm">
                  {entry.changes.map((c) => (
                    <li key={c.field} className="flex flex-wrap items-center gap-1.5">
                      <span className="text-muted-foreground">{fieldLabel(c.field)}:</span>
                      <span className="tabular-nums">{renderValue(c.field, c.old)}</span>
                      <ArrowRight className="size-3 text-muted-foreground" />
                      <span className="font-medium tabular-nums">{renderValue(c.field, c.new)}</span>
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
