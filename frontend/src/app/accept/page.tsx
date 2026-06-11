"use client";

import * as React from "react";
import { Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Check } from "lucide-react";

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

type Phase = "intro" | "profile" | "success";

function AcceptInvitationForm() {
  const { acceptInvitation } = useAuth();
  const { t } = useTranslation();
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = searchParams.get("token") ?? "";

  const [phase, setPhase] = React.useState<Phase>("intro");
  const [submitting, setSubmitting] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [errors, setErrors] = React.useState<Record<string, string>>({});

  const [firstName, setFirstName] = React.useState("");
  const [middleName, setMiddleName] = React.useState("");
  const [lastName, setLastName] = React.useState("");
  const [password, setPassword] = React.useState("");

  function finish() {
    setPhase("success");
    setTimeout(() => router.replace("/dashboard"), 800);
  }

  function applyError(err: unknown) {
    if (err instanceof ApiError) {
      // Invalid/expired token — nothing the form can fix.
      if (err.fieldError("token")) {
        setError(t("accept.invalidToken"));
        return false;
      }
      // The backend signals "new account, profile required" via a password error.
      if (err.status === 422 && (err.fieldError("password") || err.fieldError("first_name"))) {
        const flat: Record<string, string> = {};
        for (const [field, messages] of Object.entries(err.errors ?? {})) {
          if (messages[0]) flat[field] = messages[0];
        }
        setErrors(flat);
        return true; // needs profile
      }
      // 4xx messages are user-facing (localized); never surface a raw 5xx body.
      setError(err.status < 500 ? err.message : t("accept.errorGeneric"));
      return false;
    }
    setError(t("accept.errorGeneric"));
    return false;
  }

  async function handleAccept() {
    setError(null);
    setSubmitting(true);
    try {
      // Existing accounts only need the token; new ones will 422 → profile form.
      await acceptInvitation({ token });
      finish();
    } catch (err) {
      if (applyError(err)) setPhase("profile");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleCreate(event: React.SyntheticEvent) {
    event.preventDefault();
    setError(null);
    setErrors({});
    setSubmitting(true);
    try {
      await acceptInvitation({
        token,
        first_name: firstName.trim(),
        middle_name: middleName.trim() || null,
        last_name: lastName.trim(),
        password,
      });
      finish();
    } catch (err) {
      applyError(err);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="flex min-h-dvh items-center justify-center bg-muted/30 px-4 py-12">
      <Card className="w-full max-w-sm">
        <CardHeader className="items-center text-center">
          <Logo className="mb-1 size-20" />
          {phase === "profile" ? (
            <>
              <CardTitle className="text-xl">{t("accept.createHeading")}</CardTitle>
              <CardDescription>{t("accept.createSubtitle")}</CardDescription>
            </>
          ) : (
            <>
              <CardTitle className="text-xl">{t("accept.heading")}</CardTitle>
              <CardDescription>{t("accept.subtitle", { app: APP_NAME })}</CardDescription>
            </>
          )}
        </CardHeader>
        <CardContent>
          {!token ? (
            <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
              {t("accept.missingToken")}
            </p>
          ) : phase === "success" ? (
            <div className="flex flex-col items-center gap-3 py-4 text-center">
              <span className="flex size-10 items-center justify-center rounded-full bg-success/10 text-success">
                <Check className="size-5" />
              </span>
              <p className="text-sm text-muted-foreground">{t("accept.success")}</p>
            </div>
          ) : phase === "profile" ? (
            <form onSubmit={handleCreate} className="space-y-4">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="first_name">{t("accept.firstName")}</Label>
                  <Input
                    id="first_name"
                    value={firstName}
                    onChange={(e) => setFirstName(e.target.value)}
                    autoComplete="given-name"
                    required
                  />
                  {errors.first_name && <p className="text-sm text-destructive">{errors.first_name}</p>}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="last_name">{t("accept.lastName")}</Label>
                  <Input
                    id="last_name"
                    value={lastName}
                    onChange={(e) => setLastName(e.target.value)}
                    autoComplete="family-name"
                    required
                  />
                  {errors.last_name && <p className="text-sm text-destructive">{errors.last_name}</p>}
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="middle_name">{t("accept.middleName")}</Label>
                <Input
                  id="middle_name"
                  value={middleName}
                  onChange={(e) => setMiddleName(e.target.value)}
                  autoComplete="additional-name"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="password">{t("accept.password")}</Label>
                <Input
                  id="password"
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  autoComplete="new-password"
                  required
                  minLength={8}
                />
                <p className="text-xs text-muted-foreground">{t("accept.passwordHint")}</p>
                {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
              </div>

              {error && (
                <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>
              )}

              <Button type="submit" className="w-full" disabled={submitting}>
                {submitting && <Spinner />}
                {t("accept.submit")}
              </Button>
            </form>
          ) : (
            <div className="space-y-4">
              {error && (
                <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>
              )}
              <Button type="button" className="w-full" onClick={handleAccept} disabled={submitting}>
                {submitting && <Spinner />}
                {t("accept.cta")}
              </Button>
            </div>
          )}

          <div className="mt-6 flex justify-center">
            <LanguageSwitcher />
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

export default function AcceptInvitationPage() {
  return (
    <Suspense fallback={null}>
      <AcceptInvitationForm />
    </Suspense>
  );
}