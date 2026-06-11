"use client";

import * as React from "react";
import { Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import {
  useItemTierPrices,
  useSetItemTierPrice,
  useRemoveItemTierPrice,
  useItemCustomerPrices,
  useSetItemCustomerPrice,
  useRemoveItemCustomerPrice,
} from "@/hooks/use-inventory";
import { useCreatePricingTier, usePricingTiers } from "@/hooks/use-customers";
import { useAuth } from "@/lib/auth/context";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import { majorToMinor, minorToMajorInput } from "@/lib/money";
import { cn } from "@/lib/utils";
import type { Customer, InventoryItem, Money } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";
import { CustomerPicker } from "@/components/customers/customer-picker";

/**
 * A badge showing a price's change vs. the item's default price — so it's obvious
 * how much a tier/customer price differs. Green for a discount, amber for a markup.
 * Renders nothing when there's no positive default to compare against.
 */
function Discount({ price, defaultPrice }: { price: Money; defaultPrice: Money | null }) {
  const base = defaultPrice?.minor;
  if (!base || base <= 0) return null;
  const pct = ((price.minor - base) / base) * 100;
  const label = `${pct > 0 ? "+" : ""}${pct.toFixed(1)}%`;
  return (
    <span
      className={cn(
        "rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums",
        pct <= 0 ? "bg-emerald-500/10 text-emerald-600" : "bg-amber-500/10 text-amber-600",
      )}
    >
      {label}
    </span>
  );
}

/** Live discount badge for a major-unit price being typed into an add form. */
function LiveDiscount({ value, defaultPrice }: { value: string; defaultPrice: Money | null }) {
  const minor = majorToMinor(value);
  if (minor === null || !defaultPrice) return null;
  return <Discount price={{ minor, currency: defaultPrice.currency }} defaultPrice={defaultPrice} />;
}

export function PricingTab({ item }: { item: InventoryItem; canManage?: boolean }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { moneyObject } = useFormatters();
  // Pricing writes are gated by pricing.manage (distinct from inventory.manage).
  const canManage = can("pricing.manage");

  return (
    <div className="space-y-4">
      <Card>
        <CardContent className="flex items-center justify-between pt-6">
          <p className="text-sm text-muted-foreground">{t("inventory.pricing.defaultPrice")}</p>
          <p className="text-xl font-semibold tabular-nums">
            {item.default_price ? moneyObject(item.default_price) : "—"}
          </p>
        </CardContent>
      </Card>

      <TierPricing item={item} canManage={canManage} />
      <CustomerPricing item={item} canManage={canManage} />
    </div>
  );
}

function TierPricing({ item, canManage }: { item: InventoryItem; canManage: boolean }) {
  const { t } = useTranslation();
  const { moneyObject } = useFormatters();
  const confirm = useConfirm();
  const listQ = useItemTierPrices(item.id);
  const tiersQ = usePricingTiers();
  const setPrice = useSetItemTierPrice(item.id);
  const removePrice = useRemoveItemTierPrice(item.id);
  const createTier = useCreatePricingTier();

  const [tierId, setTierId] = React.useState("");
  const [price, setPriceInput] = React.useState("");
  const [error, setError] = React.useState<string | null>(null);

  // Inline "create a new tier" form.
  const [creating, setCreating] = React.useState(false);
  const [newName, setNewName] = React.useState("");
  const [newRebate, setNewRebate] = React.useState("");
  const [createError, setCreateError] = React.useState<string | null>(null);

  const rows = listQ.data ?? [];
  // Only offer tiers that don't already have a price for this item.
  const priced = new Set(rows.map((r) => r.pricing_tier_id));
  const available = (tiersQ.data ?? []).filter((tier) => !priced.has(tier.id));

  // Selecting a tier prefills the price from the item's default (a starting point).
  function selectTier(id: string) {
    setTierId(id);
    if (id && price.trim() === "") {
      setPriceInput(minorToMajorInput(item.default_price?.minor));
    }
  }

  async function add(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    const minor = majorToMinor(price);
    if (!tierId || minor === null) {
      setError(t("inventory.pricing.tier.invalid"));
      return;
    }
    try {
      await setPrice.mutateAsync({ tierId, minor });
      setTierId("");
      setPriceInput("");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("inventory.pricing.tier.errorGeneric"));
    }
  }

  async function remove(id: string, name: string | null) {
    const ok = await confirm({
      title: t("inventory.pricing.tier.removeTitle"),
      description: t("inventory.pricing.tier.removeBody", { tier: name ?? "" }),
      confirmLabel: t("inventory.pricing.remove"),
      tone: "danger",
    });
    if (ok) await removePrice.mutateAsync(id);
  }

  async function create(event: React.FormEvent) {
    event.preventDefault();
    setCreateError(null);
    if (!newName.trim()) {
      setCreateError(t("inventory.pricing.tier.nameRequired"));
      return;
    }
    try {
      const rebate = newRebate.trim() === "" ? undefined : Number(newRebate);
      const tier = await createTier.mutateAsync({ name: newName.trim(), rebate_percent: rebate });
      setTierId(tier.id); // auto-select the new tier so it can be priced
      setNewName("");
      setNewRebate("");
      setCreating(false);
    } catch (err) {
      setCreateError(err instanceof ApiError ? err.message : t("inventory.pricing.tier.errorGeneric"));
    }
  }

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <div>
          <h3 className="text-sm font-semibold">{t("inventory.pricing.tier.title")}</h3>
          <p className="text-xs text-muted-foreground">
            {t("inventory.pricing.tier.intro", {
              price: item.default_price ? moneyObject(item.default_price) : "—",
            })}
          </p>
        </div>

        {listQ.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : rows.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("inventory.pricing.tier.empty")}</p>
        ) : (
          <ul className="divide-y divide-border rounded-md border border-border">
            {rows.map((row) => {
              return (
                <li
                  key={row.pricing_tier_id}
                  className="flex items-center justify-between gap-3 px-3 py-2.5"
                >
                  <p className="min-w-0 truncate text-sm font-medium">
                    {row.tier_name ?? row.pricing_tier_id}
                  </p>
                  <div className="flex items-center gap-3">
                    <span className="text-sm font-medium tabular-nums">{moneyObject(row.price)}</span>
                    <Discount price={row.price} defaultPrice={item.default_price} />
                    {canManage && (
                      <button
                        type="button"
                        onClick={() => remove(row.pricing_tier_id, row.tier_name)}
                        aria-label={t("inventory.pricing.remove")}
                        className="text-muted-foreground transition-colors hover:text-destructive"
                      >
                        <Trash2 className="size-4" />
                      </button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}

        {canManage && (
          <div className="space-y-3 border-t border-border pt-4">
            {available.length > 0 ? (
              <form onSubmit={add}>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                  <div className="space-y-1.5">
                    <Label htmlFor="tp-tier">{t("inventory.pricing.tier.selectTier")}</Label>
                    <Select id="tp-tier" value={tierId} onChange={(e) => selectTier(e.target.value)}>
                      <option value="">{t("inventory.pricing.tier.selectTier")}</option>
                      {available.map((tier) => (
                        <option key={tier.id} value={tier.id}>
                          {tier.name}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="tp-price">{t("inventory.pricing.tier.priceLabel")}</Label>
                    <div className="flex items-center gap-2">
                      <Input
                        id="tp-price"
                        type="number"
                        min={0}
                        step="0.01"
                        value={price}
                        onChange={(e) => setPriceInput(e.target.value)}
                        placeholder="0.00"
                        className="w-32"
                      />
                      <LiveDiscount value={price} defaultPrice={item.default_price} />
                    </div>
                  </div>
                  <Button type="submit" disabled={setPrice.isPending}>
                    {setPrice.isPending && <Spinner />}
                    {t("inventory.pricing.tier.add")}
                  </Button>
                </div>
                {error && <p className="mt-2 text-sm text-destructive">{error}</p>}
              </form>
            ) : (
              (tiersQ.data?.length ?? 0) > 0 && (
                <p className="text-xs text-muted-foreground">
                  {t("inventory.pricing.tier.allAssigned")}
                </p>
              )
            )}

            {/* Create a brand-new tier inline. */}
            {creating ? (
              <form onSubmit={create} className="rounded-md border border-border bg-muted/30 p-3">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end">
                  <div className="space-y-1.5">
                    <Label htmlFor="tp-new-name">{t("inventory.pricing.tier.nameLabel")}</Label>
                    <Input
                      id="tp-new-name"
                      value={newName}
                      onChange={(e) => setNewName(e.target.value)}
                      placeholder={t("inventory.pricing.tier.namePlaceholder")}
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="tp-new-rebate">{t("inventory.pricing.tier.rebateLabel")}</Label>
                    <Input
                      id="tp-new-rebate"
                      type="number"
                      min={0}
                      max={100}
                      step="0.01"
                      value={newRebate}
                      onChange={(e) => setNewRebate(e.target.value)}
                      placeholder="0"
                      className="w-24"
                    />
                  </div>
                  <Button type="submit" disabled={createTier.isPending}>
                    {createTier.isPending && <Spinner />}
                    {t("inventory.pricing.tier.create")}
                  </Button>
                  <Button type="button" variant="outline" onClick={() => setCreating(false)}>
                    {t("inventory.pricing.tier.cancel")}
                  </Button>
                </div>
                {createError && <p className="mt-2 text-sm text-destructive">{createError}</p>}
              </form>
            ) : (
              <button
                type="button"
                onClick={() => setCreating(true)}
                className="text-sm font-medium text-primary transition-colors hover:underline"
              >
                + {t("inventory.pricing.tier.newTier")}
              </button>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function CustomerPricing({ item, canManage }: { item: InventoryItem; canManage: boolean }) {
  const { t } = useTranslation();
  const { moneyObject } = useFormatters();
  const confirm = useConfirm();
  const listQ = useItemCustomerPrices(item.id);
  const setPrice = useSetItemCustomerPrice(item.id);
  const removePrice = useRemoveItemCustomerPrice(item.id);

  const [customer, setCustomer] = React.useState<Customer | null>(null);
  const [price, setPriceInput] = React.useState("");
  const [error, setError] = React.useState<string | null>(null);

  const rows = listQ.data ?? [];

  // Prefill with the existing override for this customer, else the list price.
  function selectCustomer(c: Customer) {
    setCustomer(c);
    const existing = rows.find((r) => r.customer_id === c.id);
    setPriceInput(minorToMajorInput(existing?.price.minor ?? item.default_price?.minor));
  }

  async function add(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    const minor = majorToMinor(price);
    if (!customer || minor === null) {
      setError(t("inventory.pricing.customer.invalid"));
      return;
    }
    try {
      await setPrice.mutateAsync({ customerId: customer.id, minor });
      setCustomer(null);
      setPriceInput("");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("inventory.pricing.customer.errorGeneric"));
    }
  }

  async function remove(id: string, name: string | null) {
    const ok = await confirm({
      title: t("inventory.pricing.customer.removeTitle"),
      description: t("inventory.pricing.customer.removeBody", { name: name ?? "" }),
      confirmLabel: t("inventory.pricing.remove"),
      tone: "danger",
    });
    if (ok) await removePrice.mutateAsync(id);
  }

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <div>
          <h3 className="text-sm font-semibold">{t("inventory.pricing.customer.title")}</h3>
          <p className="text-xs text-muted-foreground">{t("inventory.pricing.customer.intro")}</p>
        </div>

        {listQ.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : rows.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("inventory.pricing.customer.empty")}</p>
        ) : (
          <ul className="divide-y divide-border rounded-md border border-border">
            {rows.map((row) => {
              return (
                <li
                  key={row.customer_id}
                  className="flex items-center justify-between gap-3 px-3 py-2.5"
                >
                  <p className="min-w-0 truncate text-sm font-medium">
                    {row.company_name ?? row.customer_id}
                  </p>
                  <div className="flex items-center gap-3">
                    <span className="text-sm font-medium tabular-nums">{moneyObject(row.price)}</span>
                    <Discount price={row.price} defaultPrice={item.default_price} />
                    {canManage && (
                      <button
                        type="button"
                        onClick={() => remove(row.customer_id, row.company_name)}
                        aria-label={t("inventory.pricing.remove")}
                        className="text-muted-foreground transition-colors hover:text-destructive"
                      >
                        <Trash2 className="size-4" />
                      </button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}

        {canManage && (
          <form onSubmit={add} className="space-y-3 border-t border-border pt-4">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
              <div className="space-y-1.5">
                <Label htmlFor="cp-customer">{t("inventory.pricing.customer.customerLabel")}</Label>
                <CustomerPicker
                  id="cp-customer"
                  valueLabel={customer?.company_name}
                  onChange={selectCustomer}
                  placeholder={t("inventory.pricing.customer.pickCustomer")}
                  searchPlaceholder={t("inventory.pricing.customer.searchCustomer")}
                  emptyLabel={t("inventory.pricing.customer.noCustomers")}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="cp-price">{t("inventory.pricing.customer.priceLabel")}</Label>
                <div className="flex items-center gap-2">
                  <Input
                    id="cp-price"
                    type="number"
                    min={0}
                    step="0.01"
                    value={price}
                    onChange={(e) => setPriceInput(e.target.value)}
                    placeholder="0.00"
                    className="w-32"
                  />
                  <LiveDiscount value={price} defaultPrice={item.default_price} />
                </div>
              </div>
              <Button type="submit" disabled={setPrice.isPending}>
                {setPrice.isPending && <Spinner />}
                {t("inventory.pricing.customer.add")}
              </Button>
            </div>
            {error && <p className="text-sm text-destructive">{error}</p>}
          </form>
        )}
      </CardContent>
    </Card>
  );
}
