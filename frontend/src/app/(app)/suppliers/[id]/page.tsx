"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useSupplier } from "@/hooks/use-suppliers";
import { useTranslation } from "@/i18n/context";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { SupplierDetailPanel } from "@/components/suppliers/supplier-detail-panel";

export default function SupplierDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id;
  const { t } = useTranslation();
  const router = useRouter();

  const { data: supplier, isLoading, isError } = useSupplier(id);

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <Link
        href="/suppliers"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("suppliers.back")}
      </Link>

      {isLoading ? (
        <div className="flex justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      ) : isError || !supplier ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("suppliers.notFound")}
          </CardContent>
        </Card>
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-semibold tracking-tight">{supplier.company_name}</h1>
            <Badge variant={supplier.is_active ? "success" : "secondary"}>
              {supplier.is_active ? t("common.status.active") : t("common.status.inactive")}
            </Badge>
          </div>

          <SupplierDetailPanel supplier={supplier} onDeleted={() => router.push("/suppliers")} />
        </>
      )}
    </div>
  );
}
