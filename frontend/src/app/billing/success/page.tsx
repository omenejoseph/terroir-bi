"use client";

import * as React from "react";
import Link from "next/link";
import { CheckCircle2 } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { buttonVariants } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import { Logo } from "@/components/logo";
import { cn } from "@/lib/utils";

/**
 * Stripe Checkout success return. Stripe sends the customer here (FRONTEND_URL +
 * /billing/success) after they set up payment. The subscription itself is synced
 * server-side by the Stripe webhook; this page just re-pulls /auth/me a few times
 * (the webhook can lag a second or two) so the app reflects the new access.
 */
const MAX_ATTEMPTS = 5;

export default function BillingSuccessPage() {
  const { isAuthenticated, loading, refreshSession, access } = useAuth();
  const { t } = useTranslation();

  const attemptsRef = React.useRef(0);
  const [syncing, setSyncing] = React.useState(true);

  React.useEffect(() => {
    if (loading) return;

    // Not signed in here (e.g. returned in a different browser) — nothing to
    // refresh; just confirm the checkout completed.
    if (!isAuthenticated) {
      setSyncing(false);
      return;
    }

    // Active subscription confirmed, or we've polled enough — stop.
    if (access?.level === "full" || attemptsRef.current >= MAX_ATTEMPTS) {
      setSyncing(false);
      return;
    }

    // First pull immediately, then back off ~1.5s between retries while the
    // webhook catches up. This effect re-runs as `access` updates each pull.
    const delay = attemptsRef.current === 0 ? 0 : 1500;
    const id = window.setTimeout(() => {
      attemptsRef.current += 1;
      void refreshSession().catch(() => undefined);
    }, delay);

    return () => window.clearTimeout(id);
  }, [loading, isAuthenticated, access, refreshSession]);

  return (
    <div className="flex min-h-dvh items-center justify-center bg-muted/30 px-4 py-12">
      <Card className="w-full max-w-sm">
        <CardContent className="flex flex-col items-center gap-4 py-10 text-center">
          <Logo className="size-16" />

          {syncing ? (
            <>
              <Spinner className="size-6" />
              <p className="text-sm text-muted-foreground">{t("billing.syncing")}</p>
            </>
          ) : (
            <>
              <CheckCircle2 className="size-10 text-success" />
              <h1 className="text-xl font-semibold">{t("billing.successTitle")}</h1>
              <p className="text-sm text-muted-foreground">
                {isAuthenticated ? t("billing.successBody") : t("billing.successGuestBody")}
              </p>
              <Link
                href={isAuthenticated ? "/dashboard" : "/login"}
                className={cn(buttonVariants({ size: "lg" }), "mt-2 w-full")}
              >
                {isAuthenticated ? t("billing.goToDashboard") : t("billing.signIn")}
              </Link>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
