"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useTranslation } from "@/i18n/context";
import { CustomerForm } from "@/components/customers/customer-form";

export default function NewCustomerPage() {
  const { t } = useTranslation();
  const router = useRouter();

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <Link
        href="/customers"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("customers.back")}
      </Link>

      <h1 className="text-2xl font-semibold tracking-tight">{t("customers.form.addTitle")}</h1>

      <CustomerForm
        customer={null}
        onSaved={() => router.push("/customers")}
        onCancel={() => router.push("/customers")}
      />
    </div>
  );
}