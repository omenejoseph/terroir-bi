"use client";

import * as React from "react";
import { RotateCcw } from "lucide-react";

import { translationsApi } from "@/lib/api/translations";
import { useTranslation } from "@/i18n/context";
import { MESSAGES } from "@/i18n/config";
import { SUPPORTED_LOCALES, type Locale } from "@/lib/config";
import { LOCALE_LABELS } from "@/i18n/config";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";

/** Flatten a nested message catalog into sorted [key, sourceText] pairs. */
function flattenCatalog(catalog: unknown, prefix = "", out: Array<[string, string]> = []) {
  if (catalog && typeof catalog === "object") {
    for (const [k, v] of Object.entries(catalog as Record<string, unknown>)) {
      const path = prefix ? `${prefix}.${k}` : k;
      if (v && typeof v === "object") flattenCatalog(v, path, out);
      else if (typeof v === "string") out.push([path, v]);
    }
  }
  return out;
}

export function TranslationsEditor() {
  const { t, locale, refreshOverrides } = useTranslation();

  const [editLocale, setEditLocale] = React.useState<Locale>(locale);
  // Follow the active locale until the user explicitly picks one (locale hydrates
  // from storage after first render).
  const userPicked = React.useRef(false);
  React.useEffect(() => {
    if (!userPicked.current) setEditLocale(locale);
  }, [locale]);

  const [overrides, setOverrides] = React.useState<Record<string, string>>({});
  const [drafts, setDrafts] = React.useState<Record<string, string>>({});
  const [loading, setLoading] = React.useState(true);
  const [search, setSearch] = React.useState("");
  const [busyKey, setBusyKey] = React.useState<string | null>(null);

  // The bundled catalog is the canonical key list + source (human) text.
  const entries = React.useMemo(() => flattenCatalog(MESSAGES[editLocale]), [editLocale]);

  const loadOverrides = React.useCallback(async () => {
    setLoading(true);
    try {
      setOverrides(await translationsApi.overrides(editLocale));
    } catch {
      setOverrides({});
    } finally {
      setLoading(false);
    }
  }, [editLocale]);

  React.useEffect(() => {
    void loadOverrides();
    setDrafts({});
  }, [loadOverrides]);

  // Search the human text (and override value). Keys are intentionally not shown.
  const q = search.trim().toLowerCase();
  const filtered = q
    ? entries.filter(
        ([key, src]) =>
          src.toLowerCase().includes(q) || (overrides[key]?.toLowerCase().includes(q) ?? false),
      )
    : entries;

  async function save(key: string, value: string) {
    setBusyKey(key);
    try {
      await translationsApi.update(editLocale, key, value);
      setOverrides((o) => ({ ...o, [key]: value }));
      setDrafts((d) => {
        const next = { ...d };
        delete next[key];
        return next;
      });
      if (editLocale === locale) await refreshOverrides();
    } finally {
      setBusyKey(null);
    }
  }

  async function revert(key: string) {
    setBusyKey(key);
    try {
      await translationsApi.remove(editLocale, key);
      setOverrides((o) => {
        const next = { ...o };
        delete next[key];
        return next;
      });
      setDrafts((d) => {
        const next = { ...d };
        delete next[key];
        return next;
      });
      if (editLocale === locale) await refreshOverrides();
    } finally {
      setBusyKey(null);
    }
  }

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <p className="text-sm text-muted-foreground">{t("settings.translations.description")}</p>

        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <div className="space-y-2">
            <Label htmlFor="edit_locale">{t("settings.translations.localeLabel")}</Label>
            <Select
              id="edit_locale"
              value={editLocale}
              onChange={(e) => {
                userPicked.current = true;
                setEditLocale(e.target.value as Locale);
              }}
              className="sm:w-40"
            >
              {SUPPORTED_LOCALES.map((loc) => (
                <option key={loc} value={loc}>
                  {LOCALE_LABELS[loc]}
                </option>
              ))}
            </Select>
          </div>
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t("settings.translations.search")}
            className="sm:max-w-xs"
          />
        </div>

        {loading ? (
          <div className="flex justify-center py-12">
            <Spinner className="size-6 text-muted-foreground" />
          </div>
        ) : filtered.length === 0 ? (
          <p className="py-12 text-center text-sm text-muted-foreground">
            {t("settings.translations.empty")}
          </p>
        ) : (
          <div className="max-h-[60vh] divide-y divide-border overflow-y-auto rounded-md border border-border">
            {filtered.map(([key, source]) => {
              const current = drafts[key] ?? overrides[key] ?? source;
              const overridden = overrides[key] !== undefined;
              const dirty = current !== (overrides[key] ?? source);
              return (
                <div key={key} className="grid grid-cols-1 gap-2 p-3 sm:grid-cols-[1fr_1.4fr]">
                  {/* Human label only — the technical key stays hidden. */}
                  <div className="flex min-w-0 items-center">
                    <p className="truncate text-sm font-medium text-foreground" title={source}>
                      {source}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Input
                      aria-label={source}
                      value={current}
                      onChange={(e) => setDrafts((d) => ({ ...d, [key]: e.target.value }))}
                    />
                    <Button
                      type="button"
                      size="sm"
                      onClick={() => save(key, current)}
                      disabled={!dirty || busyKey === key}
                    >
                      {busyKey === key ? <Spinner /> : t("settings.translations.save")}
                    </Button>
                    {overridden && (
                      <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label={`${t("settings.translations.revert")}: ${source}`}
                        onClick={() => revert(key)}
                        disabled={busyKey === key}
                      >
                        <RotateCcw className="size-4" />
                      </Button>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
