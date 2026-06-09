"use client";

import * as React from "react";
import { Check } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useSettings, useUpdateSettings } from "@/hooks/use-settings";
import { useTranslation } from "@/i18n/context";
import { SUPPORTED_LOCALES } from "@/lib/config";
import { LOCALE_LABELS } from "@/i18n/config";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";

/** All IANA timezones the runtime knows, with a small fallback for old engines. */
function timezones(): string[] {
  const supported = (Intl as { supportedValuesOf?: (k: string) => string[] }).supportedValuesOf;
  if (typeof supported === "function") return supported("timeZone");
  return ["UTC", "Europe/Zagreb", "Europe/London", "Europe/Paris", "America/New_York"];
}

export function GeneralSettings() {
  const { t } = useTranslation();
  const { refreshSession } = useAuth();
  const settingsQ = useSettings();
  const update = useUpdateSettings();

  const [name, setName] = React.useState("");
  const [locale, setLocale] = React.useState("");
  const [timezone, setTimezone] = React.useState("");
  const [oib, setOib] = React.useState("");
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [error, setError] = React.useState<string | null>(null);
  const [saved, setSaved] = React.useState(false);

  const settings = settingsQ.data;

  // Sync local form state once the settings load (or change).
  React.useEffect(() => {
    if (settings) {
      setName(settings.name);
      setLocale(settings.default_locale);
      setTimezone(settings.timezone);
      setOib(settings.company_oib ?? "");
    }
  }, [settings]);

  const tzOptions = React.useMemo(() => timezones(), []);

  async function save(event: React.SyntheticEvent) {
    event.preventDefault();
    setErrors({});
    setError(null);
    setSaved(false);
    try {
      await update.mutateAsync({
        name: name.trim(),
        default_locale: locale,
        timezone,
        company_oib: oib.trim() || null,
      });
      await refreshSession();
      setSaved(true);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const flat: Record<string, string> = {};
        for (const [field, messages] of Object.entries(err.errors)) {
          if (messages[0]) flat[field] = messages[0];
        }
        setErrors(flat);
        setError(err.message);
      } else {
        setError(t("settings.general.errorGeneric"));
      }
    }
  }

  if (settingsQ.isLoading) {
    return (
      <div className="flex justify-center py-16">
        <Spinner className="size-6 text-muted-foreground" />
      </div>
    );
  }

  if (settingsQ.isError || !settings) {
    return (
      <Card>
        <CardContent className="py-12 text-center text-sm text-destructive">
          {t("settings.general.errorLoad")}
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardContent className="pt-6">
        <form onSubmit={save} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="org_name">{t("settings.general.name")}</Label>
            <Input
              id="org_name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="org_locale">{t("settings.general.language")}</Label>
              <Select id="org_locale" value={locale} onChange={(e) => setLocale(e.target.value)}>
                {SUPPORTED_LOCALES.map((loc) => (
                  <option key={loc} value={loc}>
                    {LOCALE_LABELS[loc]}
                  </option>
                ))}
              </Select>
              {errors.default_locale && (
                <p className="text-sm text-destructive">{errors.default_locale}</p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="org_currency">{t("settings.general.currency")}</Label>
              <Input id="org_currency" value={settings.default_currency} readOnly disabled />
              <p className="text-xs text-muted-foreground">{t("settings.general.currencyHint")}</p>
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="org_timezone">{t("settings.general.timezone")}</Label>
            <Select id="org_timezone" value={timezone} onChange={(e) => setTimezone(e.target.value)}>
              {tzOptions.map((tz) => (
                <option key={tz} value={tz}>
                  {tz}
                </option>
              ))}
            </Select>
            {errors.timezone && <p className="text-sm text-destructive">{errors.timezone}</p>}
          </div>

          <div className="space-y-2">
            <Label htmlFor="org_oib">{t("settings.general.oib")}</Label>
            <Input id="org_oib" value={oib} onChange={(e) => setOib(e.target.value)} />
            {errors.company_oib && <p className="text-sm text-destructive">{errors.company_oib}</p>}
          </div>

          {error && (
            <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>
          )}

          <div className="flex items-center justify-end gap-3 border-t border-border pt-4">
            {saved && (
              <span className="flex items-center gap-1.5 text-sm text-success">
                <Check className="size-4" />
                {t("settings.general.saved")}
              </span>
            )}
            <Button type="submit" disabled={update.isPending}>
              {update.isPending && <Spinner />}
              {t("settings.general.save")}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
