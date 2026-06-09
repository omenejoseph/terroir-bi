"use client";

import { useTranslation } from "@/i18n/context";
import type { Customer } from "@/lib/types";
import { Badge } from "@/components/ui/badge";

/** Read-only summary of a customer, shown inside the expandable card. */
export function CustomerDetails({ customer }: { customer: Customer }) {
  const { t } = useTranslation();

  const location = [customer.address, customer.city, customer.zip, customer.country]
    .filter(Boolean)
    .join(", ");
  const yesNo = (v: boolean) => (v ? t("common.yes") : t("common.no"));

  return (
    <div className="space-y-4">
      <dl className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
        <Detail label={t("customers.form.email")} value={customer.email} />
        <Detail label={t("customers.form.contact")} value={customer.contact_name} />
        <Detail label={t("customers.form.phone")} value={customer.phone} />
        <Detail label={t("customers.form.tierLabel")} value={customer.pricing_tier?.name} />
        <Detail
          label={t("customers.form.rebate")}
          value={t("customers.rebateOff", { percent: customer.effective_rebate_percent })}
        />
        <Detail label={t("customers.form.oib")} value={customer.oib} />
        <Detail label={t("customers.form.customerType")} value={customer.customer_type} />
        <Detail label={t("customers.detail.location")} value={location || null} />
        <Detail label={t("customers.form.hidePrices")} value={yesNo(customer.hide_prices)} />
        <Detail label={t("customers.form.isAgency")} value={yesNo(customer.is_agency ?? false)} />
        <Detail
          label={t("customers.form.allowSingleBottle")}
          value={yesNo(customer.allow_single_bottle ?? false)}
        />
        <Detail
          label={t("customers.form.excludeFromStats")}
          value={yesNo(customer.exclude_from_stats ?? false)}
        />
      </dl>

      <div className="flex flex-wrap gap-2">
        <Badge variant={customer.is_active ? "success" : "secondary"}>
          {customer.is_active ? t("common.status.active") : t("common.status.inactive")}
        </Badge>
      </div>
    </div>
  );
}

function Detail({ label, value }: { label: string; value: string | null | undefined }) {
  const { t } = useTranslation();
  return (
    <div className="space-y-0.5">
      <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="text-sm">{value || <span className="text-muted-foreground">{t("customers.detail.empty")}</span>}</dd>
    </div>
  );
}