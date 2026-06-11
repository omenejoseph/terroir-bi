"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";
import { SupplierForm } from "@/components/suppliers/supplier-form";

export default function NewSupplierPage() {
  const { t } = useTranslation();
  const router = useRouter();

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <Link
        href="/suppliers"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("suppliers.back")}
      </Link>

      <h1 className="text-2xl font-semibold tracking-tight">{t("suppliers.add")}</h1>

      <Card>
        <CardContent className="pt-6">
          <SupplierForm
            supplier={null}
            onSaved={(saved) => router.push(`/suppliers/${saved.id}`)}
            onCancel={() => router.push("/suppliers")}
          />
        </CardContent>
      </Card>
    </div>
  );
}
