"use client";

import Link from "next/link";
import { XCircle } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { buttonVariants } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Logo } from "@/components/logo";
import { cn } from "@/lib/utils";

/**
 * Stripe Checkout cancel return (FRONTEND_URL + /billing/cancel). No charge was
 * made and no subscription state changed — just send the user back into the app.
 */
export default function BillingCancelPage() {
  const { isAuthenticated } = useAuth();
  const { t } = useTranslation();

  return (
    <div className="flex min-h-dvh items-center justify-center bg-muted/30 px-4 py-12">
      <Card className="w-full max-w-sm">
        <CardContent className="flex flex-col items-center gap-4 py-10 text-center">
          <Logo className="size-16" />
          <XCircle className="size-10 text-muted-foreground" />
          <h1 className="text-xl font-semibold">{t("billing.canceledTitle")}</h1>
          <p className="text-sm text-muted-foreground">{t("billing.canceledBody")}</p>
          <Link
            href={isAuthenticated ? "/dashboard" : "/login"}
            className={cn(buttonVariants({ size: "lg", variant: "secondary" }), "mt-2 w-full")}
          >
            {isAuthenticated ? t("billing.goToDashboard") : t("billing.signIn")}
          </Link>
        </CardContent>
      </Card>
    </div>
  );
}
