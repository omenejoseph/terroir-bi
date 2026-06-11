"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";
import { CostForm } from "@/components/costs/cost-form";

export default function NewCostPage() {
  const { t } = useTranslation();
  const router = useRouter();

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <Link
        href="/costs"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("costs.back")}
      </Link>

      <h1 className="text-2xl font-semibold tracking-tight">{t("costs.add")}</h1>

      <Card>
        <CardContent className="pt-6">
          <CostForm onSaved={() => router.push("/costs")} onCancel={() => router.push("/costs")} />
        </CardContent>
      </Card>
    </div>
  );
}
