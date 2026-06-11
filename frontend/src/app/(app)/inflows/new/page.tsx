"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";
import { InflowForm } from "@/components/inflows/inflow-form";

export default function NewInflowPage() {
  const { t } = useTranslation();
  const router = useRouter();

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <Link
        href="/inflows"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("inflows.back")}
      </Link>

      <h1 className="text-2xl font-semibold tracking-tight">{t("inflows.add")}</h1>

      <Card>
        <CardContent className="pt-6">
          <InflowForm onSaved={() => router.push("/inflows")} onCancel={() => router.push("/inflows")} />
        </CardContent>
      </Card>
    </div>
  );
}
