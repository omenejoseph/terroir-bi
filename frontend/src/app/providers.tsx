"use client";

import * as React from "react";
import { QueryClientProvider } from "@tanstack/react-query";

import { AuthProvider } from "@/lib/auth/context";
import { I18nProvider } from "@/i18n/context";
import { makeQueryClient } from "@/lib/query";
import { ConfirmProvider } from "@/components/ui/confirm";
import { ServiceWorkerRegistrar } from "@/components/service-worker-registrar";

/**
 * App-wide providers. I18n is outermost so every component (incl. auth UI) can
 * translate; then React Query, then auth state.
 */
export function Providers({ children }: { children: React.ReactNode }) {
  // One client per browser session.
  const [queryClient] = React.useState(makeQueryClient);

  return (
    <I18nProvider>
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <ConfirmProvider>{children}</ConfirmProvider>
        </AuthProvider>
        <ServiceWorkerRegistrar />
      </QueryClientProvider>
    </I18nProvider>
  );
}