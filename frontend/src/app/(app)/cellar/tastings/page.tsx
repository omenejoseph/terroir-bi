"use client";

import * as React from "react";
import { Plus } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { useFormatters } from "@/lib/format";
import { useCreateTastingReport, useTastingReports } from "@/hooks/use-cellar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import { CellarPageHeader } from "@/components/cellar/cellar-page-header";

export default function TastingsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { date } = useFormatters();
  const { data, isLoading } = useTastingReports();
  const create = useCreateTastingReport();
  const [open, setOpen] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  return (
    <div className="space-y-6">
      <CellarPageHeader
        title={t("cellar.tastingsPage.title")}
        subtitle={t("cellar.tastingsPage.subtitle")}
        actions={
          can("cellar.manage") && (
            <Button onClick={() => setOpen(true)}>
              <Plus className="size-4" />
              {t("cellar.tastingsPage.add")}
            </Button>
          )
        }
      />

      {isLoading ? (
        <div className="flex h-32 items-center justify-center">
          <Spinner />
        </div>
      ) : (data ?? []).length === 0 ? (
        <Card>
          <CardContent className="p-6 text-sm text-muted-foreground">{t("cellar.tastingsPage.empty")}</CardContent>
        </Card>
      ) : (
        <div className="space-y-2">
          {(data ?? []).map((r) => (
            <Card key={r.id}>
              <CardContent className="flex items-center justify-between p-4">
                <div>
                  <div className="font-medium">{r.title ?? date(r.date)}</div>
                  {r.note && <div className="text-sm text-muted-foreground">{r.note}</div>}
                </div>
                <Badge variant="secondary">{r.notes_count}</Badge>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {open && (
        <Dialog open onOpenChange={setOpen} title={t("cellar.tastingsPage.add")}>
          <form
            className="space-y-4 p-6 pt-2"
            onSubmit={async (e) => {
              e.preventDefault();
              setError(null);
              const f = new FormData(e.currentTarget);
              try {
                await create.mutateAsync({
                  title: String(f.get("title") ?? "") || null,
                  note: String(f.get("note") ?? "") || null,
                });
                setOpen(false);
              } catch (err) {
                setError(err instanceof ApiError ? err.message : "Error");
              }
            }}
          >
            <div>
              <Label>{t("cellar.tastingsPage.reportTitle")}</Label>
              <Input name="title" />
            </div>
            <div>
              <Label>{t("cellar.tastingsPage.notes")}</Label>
              <Input name="note" />
            </div>
            {error && <p className="text-sm text-destructive">{error}</p>}
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setOpen(false)}>
                {t("cellar.actions.cancel")}
              </Button>
              <Button type="submit" disabled={create.isPending}>
                {t("cellar.actions.save")}
              </Button>
            </div>
          </form>
        </Dialog>
      )}
    </div>
  );
}
