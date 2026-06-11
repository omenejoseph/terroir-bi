"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useCreateOrder } from "@/hooks/use-orders";
import { useTranslation } from "@/i18n/context";
import { useFormatters } from "@/lib/format";
import { majorToMinor } from "@/lib/money";
import { cn } from "@/lib/utils";
import { ORDER_STATUSES, type Order, type OrderStatus } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
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
  const { moneyObject } = useFormatters();
  const create = useCreateOrder();

  const [customerId, setCustomerId] = React.useState("");
  const [customerLabel, setCustomerLabel] = React.useState("");
  const [lines, setLines] = React.useState<DraftLine[]>(() => [blankCatalogLine()]);
  const [notes, setNotes] = React.useState("");
  const [status, setStatus] = React.useState<OrderStatus>("RECEIVED");
  const [orderType, setOrderType] = React.useState<"standard" | "backorder" | "consignment">(
    "standard",
  );
  const [deductStock, setDeductStock] = React.useState(false); // backorder: deduct now?
  const [backorderDate, setBackorderDate] = React.useState("");
  const [shippingCost, setShippingCost] = React.useState("");
  const [subtotal, setSubtotal] = React.useState({ minor: 0, currency: "EUR" });
  const [errors, setErrors] = React.useState<Record<string, string>>({});

  const isBackorder = orderType === "backorder";
  const isConsignment = orderType === "consignment";
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

    const shippingMinor = majorToMinor(shippingCost); // input is in major units (€)
    try {
      const order = await create.mutateAsync({
        customer_id: customerId,
        items,
        status,
        notes: notes.trim() || null,
        is_consignment: isConsignment,
        is_backorder: isBackorder,
        backorder_date: isBackorder && backorderDate ? backorderDate : null,
        // Only a backorder offers the deduct-now choice; others use the API default.
        ...(isBackorder ? { deduct_stock: deductStock } : {}),
        // A logistics cost means we bear the freight; the API infers shipping_paid_by_us.
        ...(shippingMinor === null ? {} : { shipping_cost: shippingMinor }),
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
            <OrderLineItemsEditor
              lines={lines}
              onChange={setLines}
              customerId={customerId}
              onSubtotalChange={(minor, currency) => setSubtotal({ minor, currency })}
            />
            {errors.items && <p className="text-sm text-destructive">{errors.items}</p>}
            <div className="flex items-center justify-end gap-3 border-t border-border pt-2 text-sm">
              <span className="text-muted-foreground">{t("orders.form.total")}</span>
              <span className="text-base font-semibold tabular-nums">
                {moneyObject({ minor: subtotal.minor, currency: subtotal.currency })}
              </span>
            </div>
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
              <Label htmlFor="order-status">{t("orders.form.initialStatus")}</Label>
              <Select
                id="order-status"
                value={status}
                onChange={(e) => setStatus(e.target.value as OrderStatus)}
              >
                {ORDER_STATUSES.map((s) => (
                  <option key={s} value={s}>
                    {t(`orders.status.${s}`)}
                  </option>
                ))}
              </Select>
            </div>
            <div className="space-y-1">
              <Label htmlFor="order-shipping">{t("orders.form.logistics")}</Label>
              <div className="flex items-center gap-2">
                <Input
                  id="order-shipping"
                  type="number"
                  min={0}
                  step="0.01"
                  value={shippingCost}
                  onChange={(e) => setShippingCost(e.target.value)}
                  placeholder={t("orders.form.shippingPlaceholder")}
                />
                <span className="text-sm text-muted-foreground">€</span>
              </div>
              <p className="text-xs text-muted-foreground">{t("orders.form.logisticsHint")}</p>
            </div>
          </div>

          {/* Fulfilment type — mutually exclusive */}
          <fieldset className="space-y-2">
            <legend className="mb-1 text-sm font-medium">{t("orders.form.fulfilment")}</legend>
            {(["standard", "backorder", "consignment"] as const).map((opt) => (
              <label
                key={opt}
                className={cn(
                  "flex cursor-pointer gap-3 rounded-lg border p-3 transition-colors",
                  orderType === opt ? "border-primary bg-primary/5" : "border-border",
                )}
              >
                <input
                  type="radio"
                  name="order-type"
                  value={opt}
                  checked={orderType === opt}
                  onChange={() => setOrderType(opt)}
                  className="mt-0.5 size-4 accent-primary"
                  aria-label={t(`orders.form.type.${opt}`)}
                />
                <div className="space-y-1">
                  <span className="block text-sm font-medium">{t(`orders.form.type.${opt}`)}</span>
                  <span className="block text-xs text-muted-foreground">
                    {t(`orders.form.type.${opt}Hint`)}
                  </span>

                  {opt === "backorder" && isBackorder && (
                    <div className="space-y-3 pt-2">
                      <label className="flex items-center gap-2 text-xs font-medium">
                        <Checkbox
                          checked={deductStock}
                          onChange={(e) => setDeductStock(e.target.checked)}
                        />
                        {t("orders.form.deductStock")}
                        <span className="font-normal text-muted-foreground">
                          {deductStock
                            ? t("orders.form.deductStockOn")
                            : t("orders.form.deductStockOff")}
                        </span>
                      </label>
                      <div className="space-y-1">
                        <Label htmlFor="order-backorder-date" className="text-xs">
                          {t("orders.form.expectedDate")}
                        </Label>
                        <Input
                          id="order-backorder-date"
                          type="date"
                          value={backorderDate}
                          onChange={(e) => setBackorderDate(e.target.value)}
                          className="max-w-48"
                        />
                      </div>
                    </div>
                  )}
                </div>
              </label>
            ))}
          </fieldset>

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
