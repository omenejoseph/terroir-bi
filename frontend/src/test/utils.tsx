import * as React from "react";
import { render } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { AuthProvider } from "@/lib/auth/context";
import { I18nProvider } from "@/i18n/context";
import { ConfirmProvider } from "@/components/ui/confirm";
import { STORAGE_KEYS, type Locale } from "@/lib/config";

/** Seed a logged-in session before render (AuthProvider restores it via /auth/me). */
export function seedAuth(token = "tok_test") {
  window.localStorage.setItem(STORAGE_KEYS.token, token);
}

/** Force a locale so assertions can target known strings (default: English). */
export function seedLocale(locale: Locale = "en") {
  window.localStorage.setItem(STORAGE_KEYS.locale, locale);
}

/** Render a component wrapped in the full provider tree, with retries disabled. */
export function renderWithProviders(ui: React.ReactElement) {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });

  return render(
    <I18nProvider>
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <ConfirmProvider>{ui}</ConfirmProvider>
        </AuthProvider>
      </QueryClientProvider>
    </I18nProvider>,
  );
}

export * from "@testing-library/react";
export { default as userEvent } from "@testing-library/user-event";