"use client";

import { useTranslation } from "@/i18n/context";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/**
 * Placeholder for the Customers module. The API already exposes /customers,
 * /pricing-tiers, and resolved prices — wire them up the same way as Inventory
 * (lib/api/customers.ts → hooks/use-customers.ts → this page).
 */
export default function CustomersPage() {
  const { t } = useTranslation();

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("customers.title")}</h1>
        <p className="text-sm text-muted-foreground">{t("customers.subtitle")}</p>
      </header>
      <Card>
        <CardHeader>
          <CardTitle>{t("customers.comingSoonTitle")}</CardTitle>
          <CardDescription>{t("customers.comingSoonDesc")}</CardDescription>
        </CardHeader>
        <CardContent />
      </Card>
    </div>
  );
}