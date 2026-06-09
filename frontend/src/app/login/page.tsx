"use client";

import * as React from "react";
import { useRouter } from "next/navigation";

import { ApiError } from "@/lib/api/client";
import { APP_NAME } from "@/lib/config";
import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import { Logo } from "@/components/logo";
import { LanguageSwitcher } from "@/components/language-switcher";

export default function LoginPage() {
  const { login, isAuthenticated, loading } = useAuth();
  const { t } = useTranslation();
  const router = useRouter();

  const [email, setEmail] = React.useState("");
  const [password, setPassword] = React.useState("");
  const [error, setError] = React.useState<string | null>(null);
  const [submitting, setSubmitting] = React.useState(false);

  // Already signed in → bounce to the app.
  React.useEffect(() => {
    if (!loading && isAuthenticated) router.replace("/dashboard");
  }, [loading, isAuthenticated, router]);

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await login(email, password);
      router.replace("/dashboard");
    } catch (err) {
      if (err instanceof ApiError && err.status < 500) {
        // 4xx messages arrive already localized (X-Locale). Never surface a 5xx
        // body — those are raw server errors, not user-facing copy.
        setError(err.fieldError("email") ?? err.message);
      } else {
        setError(t("login.errorGeneric"));
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="flex min-h-dvh items-center justify-center bg-muted/30 px-4 py-12">
      <Card className="w-full max-w-sm">
        <CardHeader className="items-center text-center">
          <Logo className="mb-1 size-20" />
          <CardTitle className="text-xl">{t("login.heading")}</CardTitle>
          <CardDescription>{t("login.subtitle")}</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="email">{t("login.emailLabel")}</Label>
              <Input
                id="email"
                type="email"
                autoComplete="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder={t("login.emailPlaceholder")}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="password">{t("login.passwordLabel")}</Label>
              <Input
                id="password"
                type="password"
                autoComplete="current-password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder={t("login.passwordPlaceholder")}
              />
            </div>

            {error && (
              <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                {error}
              </p>
            )}

            <Button type="submit" className="w-full" disabled={submitting}>
              {submitting && <Spinner />}
              {t("login.submit")}
            </Button>
          </form>

          <div className="mt-6 flex justify-center">
            <LanguageSwitcher />
          </div>
        </CardContent>
      </Card>
    </div>
  );
}