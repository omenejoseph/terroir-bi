"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useCustomer } from "@/hooks/use-customers";
import { useTranslation } from "@/i18n/context";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { CustomerDetailPanel } from "@/components/customers/customer-detail-panel";

export default function CustomerDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id;
  const { t } = useTranslation();
  const router = useRouter();

  const { data: customer, isLoading, isError } = useCustomer(id);

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <Link
        href="/customers"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("customers.back")}
      </Link>

      {isLoading ? (
        <div className="flex justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : isError || !customer ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("customers.notFound")}
          </CardContent>
        </Card>
      ) : (
        <>
          <header className="flex flex-wrap items-center justify-between gap-3">
            <h1 className="text-2xl font-semibold tracking-tight">{customer.company_name}</h1>
            <Badge variant={customer.is_active ? "success" : "secondary"}>
              {customer.is_active ? t("common.status.active") : t("common.status.inactive")}
            </Badge>
          </header>

          <CustomerDetailPanel customer={customer} onDeleted={() => router.push("/customers")} />
        </>
      )}
    </div>
  );
}
