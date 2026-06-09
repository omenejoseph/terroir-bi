"use client";

import * as React from "react";

import {
  useConsignmentClose,
  useConsignmentReturn,
  useConsignmentSale,
  useOrderConsignment,
} from "@/hooks/use-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Order } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";

export function OrderConsignmentSection({ order, canManage }: { order: Order; canManage: boolean }) {
  const { t } = useTranslation();
  const { moneyObject, date } = useFormatters();
  const confirm = useConfirm();

  const summaryQ = useOrderConsignment(order.id);
  const sale = useConsignmentSale(order.id);
  const recordReturn = useConsignmentReturn(order.id);
  const close = useConsignmentClose(order.id);

  const [mode, setMode] = React.useState<"sale" | "return" | null>(null);

  const summary = summaryQ.data;
  const closed = !!summary?.closed_at;

  async function handleClose() {
    const ok = await confirm({
      title: t("orders.consignment.closeConfirmTitle"),
      description: t("orders.consignment.closeConfirmBody"),
      tone: "danger",
    });
    if (!ok) return;
    await close.mutateAsync();
  }

  if (summaryQ.isLoading) {
    return (
      <Card>
        <CardContent className="flex justify-center py-10">
          <Spinner className="size-5 text-muted-foreground" />
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardContent className="space-y-5 pt-6">
        <div className="flex items-center justify-between">
          <Badge variant={closed ? "secondary" : "success"}>
            {closed ? t("orders.consignment.closed", { date: summary?.closed_at ? date(summary.closed_at) : "" }) : t("orders.consignment.open")}
          </Badge>
          {canManage && !closed && (
            <div className="flex gap-2">
              <Button type="button" variant="outline" size="sm" onClick={() => setMode("sale")}>
                {t("orders.consignment.recordSale")}
              </Button>
              <Button type="button" variant="outline" size="sm" onClick={() => setMode("return")}>
                {t("orders.consignment.recordReturn")}
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                onClick={handleClose}
                disabled={close.isPending}
              >
                {close.isPending ? <Spinner /> : null}
                {t("orders.consignment.close")}
              </Button>
            </div>
          )}
        </div>

        {summary && summary.lines.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-border text-left text-muted-foreground">
                <tr>
                  <th className="py-2 pr-3 font-medium">{t("orders.consignment.colItem")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("orders.consignment.colPlaced")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("orders.consignment.colSold")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("orders.consignment.colReturned")}</th>
                  <th className="py-2 pr-3 text-right font-medium">{t("orders.consignment.colRemaining")}</th>
                  <th className="py-2 text-right font-medium">{t("orders.consignment.colRevenue")}</th>
                </tr>
              </thead>
              <tbody>
                {summary.lines.map((line) => (
                  <tr key={line.order_item_id} className="border-b border-border last:border-0">
                    <td className="py-2 pr-3">{line.name}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{line.placed}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{line.sold}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{line.returned}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{line.remaining}</td>
                    <td className="py-2 text-right tabular-nums">{moneyObject(line.revenue)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="font-medium">
                  <td className="py-2 pr-3">{t("orders.consignment.totals")}</td>
                  <td className="py-2 pr-3 text-right tabular-nums">{summary.totals.placed}</td>
                  <td className="py-2 pr-3 text-right tabular-nums">{summary.totals.sold}</td>
                  <td className="py-2 pr-3 text-right tabular-nums">{summary.totals.returned}</td>
                  <td className="py-2 pr-3 text-right tabular-nums">{summary.totals.remaining}</td>
                  <td className="py-2 text-right tabular-nums">{moneyObject(summary.totals.revenue)}</td>
                </tr>
              </tfoot>
            </table>
            <p className="mt-2 text-right text-sm text-muted-foreground">
              {t("orders.consignment.profit")}: {moneyObject(summary.totals.profit)} ·{" "}
              {t("orders.consignment.margin")}: {summary.totals.margin_percent}%
            </p>
          </div>
        ) : (
          <p className="py-4 text-center text-sm text-muted-foreground">{t("orders.consignment.empty")}</p>
        )}

        {mode && (
          <ConsignmentMovementForm
            order={order}
            mode={mode}
            pending={sale.isPending || recordReturn.isPending}
            onCancel={() => setMode(null)}
            onSubmit={async (items, note) => {
              if (mode === "sale") {
                await sale.mutateAsync({ items, note: note || null });
              } else {
                await recordReturn.mutateAsync({ items: items.map(({ order_item_id, quantity }) => ({ order_item_id, quantity })), note: note || null });
              }
              setMode(null);
            }}
          />
        )}
      </CardContent>
    </Card>
  );
}

function ConsignmentMovementForm({
  order,
  mode,
  pending,
  onCancel,
  onSubmit,
}: {
  order: Order;
  mode: "sale" | "return";
  pending: boolean;
  onCancel: () => void;
  onSubmit: (items: { order_item_id: string; quantity: number }[], note: string) => Promise<void>;
}) {
  const { t } = useTranslation();
  const [qty, setQty] = React.useState<Record<string, string>>({});
  const [note, setNote] = React.useState("");

  function submit() {
    const items = order.items
      .map((i) => ({ order_item_id: i.id, quantity: Number(qty[i.id] ?? "0") }))
      .filter((i) => i.quantity > 0);
    if (items.length === 0) return;
    void onSubmit(items, note.trim());
  }

  return (
    <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-3">
      <p className="text-sm font-medium">
        {mode === "sale" ? t("orders.consignment.saleTitle") : t("orders.consignment.returnTitle")}
      </p>
      <div className="space-y-2">
        {order.items.map((item) => (
          <div key={item.id} className="flex items-center justify-between gap-2">
            <span className="text-sm">{item.name}</span>
            <Input
              type="number"
              min={0}
              value={qty[item.id] ?? ""}
              onChange={(e) => setQty((q) => ({ ...q, [item.id]: e.target.value }))}
              placeholder="0"
              className="h-8 w-20"
              aria-label={`${item.name} ${t("orders.consignment.quantity")}`}
            />
          </div>
        ))}
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="consignment-note">{t("orders.consignment.note")}</Label>
        <Input id="consignment-note" value={note} onChange={(e) => setNote(e.target.value)} />
      </div>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
          {t("orders.form.cancel")}
        </Button>
        <Button type="button" size="sm" onClick={submit} disabled={pending}>
          {pending && <Spinner />}
          {mode === "sale" ? t("orders.consignment.submitSale") : t("orders.consignment.submitReturn")}
        </Button>
      </div>
    </div>
  );
}
