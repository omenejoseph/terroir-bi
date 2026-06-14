"use client";

import * as React from "react";

import { cn } from "@/lib/utils";
import { useUpdateOrderStatus } from "@/hooks/use-orders";
import { useTranslation } from "@/i18n/context";
import { ORDER_STATUSES, type Order, type OrderStatus } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";

/** Dot color per status, matching the status badges. */
const STATUS_DOT: Record<OrderStatus, string> = {
  RECEIVED: "bg-blue-500",
  IN_PROCESS: "bg-amber-500",
  READY_TO_SHIP: "bg-purple-500",
  SHIPPED: "bg-green-500",
};

/** Filled style applied to the active/selected status button. */
const STATUS_ACTIVE: Record<OrderStatus, string> = {
  RECEIVED: "border-blue-500 bg-blue-500 text-white",
  IN_PROCESS: "border-amber-500 bg-amber-500 text-white",
  READY_TO_SHIP: "border-purple-500 bg-purple-500 text-white",
  SHIPPED: "border-green-500 bg-green-500 text-white",
};

/**
 * Prominent status control: every status is a clickable, colour-coded button so
 * the current step and the available moves read at a glance. Picking a different
 * status reveals an optional note + Update action (confirmed before saving).
 */
export function OrderStatusUpdater({ order, canManage }: { order: Order; canManage: boolean }) {
  const { t } = useTranslation();
  const confirm = useConfirm();
  const updateStatus = useUpdateOrderStatus(order.id);
  const [selected, setSelected] = React.useState<OrderStatus | null>(null);
  const [note, setNote] = React.useState("");

  const pending = selected !== null && selected !== order.status;

  async function apply() {
    if (!pending || selected === null) return;
    const ok = await confirm({
      title: t("orders.statusChange.confirmTitle"),
      description: t("orders.statusChange.confirmBody", {
        order: order.order_number,
        status: t(`orders.status.${selected}`),
      }),
    });
    if (!ok) return;
    await updateStatus.mutateAsync({ status: selected, note: note.trim() || null });
    setSelected(null);
    setNote("");
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-semibold">{t("orders.statusChange.title")}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="grid grid-cols-2 gap-2">
          {ORDER_STATUSES.map((s) => {
            const isCurrent = order.status === s;
            const isActive = (selected ?? order.status) === s;
            return (
              <button
                key={s}
                type="button"
                disabled={!canManage}
                aria-pressed={isActive}
                onClick={() => setSelected(s)}
                className={cn(
                  "flex items-center gap-2 rounded-md border px-3 py-2 text-sm font-medium transition-colors",
                  "disabled:cursor-not-allowed disabled:opacity-70",
                  isActive ? STATUS_ACTIVE[s] : "border-border text-muted-foreground hover:text-foreground",
                )}
              >
                <span className={cn("size-2 shrink-0 rounded-full", isActive ? "bg-white" : STATUS_DOT[s])} />
                <span className="truncate">{t(`orders.status.${s}`)}</span>
                {isCurrent && (
                  <span className="ml-auto text-[10px] font-normal opacity-80">
                    {t("orders.statusChange.current")}
                  </span>
                )}
              </button>
            );
          })}
        </div>

        {canManage && pending && (
          <div className="space-y-2">
            <Input
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder={t("orders.statusChange.note")}
            />
            <div className="flex justify-end gap-2">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => {
                  setSelected(null);
                  setNote("");
                }}
              >
                {t("orders.form.cancel")}
              </Button>
              <Button type="button" size="sm" onClick={apply} disabled={updateStatus.isPending}>
                {updateStatus.isPending && <Spinner />}
                {t("orders.statusChange.action")}
              </Button>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
