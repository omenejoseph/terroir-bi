"use client";

import * as React from "react";
import { AlertTriangle, Lock } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";

/**
 * Gates the authenticated app on the tenant's subscription access level:
 *   - blocked   → replace the page with a "subscription expired" screen
 *   - read_only → show a persistent banner above the page (the API also 403s
 *                 writes with `subscription_read_only`; the server is authority)
 *   - full      → render children unchanged
 */
export function AccessGuard({ children }: { children: React.ReactNode }) {
  const { isBlocked, isReadOnly, access } = useAuth();
  const { t } = useTranslation();

  if (isBlocked) {
    return (
      <div className="flex min-h-[60vh] items-center justify-center p-4">
        <Card className="max-w-md">
          <CardContent className="space-y-3 py-10 text-center">
            <Lock className="mx-auto size-8 text-destructive" />
            <h2 className="text-lg font-semibold">{t("access.blockedTitle")}</h2>
            <p className="text-sm text-muted-foreground">{t("access.blockedBody")}</p>
            <p className="text-xs text-muted-foreground">{t("access.contactSupport")}</p>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {isReadOnly && (
        <div
          role="status"
          className="flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300"
        >
          <AlertTriangle className="mt-0.5 size-4 shrink-0" />
          <div>
            <p className="font-medium">{t("access.readOnlyTitle")}</p>
            <p className="opacity-90">
              {t("access.readOnlyBody")}
              {access?.days_remaining != null && (
                <> {t("access.daysRemaining", { count: access.days_remaining })}.</>
              )}
            </p>
          </div>
        </div>
      )}
      {children}
    </div>
  );
}
