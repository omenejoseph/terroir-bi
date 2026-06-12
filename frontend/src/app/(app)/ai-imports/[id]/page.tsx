"use client";

import * as React from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { useAuth } from "@/lib/auth/context";
import { useAiImport, useCommitAiImport, useUpdateAiImportLine } from "@/hooks/use-ai-imports";
import { useCostCategories } from "@/hooks/use-costs";
import { useCreateCustomer, useCustomers } from "@/hooks/use-customers";
import { useCreateSupplier, useSuppliers } from "@/hooks/use-suppliers";
import { useTranslation } from "@/i18n/context";
import { majorToMinor, minorToMajorInput } from "@/lib/money";
import {
  COST_STATUSES,
  INFLOW_STATUSES,
  INVENTORY_CATEGORIES,
  ORDER_STATUSES,
  PAYMENT_METHODS,
  type AiImportLine,
  type AiLineStatus,
  type AiTargetType,
} from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";

const LINE_VARIANT: Record<AiLineStatus, "default" | "secondary" | "success" | "destructive"> = {
  pending: "secondary",
  approved: "default",
  edited: "default",
  rejected: "destructive",
  committed: "success",
};

/** Payload keys stored in minor units (edited/displayed in major units). */
const MONEY_FIELDS = new Set([
  "total_amount",
  "amount",
  "vat_amount",
  "unit_price",
  "default_price",
  "cost_per_unit",
  "shipping_cost",
]);

const DATE_FIELDS = new Set(["date", "due_date", "order_date", "paid_at", "received_at"]);

/** Valid status values for a target's `status` field (empty = no enum). */
function statusOptions(target: AiTargetType): readonly string[] {
  switch (target) {
    case "cost":
      return COST_STATUSES;
    case "inflow":
      return INFLOW_STATUSES;
    case "order":
      return ORDER_STATUSES;
    default:
      return [];
  }
}

/** "total_amount" → "Total amount". */
function humanize(key: string): string {
  const spaced = key.replace(/_/g, " ");
  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

/** Enum value → human label: "bank_transfer" → "Bank transfer", "PENDING" → "Pending". */
function labelize(value: string): string {
  const s = value.replace(/_/g, " ").toLowerCase();
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export default function AiImportReviewPage() {
  const { t } = useTranslation();
  const { can, hasModule } = useAuth();
  const params = useParams<{ id: string }>();
  const id = params.id;

  const query = useAiImport(id);
  const updateLine = useUpdateAiImportLine(id);
  const commit = useCommitAiImport(id);
  const categories = useCostCategories().data ?? [];
  const suppliers = useSuppliers().data?.data ?? [];
  const customers = useCustomers().data?.data ?? [];
  const [committedMessage, setCommittedMessage] = React.useState<string | null>(null);

  if (!hasModule("ai_data_entry") || !can("ai.use")) {
    return (
      <Card>
        <CardContent className="pt-6">{t("aiImports.disabled")}</CardContent>
      </Card>
    );
  }

  const imp = query.data;
  const manage = can("ai.manage");

  return (
    <div className="space-y-6">
      <Link
        href="/ai-imports"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("aiImports.review.back")}
      </Link>

      {query.isLoading || !imp ? (
        <Spinner />
      ) : imp.status === "uploaded" || imp.status === "processing" ? (
        <Card>
          <CardContent className="flex items-center gap-3 pt-6">
            <Spinner />
            <span>{t("aiImports.review.processing")}</span>
          </CardContent>
        </Card>
      ) : imp.status === "failed" ? (
        <Card>
          <CardContent className="pt-6">
            <p className="font-medium text-destructive">{t("aiImports.review.failed")}</p>
            <p className="text-sm text-muted-foreground">{imp.error}</p>
          </CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <div className="flex items-center justify-between">
              <h1 className="text-lg font-semibold">{imp.type_label}</h1>
              <Badge variant="secondary">{imp.status_label}</Badge>
            </div>

            {committedMessage && (
              <p className="rounded-md bg-success/10 px-3 py-2 text-sm text-success">{committedMessage}</p>
            )}

            {!imp.lines?.length ? (
              <p className="text-sm text-muted-foreground">{t("aiImports.review.noLines")}</p>
            ) : (
              <div className="space-y-3">
                {imp.lines.map((line) => (
                  <LineRow
                    key={line.id}
                    line={line}
                    canManage={manage}
                    categories={categories}
                    suppliers={suppliers}
                    customers={customers}
                    onUpdate={(input) => updateLine.mutate({ lineId: line.id, input })}
                  />
                ))}
              </div>
            )}

            {manage && (
              <div className="flex justify-end">
                <Button
                  onClick={() =>
                    commit.mutate(undefined, {
                      onSuccess: (updated) =>
                        setCommittedMessage(
                          t("aiImports.review.committed", { committed: updated.lines_committed ?? 0 }),
                        ),
                    })
                  }
                  disabled={commit.isPending}
                >
                  {commit.isPending ? t("aiImports.review.committing") : t("aiImports.review.commit")}
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

type ItemDraft = { description: string; quantity: string; unit_price: string };

/** Read-only line-item table (e.g. an order's items). Prices are minor units. */
function ItemsTable({ items }: { items: Array<Record<string, unknown>> }) {
  const { t } = useTranslation();

  if (items.length === 0) {
    return <span className="text-muted-foreground">{t("aiImports.review.item.empty")}</span>;
  }

  return (
    <div className="overflow-hidden rounded-md border border-border">
      <table className="w-full text-sm">
        <thead className="bg-muted/40 text-xs text-muted-foreground">
          <tr>
            <th className="px-3 py-2 text-left font-medium">{t("aiImports.review.item.description")}</th>
            <th className="px-3 py-2 text-right font-medium">{t("aiImports.review.item.quantity")}</th>
            <th className="px-3 py-2 text-right font-medium">{t("aiImports.review.item.unitPrice")}</th>
            <th className="px-3 py-2 text-right font-medium">{t("aiImports.review.item.total")}</th>
          </tr>
        </thead>
        <tbody>
          {items.map((it, i) => {
            const qty = Number(it.quantity ?? 0);
            const unit = Number(it.unit_price ?? 0);
            return (
              <tr key={i} className="border-t border-border">
                <td className="px-3 py-2">{String(it.description ?? "")}</td>
                <td className="px-3 py-2 text-right tabular-nums">{qty}</td>
                <td className="px-3 py-2 text-right tabular-nums">{minorToMajorInput(unit)}</td>
                <td className="px-3 py-2 text-right tabular-nums">{minorToMajorInput(qty * unit)}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

/** Editable line items: description, quantity, unit price (major units). */
function ItemsEditor({ items, onChange }: { items: ItemDraft[]; onChange: (items: ItemDraft[]) => void }) {
  const { t } = useTranslation();
  const update = (i: number, patch: Partial<ItemDraft>) =>
    onChange(items.map((it, idx) => (idx === i ? { ...it, ...patch } : it)));

  return (
    <div className="space-y-2">
      {items.map((it, i) => (
        <div key={i} className="flex flex-wrap items-center gap-2">
          <Input
            aria-label={`${t("aiImports.review.item.description")} ${i + 1}`}
            className="min-w-0 flex-1"
            value={it.description}
            onChange={(e) => update(i, { description: e.target.value })}
          />
          <Input
            aria-label={`${t("aiImports.review.item.quantity")} ${i + 1}`}
            type="number"
            min={0}
            className="w-20"
            value={it.quantity}
            onChange={(e) => update(i, { quantity: e.target.value })}
          />
          <Input
            aria-label={`${t("aiImports.review.item.unitPrice")} ${i + 1}`}
            type="number"
            min={0}
            step="0.01"
            className="w-28"
            value={it.unit_price}
            onChange={(e) => update(i, { unit_price: e.target.value })}
          />
          <Button
            type="button"
            variant="ghost"
            className="text-muted-foreground hover:text-destructive"
            onClick={() => onChange(items.filter((_, idx) => idx !== i))}
          >
            {t("aiImports.review.item.remove")}
          </Button>
        </div>
      ))}
      <Button
        type="button"
        variant="outline"
        onClick={() => onChange([...items, { description: "", quantity: "1", unit_price: "" }])}
      >
        {t("aiImports.review.item.add")}
      </Button>
    </div>
  );
}

type NamedEntity = { id: string; company_name: string };

/** Pick an existing supplier or create one inline (sets supplier_id on a cost). */
function SupplierLinkPicker({
  value,
  options,
  onChange,
}: {
  value: string;
  options: NamedEntity[];
  onChange: (id: string) => void;
}) {
  const { t } = useTranslation();
  const createSupplier = useCreateSupplier();
  const [creating, setCreating] = React.useState(false);
  const [name, setName] = React.useState("");

  async function create() {
    if (!name.trim()) return;
    const created = await createSupplier.mutateAsync({ company_name: name.trim() });
    onChange(created.id);
    setName("");
    setCreating(false);
  }

  if (creating) {
    return (
      <div className="flex flex-wrap items-center gap-2">
        <Input
          aria-label={t("aiImports.review.link.newSupplier")}
          placeholder={t("aiImports.review.link.newSupplier")}
          value={name}
          onChange={(e) => setName(e.target.value)}
          className="min-w-0 flex-1"
        />
        <Button type="button" onClick={create} disabled={createSupplier.isPending || !name.trim()}>
          {t("aiImports.review.link.create")}
        </Button>
        <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
          {t("aiImports.review.cancel")}
        </Button>
      </div>
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Select
        aria-label={t("aiImports.review.link.supplier")}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="min-w-0 flex-1"
      >
        <option value="">{t("aiImports.review.link.none")}</option>
        {options.map((o) => (
          <option key={o.id} value={o.id}>
            {o.company_name}
          </option>
        ))}
      </Select>
      <Button type="button" variant="outline" onClick={() => setCreating(true)}>
        {t("aiImports.review.link.new")}
      </Button>
    </div>
  );
}

/** Pick an existing customer or create one inline (sets customer_id on an order). */
function CustomerLinkPicker({
  value,
  options,
  onChange,
  suggestedName = "",
}: {
  value: string;
  options: NamedEntity[];
  onChange: (id: string) => void;
  /** The AI-extracted customer name — used to prefill the create form. */
  suggestedName?: string;
}) {
  const { t } = useTranslation();
  const createCustomer = useCreateCustomer();
  const [creating, setCreating] = React.useState(false);
  const [name, setName] = React.useState(suggestedName);
  const [email, setEmail] = React.useState("");

  async function create() {
    if (!name.trim() || !email.trim()) return;
    const created = await createCustomer.mutateAsync({ company_name: name.trim(), email: email.trim() });
    onChange(created.id);
    setEmail("");
    setCreating(false);
  }

  // Carry the AI name into the create form when opening it unmodified.
  const openCreate = () => {
    if (!name.trim()) setName(suggestedName);
    setCreating(true);
  };

  if (creating) {
    return (
      <div className="flex flex-wrap items-center gap-2">
        <Input
          aria-label={t("aiImports.review.link.newCustomer")}
          placeholder={t("aiImports.review.link.newCustomer")}
          value={name}
          onChange={(e) => setName(e.target.value)}
          className="min-w-0 flex-1"
        />
        <Input
          aria-label={t("aiImports.review.link.newCustomerEmail")}
          type="email"
          placeholder={t("aiImports.review.link.newCustomerEmail")}
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          className="min-w-0 flex-1"
        />
        <Button type="button" onClick={create} disabled={createCustomer.isPending || !name.trim() || !email.trim()}>
          {t("aiImports.review.link.create")}
        </Button>
        <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
          {t("aiImports.review.cancel")}
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-1">
      <div className="flex flex-wrap items-center gap-2">
        <Select
          aria-label={t("aiImports.review.link.customer")}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className="min-w-0 flex-1"
        >
          <option value="">{t("aiImports.review.link.none")}</option>
          {options.map((o) => (
            <option key={o.id} value={o.id}>
              {o.company_name}
            </option>
          ))}
        </Select>
        <Button type="button" variant="outline" onClick={openCreate}>
          {t("aiImports.review.link.new")}
        </Button>
      </div>
      {value === "" && suggestedName.trim() !== "" && (
        <p className="text-xs text-muted-foreground">
          {t("aiImports.review.link.noMatch", { name: suggestedName })}
        </p>
      )}
    </div>
  );
}

function LineRow({
  line,
  canManage,
  categories,
  suppliers,
  customers,
  onUpdate,
}: {
  line: AiImportLine;
  canManage: boolean;
  categories: string[];
  suppliers: NamedEntity[];
  customers: NamedEntity[];
  onUpdate: (input: { status: AiLineStatus; edited_payload?: Record<string, unknown> }) => void;
}) {
  const { t } = useTranslation();
  const [editing, setEditing] = React.useState(false);
  const [draft, setDraft] = React.useState<Record<string, string>>({});
  const [items, setItems] = React.useState<ItemDraft[]>([]);
  const [supplierId, setSupplierId] = React.useState("");
  const [customerId, setCustomerId] = React.useState("");

  const payload = line.effective_payload;
  const target = line.target_type;
  const committed = line.status === "committed";
  const hasItems = Array.isArray(payload.items);
  // The supplier/customer link is rendered as a dedicated picker, so the raw id
  // keys are kept out of the generic field grid.
  const linksSupplier = target === "cost";
  const linksCustomer = target === "order";

  // The AI's free-text customer name, and the existing customer it matches (if any).
  const aiCustomerName = typeof payload.customer_name === "string" ? payload.customer_name : "";
  const matchCustomerId = (name: string): string => {
    const n = name.trim().toLowerCase();
    return n ? (customers.find((c) => c.company_name.toLowerCase() === n)?.id ?? "") : "";
  };

  const startEdit = () => {
    setSupplierId(String(payload.supplier_id ?? ""));
    // Prefer an explicit id; otherwise auto-match the AI name to an existing customer.
    setCustomerId(String(payload.customer_id ?? "") || matchCustomerId(aiCustomerName));
    const next: Record<string, string> = {};
    for (const [k, v] of Object.entries(payload)) {
      if (k === "items") {
        continue; // edited via the structured items editor
      } else if (k === "supplier_id" || k === "customer_id") {
        continue; // edited via the entity link picker
      } else if (MONEY_FIELDS.has(k)) {
        next[k] = minorToMajorInput(typeof v === "number" ? v : Number(v));
      } else if (typeof v === "object" && v !== null) {
        next[k] = JSON.stringify(v, null, 2);
      } else {
        next[k] = String(v ?? "");
      }
    }
    setDraft(next);
    setItems(
      (Array.isArray(payload.items) ? (payload.items as Array<Record<string, unknown>>) : []).map((it) => ({
        description: String(it.description ?? ""),
        quantity: String(it.quantity ?? 1),
        unit_price: minorToMajorInput(Number(it.unit_price ?? 0)),
      })),
    );
    setEditing(true);
  };

  const save = () => {
    const edited: Record<string, unknown> = {};
    for (const [k, raw] of Object.entries(draft)) {
      const original = payload[k];
      if (MONEY_FIELDS.has(k)) {
        edited[k] = raw === "" ? null : majorToMinor(raw);
      } else if (typeof original === "object" && original !== null) {
        try {
          edited[k] = JSON.parse(raw);
        } catch {
          edited[k] = original;
        }
      } else if (typeof original === "number") {
        edited[k] = raw === "" ? null : Number(raw);
      } else {
        edited[k] = raw;
      }
    }
    if (hasItems) {
      edited.items = items.map((it) => ({
        description: it.description,
        quantity: Number(it.quantity) || 1,
        unit_price: majorToMinor(it.unit_price) ?? 0, // back to minor units
      }));
    }
    if (linksSupplier) edited.supplier_id = supplierId || null;
    if (linksCustomer) edited.customer_id = customerId || null;

    onUpdate({ status: "edited", edited_payload: edited });
    setEditing(false);
  };

  const set = (key: string, value: string) => setDraft((d) => ({ ...d, [key]: value }));

  const editor = (key: string, value: unknown) => {
    const val = draft[key] ?? "";
    const statuses = statusOptions(target);

    if (key === "items") {
      return <ItemsEditor items={items} onChange={setItems} />;
    }
    if (key === "status" && statuses.length > 0) {
      return (
        <Select aria-label={key} value={val} onChange={(e) => set(key, e.target.value)}>
          {statuses.map((s) => (
            <option key={s} value={s}>
              {labelize(s)}
            </option>
          ))}
        </Select>
      );
    }
    if (key === "payment_method") {
      return (
        <Select aria-label={key} value={val} onChange={(e) => set(key, e.target.value)}>
          <option value="">—</option>
          {PAYMENT_METHODS.map((m) => (
            <option key={m} value={m}>
              {labelize(m)}
            </option>
          ))}
        </Select>
      );
    }
    if (key === "category" && target === "inventory_item") {
      return (
        <Select aria-label={key} value={val} onChange={(e) => set(key, e.target.value)}>
          {INVENTORY_CATEGORIES.map((c) => (
            <option key={c} value={c}>
              {labelize(c)}
            </option>
          ))}
        </Select>
      );
    }
    if (key === "category") {
      const listId = `cat-${line.id}`;
      return (
        <>
          <Input aria-label={key} value={val} list={listId} onChange={(e) => set(key, e.target.value)} />
          <datalist id={listId}>
            {categories.map((c) => (
              <option key={c} value={c} />
            ))}
          </datalist>
        </>
      );
    }
    if (typeof value === "object" && value !== null) {
      return (
        <textarea
          aria-label={key}
          className="w-full rounded-md border border-border bg-background px-2 py-1 font-mono text-xs"
          rows={4}
          value={val}
          onChange={(e) => set(key, e.target.value)}
        />
      );
    }
    return (
      <Input
        aria-label={key}
        type={MONEY_FIELDS.has(key) ? "number" : DATE_FIELDS.has(key) ? "date" : "text"}
        step={MONEY_FIELDS.has(key) ? "0.01" : undefined}
        value={val}
        onChange={(e) => set(key, e.target.value)}
      />
    );
  };

  const display = (key: string, value: unknown) => {
    if (value === null || value === undefined || value === "") return "—";
    if (key === "items" && Array.isArray(value)) return <ItemsTable items={value as Array<Record<string, unknown>>} />;
    if (MONEY_FIELDS.has(key)) return minorToMajorInput(typeof value === "number" ? value : Number(value));
    if (typeof value === "object") return <code className="text-xs break-all">{JSON.stringify(value)}</code>;
    // Show enum-ish values in human form (the raw value is kept on edit/commit).
    const isEnum = key === "status" || key === "payment_method" || (key === "category" && target === "inventory_item");
    if (isEnum && typeof value === "string") return labelize(value);
    return String(value);
  };

  // Best-known link name for read mode: explicit id → name-match → the raw AI name.
  const linkedName = linksSupplier
    ? suppliers.find((s) => s.id === payload.supplier_id)?.company_name
    : (customers.find((c) => c.id === payload.customer_id)?.company_name ??
        customers.find((c) => c.id === matchCustomerId(aiCustomerName))?.company_name ??
        (aiCustomerName || undefined));

  return (
    <div className="rounded-lg border border-border p-4" data-testid="ai-line">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <Badge>{line.target_label}</Badge>
          <Badge variant={LINE_VARIANT[line.status]}>{line.status}</Badge>
          {line.confidence !== null && (
            <span className="text-xs text-muted-foreground">
              {t("aiImports.review.confidence")}: {Math.round(line.confidence * 100)}%
            </span>
          )}
        </div>
        {canManage && !committed && (
          <div className="flex gap-2">
            {editing ? (
              <>
                <Button variant="outline" onClick={() => setEditing(false)}>
                  {t("aiImports.review.cancel")}
                </Button>
                <Button onClick={save}>{t("aiImports.review.save")}</Button>
              </>
            ) : (
              <>
                <Button variant="outline" onClick={startEdit}>
                  {t("aiImports.review.edit")}
                </Button>
                {line.status !== "rejected" && (
                  <Button variant="outline" onClick={() => onUpdate({ status: "rejected" })}>
                    {t("aiImports.review.reject")}
                  </Button>
                )}
                {line.status !== "approved" && (
                  <Button onClick={() => onUpdate({ status: "approved" })}>
                    {t("aiImports.review.approve")}
                  </Button>
                )}
              </>
            )}
          </div>
        )}
      </div>

      <dl className="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
        {Object.entries(payload)
          // ids + the raw customer name are surfaced via the link picker below.
          .filter(([key]) => key !== "supplier_id" && key !== "customer_id" && key !== "customer_name")
          .map(([key, value]) => (
            <div key={key} className={`min-w-0 text-sm ${key === "items" ? "sm:col-span-2" : ""}`}>
              <dt className="mb-1 text-xs font-medium text-muted-foreground">{humanize(key)}</dt>
              <dd className="break-words">{editing ? editor(key, value) : display(key, value)}</dd>
            </div>
          ))}
      </dl>

      {(linksSupplier || linksCustomer) && (
        <div className="mt-4 text-sm">
          <p className="mb-1 text-xs font-medium text-muted-foreground">
            {linksSupplier ? t("aiImports.review.link.supplier") : t("aiImports.review.link.customer")}
          </p>
          {editing ? (
            linksSupplier ? (
              <SupplierLinkPicker value={supplierId} options={suppliers} onChange={setSupplierId} />
            ) : (
              <CustomerLinkPicker
                value={customerId}
                options={customers}
                onChange={setCustomerId}
                suggestedName={aiCustomerName}
              />
            )
          ) : (
            <p className="break-words">{linkedName ?? "—"}</p>
          )}
        </div>
      )}
    </div>
  );
}

