"use client";

import * as React from "react";
import { useRouter } from "next/navigation";

import { useAuth } from "@/lib/auth/context";
import { useAiImports, useDeleteAiImport, useUploadAiImport } from "@/hooks/use-ai-imports";
import { useTranslation } from "@/i18n/context";
import { AI_IMPORT_TYPES, type AiImport, type AiImportStatus, type AiImportType } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Dropzone } from "@/components/ui/dropzone";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";

const STATUS_VARIANT: Record<AiImportStatus, "default" | "secondary" | "success" | "destructive"> = {
  uploaded: "secondary",
  processing: "secondary",
  ready: "default",
  partially_committed: "default",
  committed: "success",
  failed: "destructive",
};

export default function AiImportsPage() {
  const { t } = useTranslation();
  const { can, hasModule } = useAuth();
  const router = useRouter();
  const confirm = useConfirm();

  const [type, setType] = React.useState<AiImportType>("bank_statement");
  const imports = useAiImports();
  const upload = useUploadAiImport();
  const remove = useDeleteAiImport();

  if (!hasModule("ai_data_entry") || !can("ai.use")) {
    return (
      <Card>
        <CardContent className="pt-6">{t("aiImports.disabled")}</CardContent>
      </Card>
    );
  }

  const onFile = (file: File) => {
    upload.mutate(
      { type, file },
      { onSuccess: (created: AiImport) => router.push(`/ai-imports/${created.id}`) },
    );
  };

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold">{t("aiImports.title")}</h1>
        <p className="text-sm text-muted-foreground">{t("aiImports.subtitle")}</p>
      </header>

      {can("ai.manage") && (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <h2 className="font-medium">{t("aiImports.upload.title")}</h2>
            <div className="max-w-xs space-y-1">
              <label className="text-sm font-medium" htmlFor="ai-type">
                {t("aiImports.upload.type")}
              </label>
              <Select
                id="ai-type"
                value={type}
                onChange={(e) => setType(e.target.value as AiImportType)}
              >
                {AI_IMPORT_TYPES.map((value) => (
                  <option key={value} value={value}>
                    {t(`aiImports.types.${value}`)}
                  </option>
                ))}
              </Select>
            </div>
            <Dropzone
              accept=".pdf,.png,.jpg,.jpeg,.webp,.csv,.xls,.xlsx"
              onFile={onFile}
              inputLabel={t("aiImports.upload.button")}
              title={t("aiImports.upload.dropzone")}
              hint={t("aiImports.upload.hint")}
              busy={upload.isPending}
            />
          </CardContent>
        </Card>
      )}

      <Card>
        <CardContent className="pt-6">
          <h2 className="mb-3 font-medium">{t("aiImports.list.title")}</h2>
          {imports.isLoading ? (
            <Spinner />
          ) : !imports.data?.length ? (
            <p className="text-sm text-muted-foreground">{t("aiImports.list.empty")}</p>
          ) : (
            <table className="w-full text-sm">
              <tbody>
                {imports.data.map((imp) => (
                  <tr key={imp.id} className="border-b border-border last:border-0">
                    <td className="py-2.5 pr-3">
                      <div className="font-medium">{imp.type_label}</div>
                      <div className="text-xs text-muted-foreground">{imp.source_filename}</div>
                    </td>
                    <td className="py-2.5 pr-3">
                      <Badge variant={STATUS_VARIANT[imp.status]}>{imp.status_label}</Badge>
                    </td>
                    <td className="py-2.5 pr-3 text-xs text-muted-foreground">
                      {imp.lines_pending ? t("aiImports.list.pending", { count: imp.lines_pending }) : null}
                      {imp.lines_committed
                        ? ` ${t("aiImports.list.committed", { count: imp.lines_committed })}`
                        : null}
                    </td>
                    <td className="py-2.5 pr-3 text-right">
                      <Button variant="outline" onClick={() => router.push(`/ai-imports/${imp.id}`)}>
                        {t("aiImports.list.review")}
                      </Button>
                    </td>
                    <td className="py-2.5 text-right">
                      {can("ai.manage") && (
                        <Button
                          variant="destructive"
                          onClick={async () => {
                            if (await confirm({ title: t("aiImports.list.deleteConfirm"), tone: "danger" })) {
                              remove.mutate(imp.id);
                            }
                          }}
                        >
                          {t("aiImports.list.delete")}
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
