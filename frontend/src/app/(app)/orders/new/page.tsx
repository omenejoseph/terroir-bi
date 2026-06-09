"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useTranslation } from "@/i18n/context";
import { OrderForm } from "@/components/orders/order-form";

export default function NewOrderPage() {
  const { t } = useTranslation();
  const router = useRouter();

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <Link
        href="/orders"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("orders.back")}
      </Link>

      <h1 className="text-2xl font-semibold tracking-tight">{t("orders.form.addTitle")}</h1>

      <OrderForm
        onSaved={(order) => router.push(`/orders/${order.id}`)}
        onCancel={() => router.push("/orders")}
      />
    </div>
  );
}
