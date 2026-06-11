"use client";

import * as React from "react";
import { Plus } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useOrderPayments, useRecordPayment } from "@/hooks/use-finance";
import { useFormatters } from "@/lib/format";
import { majorToMinor } from "@/lib/money";
import { useTranslation } from "@/i18n/context";
import type { OrderPaymentSummary } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";

const STATUS_VARIANT: Record<OrderPaymentSummary["status"], "secondary" | "default" | "success"> = {
  UNPAID: "secondary",
  PARTIAL: "default",
  PAID: "success",
};

const STATUS_KEY: Record<OrderPaymentSummary["status"], string> = {
  UNPAID: "orders.payments.statusUnpaid",
  PARTIAL: "orders.payments.statusPartial",
  PAID: "orders.payments.statusPaid",
};

export function OrderPaymentsSection({ orderId }: { orderId: string }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject, date } = useFormatters();
  const { data, isLoading, isError, error } = useOrderPayments(orderId);
  const [recording, setRecording] = React.useState(false);

  const canManage = can("finance.manage");

  if (isLoading) {
    return (
      <div className="flex justify-center py-10">
        <Spinner className="size-5 text-muted-foreground" />
      </div>
    );
  }

  if (isError) {
    return (
      <Card>
        <CardContent className="py-8 text-center text-sm text-destructive">
          {error instanceof ApiError && error.status === 403
            ? t("orders.payments.forbidden")
            : t("orders.payments.summaryTitle")}
        </CardContent>
      </Card>
    );
  }

  if (!data) return null;

  return (
    <div className="space-y-4">
      <Card>
        <CardContent className="pt-6">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold">{t("orders.payments.summaryTitle")}</h3>
            {canManage && (
              <Button size="sm" onClick={() => setRecording(true)}>
                <Plus className="size-4" />
                {t("orders.payments.record")}
              </Button>
            )}
          </div>
          <div className="mt-3 grid gap-4 sm:grid-cols-3">
            <div>
              <p className="text-sm text-muted-foreground">{t("orders.payments.amountPaid")}</p>
              <p className="mt-1 text-lg font-semibold tabular-nums">
                {moneyObject(data.summary.amount_paid)}
              </p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">{t("orders.payments.balanceDue")}</p>
              <p className="mt-1 text-lg font-semibold tabular-nums">
                {moneyObject(data.summary.balance_due)}
              </p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">{t("orders.payments.paymentStatus")}</p>
              <Badge variant={STATUS_VARIANT[data.summary.status]} className="mt-1">
                {t(STATUS_KEY[data.summary.status])}
              </Badge>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="pt-6">
          {data.payments.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("orders.payments.empty")}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                  <tr>
                    <th className="py-2 pr-3 font-medium">{t("orders.payments.colDate")}</th>
                    <th className="py-2 pr-3 text-right font-medium">{t("orders.payments.colAmount")}</th>
                    <th className="py-2 pr-3 font-medium">{t("orders.payments.colMethod")}</th>
                    <th className="py-2 font-medium">{t("orders.payments.colReference")}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.payments.map((payment) => (
                    <tr key={payment.id} className="border-b border-border last:border-0">
                      <td className="py-2.5 pr-3 text-muted-foreground">{date(payment.date)}</td>
                      <td className="py-2.5 pr-3 text-right tabular-nums">
                        {moneyObject(payment.amount)}
                        {payment.is_credit_note && (
                          <Badge variant="outline" className="ml-2">
                            {t("orders.payments.creditNoteBadge")}
                          </Badge>
                        )}
                      </td>
                      <td className="py-2.5 pr-3 text-muted-foreground">{payment.payment_method ?? "—"}</td>
                      <td className="py-2.5 text-muted-foreground">{payment.reference ?? "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {canManage && (
        <RecordPaymentDialog orderId={orderId} open={recording} onOpenChange={setRecording} />
      )}
    </div>
  );
}

function RecordPaymentDialog({
  orderId,
  open,
  onOpenChange,
}: {
  orderId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation();
  const record = useRecordPayment(orderId);

  const [amount, setAmount] = React.useState("");
  const [paymentDate, setPaymentDate] = React.useState("");
  const [method, setMethod] = React.useState("");
  const [reference, setReference] = React.useState("");
  const [isCreditNote, setIsCreditNote] = React.useState(false);
  const [formError, setFormError] = React.useState<string | null>(null);

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    setFormError(null);
    try {
      await record.mutateAsync({
        amount: majorToMinor(amount) ?? 0, // input is in major units (€)
        ...(paymentDate ? { date: paymentDate } : {}),
        ...(method.trim() ? { payment_method: method.trim() } : {}),
        ...(reference.trim() ? { reference: reference.trim() } : {}),
        is_credit_note: isCreditNote,
      });
      onOpenChange(false);
      setAmount("");
      setPaymentDate("");
      setMethod("");
      setReference("");
      setIsCreditNote(false);
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : t("orders.payments.summaryTitle"));
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange} title={t("orders.payments.recordTitle")}>
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="payment_amount">{t("orders.payments.amountLabel")}</Label>
          <Input
            id="payment_amount"
            type="number"
            min={0}
            step="0.01"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            required
          />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="payment_date">{t("orders.payments.dateLabel")}</Label>
            <Input id="payment_date" type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} />
          </div>
          <div className="space-y-2">
            <Label htmlFor="payment_method">{t("orders.payments.methodLabel")}</Label>
            <Input id="payment_method" value={method} onChange={(e) => setMethod(e.target.value)} />
          </div>
        </div>
        <div className="space-y-2">
          <Label htmlFor="payment_reference">{t("orders.payments.referenceLabel")}</Label>
          <Input id="payment_reference" value={reference} onChange={(e) => setReference(e.target.value)} />
        </div>
        <label className="flex items-center gap-2 text-sm">
          <Checkbox checked={isCreditNote} onChange={(e) => setIsCreditNote(e.target.checked)} />
          {t("orders.payments.creditNote")}
        </label>

        {formError && (
          <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>
        )}

        <div className="flex justify-end gap-2 border-t border-border pt-4">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {t("common.confirm.cancel")}
          </Button>
          <Button type="submit" disabled={record.isPending}>
            {record.isPending && <Spinner />}
            {t("orders.payments.submit")}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
