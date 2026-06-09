"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useCreateOrder } from "@/hooks/use-orders";
import { useTranslation } from "@/i18n/context";
import type { Order } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import { CustomerPicker } from "@/components/customers/customer-picker";
import {
  blankCatalogLine,
  linesToItems,
  OrderLineItemsEditor,
  type DraftLine,
} from "@/components/orders/order-line-items-editor";

export function OrderForm({
  onSaved,
  onCancel,
}: {
  onSaved: (order: Order) => void;
  onCancel: () => void;
}) {
  const { t } = useTranslation();
  const create = useCreateOrder();

  const [customerId, setCustomerId] = React.useState("");
  const [customerLabel, setCustomerLabel] = React.useState("");
  const [lines, setLines] = React.useState<DraftLine[]>(() => [blankCatalogLine()]);
  const [notes, setNotes] = React.useState("");
  const [isConsignment, setIsConsignment] = React.useState(false);
  const [isBackorder, setIsBackorder] = React.useState(false);
  const [backorderDate, setBackorderDate] = React.useState("");
  const [shippingCost, setShippingCost] = React.useState("");
  const [shippingPaidByUs, setShippingPaidByUs] = React.useState(false);
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [formError, setFormError] = React.useState<string | null>(null);

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    setErrors({});
    setFormError(null);

    const items = linesToItems(lines);
    if (!customerId) {
      setErrors({ customer_id: t("orders.form.customerRequired") });
      return;
    }
    if (items.length === 0) {
      setErrors({ items: t("orders.form.itemsRequired") });
      return;
    }

    const shipping = shippingCost.trim();
    try {
      const order = await create.mutateAsync({
        customer_id: customerId,
        items,
        notes: notes.trim() || null,
        is_consignment: isConsignment,
        is_backorder: isBackorder,
        backorder_date: isBackorder && backorderDate ? backorderDate : null,
        ...(shipping === "" ? {} : { shipping_cost: Number(shipping) }),
        shipping_paid_by_us: shippingPaidByUs,
      });
      onSaved(order);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const flat: Record<string, string> = {};
        for (const [field, messages] of Object.entries(err.errors)) {
          if (messages[0]) flat[field] = messages[0];
        }
        setErrors(flat);
        setFormError(err.message);
      } else {
        setFormError(t("orders.form.errorGeneric"));
      }
    }
  }

  return (
    <Card>
      <CardContent className="pt-6">
        <form onSubmit={handleSubmit} className="space-y-5">
          <div className="space-y-2">
            <Label htmlFor="order-customer">{t("orders.form.customer")}</Label>
            <CustomerPicker
              id="order-customer"
              valueLabel={customerLabel}
              onChange={(c) => {
                setCustomerId(c.id);
                setCustomerLabel(c.company_name);
              }}
              placeholder={t("orders.form.selectCustomer")}
              searchPlaceholder={t("orders.form.searchCustomer")}
              emptyLabel={t("orders.form.noCustomers")}
            />
            {errors.customer_id && <p className="text-sm text-destructive">{errors.customer_id}</p>}
          </div>

          <div className="space-y-2">
            <Label>{t("orders.form.items")}</Label>
            <OrderLineItemsEditor lines={lines} onChange={setLines} />
            {errors.items && <p className="text-sm text-destructive">{errors.items}</p>}
          </div>

          <div className="space-y-2">
            <Label htmlFor="order-notes">{t("orders.form.notes")}</Label>
            <Input
              id="order-notes"
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder={t("orders.form.notesPlaceholder")}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="order-shipping">{t("orders.form.shipping")}</Label>
              <Input
                id="order-shipping"
                type="number"
                min={0}
                value={shippingCost}
                onChange={(e) => setShippingCost(e.target.value)}
                placeholder={t("orders.form.shippingPlaceholder")}
              />
            </div>
            <div className="flex items-end">
              <label className="flex items-center gap-2 text-sm">
                <Checkbox
                  checked={shippingPaidByUs}
                  onChange={(e) => setShippingPaidByUs(e.target.checked)}
                />
                {t("orders.form.shippingPaidByUs")}
              </label>
            </div>
          </div>

          <div className="flex flex-wrap gap-6">
            <label className="flex items-center gap-2 text-sm">
              <Checkbox checked={isConsignment} onChange={(e) => setIsConsignment(e.target.checked)} />
              {t("orders.form.consignment")}
            </label>
            <label className="flex items-center gap-2 text-sm">
              <Checkbox checked={isBackorder} onChange={(e) => setIsBackorder(e.target.checked)} />
              {t("orders.form.backorder")}
            </label>
            {isBackorder && (
              <div className="flex items-center gap-2">
                <Label htmlFor="order-backorder-date" className="text-sm">
                  {t("orders.form.backorderDate")}
                </Label>
                <Input
                  id="order-backorder-date"
                  type="date"
                  value={backorderDate}
                  onChange={(e) => setBackorderDate(e.target.value)}
                />
              </div>
            )}
          </div>

          {formError && (
            <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>
          )}

          <div className="flex justify-end gap-2 border-t border-border pt-4">
            <Button type="button" variant="outline" onClick={onCancel}>
              {t("orders.form.cancel")}
            </Button>
            <Button type="submit" disabled={create.isPending}>
              {create.isPending && <Spinner />}
              {t("orders.form.submit")}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
