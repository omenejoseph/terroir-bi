"use client";

import * as React from "react";
import { Pencil } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useUpdateBackorder, useUpdateNotes, useUpdateShipping } from "@/hooks/use-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { Order } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";

export function OrderDetailsCard({ order, canManage }: { order: Order; canManage: boolean }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject, date } = useFormatters();
  const canBackorder = can("orders.backorder");

  const updateShipping = useUpdateShipping(order.id);
  const updateNotes = useUpdateNotes(order.id);
  const updateBackorder = useUpdateBackorder(order.id);

  const [editing, setEditing] = React.useState(false);
  const [shipping, setShipping] = React.useState(order.shipping_cost ? String(order.shipping_cost.minor) : "");
  const [paidByUs, setPaidByUs] = React.useState(order.shipping_paid_by_us);
  const [notes, setNotes] = React.useState(order.notes ?? "");
  const [backorderDate, setBackorderDate] = React.useState(order.backorder_date?.slice(0, 10) ?? "");
  const [saving, setSaving] = React.useState(false);

  function start() {
    setShipping(order.shipping_cost ? String(order.shipping_cost.minor) : "");
    setPaidByUs(order.shipping_paid_by_us);
    setNotes(order.notes ?? "");
    setBackorderDate(order.backorder_date?.slice(0, 10) ?? "");
    setEditing(true);
  }

  async function save() {
    setSaving(true);
    try {
      const shippingTrim = shipping.trim();
      await updateShipping.mutateAsync({
        shipping_cost: shippingTrim === "" ? null : Number(shippingTrim),
        shipping_paid_by_us: paidByUs,
      });
      await updateNotes.mutateAsync(notes.trim() || null);
      if (canBackorder) {
        await updateBackorder.mutateAsync(backorderDate || null);
      }
      setEditing(false);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold">{t("orders.details.title")}</h2>
          {canManage && !editing && (
            <Button variant="outline" size="sm" onClick={start}>
              <Pencil className="size-3.5" />
              {t("orders.details.edit")}
            </Button>
          )}
        </div>

        {editing ? (
          <div className="space-y-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="d-shipping">{t("orders.details.shipping")}</Label>
                <Input
                  id="d-shipping"
                  type="number"
                  min={0}
                  value={shipping}
                  onChange={(e) => setShipping(e.target.value)}
                />
              </div>
              <label className="flex items-end gap-2 pb-2 text-sm">
                <Checkbox checked={paidByUs} onChange={(e) => setPaidByUs(e.target.checked)} />
                {t("orders.details.shippingPaidByUs")}
              </label>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="d-notes">{t("orders.details.notes")}</Label>
              <Input id="d-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
            </div>
            {canBackorder && (
              <div className="space-y-1.5">
                <Label htmlFor="d-backorder">{t("orders.details.backorder")}</Label>
                <Input
                  id="d-backorder"
                  type="date"
                  value={backorderDate}
                  onChange={(e) => setBackorderDate(e.target.value)}
                />
              </div>
            )}
            <div className="flex justify-end gap-2">
              <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(false)}>
                {t("orders.form.cancel")}
              </Button>
              <Button type="button" size="sm" onClick={save} disabled={saving}>
                {saving && <Spinner />}
                {t("orders.details.save")}
              </Button>
            </div>
          </div>
        ) : (
          <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            <Detail label={t("orders.details.shipping")}>
              {order.shipping_cost ? moneyObject(order.shipping_cost) : t("orders.details.none")}
              {order.shipping_paid_by_us ? ` · ${t("orders.details.shippingPaidByUs")}` : ""}
            </Detail>
            <Detail label={t("orders.details.backorder")}>
              {order.backorder_date ? date(order.backorder_date) : t("orders.details.none")}
            </Detail>
            <Detail label={t("orders.details.notes")}>{order.notes || t("orders.details.none")}</Detail>
          </dl>
        )}
      </CardContent>
    </Card>
  );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-0.5">
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}
