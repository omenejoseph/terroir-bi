"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useCustomer } from "@/hooks/use-customers";
import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { CustomerForm } from "@/components/customers/customer-form";
import { CustomerConsignmentSection } from "@/components/customers/customer-consignment-section";

export default function CustomerDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id;
  const { t } = useTranslation();
  const { can } = useAuth();
  const router = useRouter();

  const { data: customer, isLoading, isError } = useCustomer(id);

  return (
    <div className="mx-auto max-w-2xl space-y-6">
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
          <h1 className="text-2xl font-semibold tracking-tight">{customer.company_name}</h1>
          <CustomerForm
            customer={customer}
            onSaved={() => router.push("/customers")}
            onCancel={() => router.push("/customers")}
            onDeleted={() => router.push("/customers")}
          />
          {can("orders.view") && <CustomerConsignmentSection customerId={customer.id} />}
        </>
      )}
    </div>
  );
}