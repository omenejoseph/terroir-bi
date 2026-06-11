"use client";

import * as React from "react";

import { ApiError } from "@/lib/api/client";
import { useCreateInflow } from "@/hooks/use-inflows";
import { useCustomers } from "@/hooks/use-customers";
import { useTranslation } from "@/i18n/context";
import { majorToMinor } from "@/lib/money";
import { INFLOW_STATUSES, PAYMENT_METHODS, type InflowInput, type InflowStatus } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

export function InflowForm({
  onSaved,
  onCancel,
}: {
  onSaved: () => void;
  onCancel: () => void;
}) {
  const { t } = useTranslation();
  const create = useCreateInflow();
  const customersQ = useCustomers();
  const customers = customersQ.data?.data ?? [];

  const [inflowDate, setInflowDate] = React.useState(today());
  const [amount, setAmount] = React.useState("");
  const [status, setStatus] = React.useState<InflowStatus>("RECEIVED");
  const [paymentMethod, setPaymentMethod] = React.useState("");
  const [customerId, setCustomerId] = React.useState("");
  const [category, setCategory] = React.useState("");
  const [reference, setReference] = React.useState("");
  const [isCreditNote, setIsCreditNote] = React.useState(false);
  const [notes, setNotes] = React.useState("");
  const [formError, setFormError] = React.useState<string | null>(null);

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    setFormError(null);
    const input: InflowInput = {
      amount: majorToMinor(amount) ?? 0, // entered in major units (€)
      ...(inflowDate ? { date: inflowDate } : {}),
      status,
      is_credit_note: isCreditNote,
      ...(paymentMethod ? { payment_method: paymentMethod } : {}),
      ...(customerId ? { customer_id: customerId } : {}),
      ...(category.trim() ? { category: category.trim() } : {}),
      ...(reference.trim() ? { reference: reference.trim() } : {}),
      ...(notes.trim() ? { notes: notes.trim() } : {}),
    };
    try {
      await create.mutateAsync(input);
      onSaved();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : t("inflows.form.errorGeneric"));
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor="inflow_date">{t("inflows.form.date")}</Label>
          <Input
            id="inflow_date"
            type="date"
            value={inflowDate}
            onChange={(e) => setInflowDate(e.target.value)}
            required
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="inflow_amount">{t("inflows.form.amount")}</Label>
          <div className="flex items-center gap-2">
            <Input
              id="inflow_amount"
              type="number"
              min={0}
              step="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              required
            />
            <span className="shrink-0 rounded-md border border-input px-3 py-2 text-sm text-muted-foreground">
              {t("inflows.form.currency")}
            </span>
          </div>
        </div>

        <div className="space-y-2">
          <Label htmlFor="inflow_status">{t("inflows.form.status")}</Label>
          <Select id="inflow_status" value={status} onChange={(e) => setStatus(e.target.value as InflowStatus)}>
            {INFLOW_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`inflows.status.${s}`)}
              </option>
            ))}
          </Select>
        </div>
        <div className="space-y-2">
          <Label htmlFor="inflow_payment">{t("inflows.form.paymentMethod")}</Label>
          <Select id="inflow_payment" value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)}>
            <option value="">{t("inflows.form.selectPaymentMethod")}</option>
            {PAYMENT_METHODS.map((m) => (
              <option key={m} value={m}>
                {t(`inflows.paymentMethods.${m}`)}
              </option>
            ))}
          </Select>
        </div>
      </div>

      <div className="space-y-2">
        <Label htmlFor="inflow_customer">{t("inflows.form.customer")}</Label>
        <Select id="inflow_customer" value={customerId} onChange={(e) => setCustomerId(e.target.value)}>
          <option value="">{t("inflows.form.noCustomer")}</option>
          {customers.map((c) => (
            <option key={c.id} value={c.id}>
              {c.company_name}
            </option>
          ))}
        </Select>
      </div>

      <div className="space-y-2">
        <Label htmlFor="inflow_category">{t("inflows.form.category")}</Label>
        <Input
          id="inflow_category"
          value={category}
          onChange={(e) => setCategory(e.target.value)}
          placeholder={t("inflows.form.categoryPlaceholder")}
        />
      </div>

      <div className="space-y-2">
        <Label htmlFor="inflow_reference">{t("inflows.form.reference")}</Label>
        <Input id="inflow_reference" value={reference} onChange={(e) => setReference(e.target.value)} />
      </div>

      <label className="flex items-center gap-2 text-sm">
        <Checkbox checked={isCreditNote} onChange={(e) => setIsCreditNote(e.target.checked)} />
        {t("inflows.form.creditNote")}
      </label>

      <div className="space-y-2">
        <Label htmlFor="inflow_notes">{t("inflows.form.notes")}</Label>
        <Input id="inflow_notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
      </div>

      {formError && (
        <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>
      )}

      <div className="flex justify-end gap-2 border-t border-border pt-4">
        <Button type="button" variant="outline" onClick={onCancel}>
          {t("inflows.form.cancel")}
        </Button>
        <Button type="submit" disabled={create.isPending}>
          {create.isPending && <Spinner />}
          {t("inflows.form.create")}
        </Button>
      </div>
    </form>
  );
}
