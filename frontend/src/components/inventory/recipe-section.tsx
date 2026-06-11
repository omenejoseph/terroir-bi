"use client";

import * as React from "react";
import { Plus, X } from "lucide-react";

import { useRecipe, useSetRecipe } from "@/hooks/use-inventory";
import { useTranslation } from "@/i18n/context";
import type { InventoryItem } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { InventoryItemPicker } from "@/components/inventory/inventory-item-picker";

interface DraftLine {
  input_id: string;
  input_name: string;
  input_unit: string;
  quantity: string;
}

const blankLine = (): DraftLine => ({ input_id: "", input_name: "", input_unit: "", quantity: "" });

export function RecipeSection({ item, canManage }: { item: InventoryItem; canManage: boolean }) {
  const { t } = useTranslation();
  const recipeQ = useRecipe(item.id);
  const setRecipe = useSetRecipe();

  const [lines, setLines] = React.useState<DraftLine[]>([]);
  const [error, setError] = React.useState<string | null>(null);

  // Sync local draft from the server recipe. For managers with no recipe yet,
  // start with one empty row so the search-and-add picker is immediately usable.
  React.useEffect(() => {
    if (!recipeQ.data) return;
    const mapped = recipeQ.data.map((l) => ({
      input_id: l.input_id ?? "",
      input_name: l.input_name,
      input_unit: l.input_unit,
      quantity: String(l.quantity),
    }));
    setLines(canManage && mapped.length === 0 ? [blankLine()] : mapped);
  }, [recipeQ.data, canManage]);

  function addLine() {
    setLines((ls) => [...ls, blankLine()]);
  }
  function removeLine(index: number) {
    setLines((ls) => ls.filter((_, i) => i !== index));
  }
  function updateLine(index: number, patch: Partial<DraftLine>) {
    setLines((ls) => ls.map((l, i) => (i === index ? { ...l, ...patch } : l)));
  }
  function selectInput(index: number, picked: InventoryItem) {
    updateLine(index, {
      input_id: picked.id,
      input_name: picked.name,
      input_unit: picked.unit,
    });
  }

  async function save() {
    setError(null);
    const items = lines
      .filter((l) => l.input_id && Number(l.quantity) > 0)
      .map((l) => ({ input_id: l.input_id, quantity: Number(l.quantity) }));
    try {
      await setRecipe.mutateAsync({ id: item.id, items });
    } catch {
      setError(t("inventory.recipe.errorGeneric"));
    }
  }

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <p className="text-sm text-muted-foreground">{t("inventory.recipe.subtitle")}</p>

        {recipeQ.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : !canManage ? (
          // Read-only view for non-managers.
          recipeQ.data && recipeQ.data.length > 0 ? (
            <ul className="space-y-2 text-sm">
              {recipeQ.data.map((l, i) => (
                <li key={l.input_id ?? `custom-${i}`} className="flex justify-between border-b border-border pb-2">
                  <span>
                    {l.input_name} <span className="text-muted-foreground">({l.input_sku})</span>
                  </span>
                  <span className="tabular-nums">
                    {l.quantity} {l.input_unit}
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="py-4 text-center text-sm text-muted-foreground">
              {t("inventory.recipe.empty")}
            </p>
          )
        ) : (
          // Editable for managers.
          <>
            <div className="space-y-2">
              {lines.map((line, index) => (
                <div key={index} className="flex items-end gap-2">
                  <div className="flex-1 space-y-1">
                    <label className="text-xs text-muted-foreground" htmlFor={`recipe-input-${index}`}>
                      {t("inventory.recipe.inputLabel")}
                    </label>
                    <InventoryItemPicker
                      id={`recipe-input-${index}`}
                      valueLabel={line.input_name}
                      excludeId={item.id}
                      onChange={(picked) => selectInput(index, picked)}
                      placeholder={t("inventory.recipe.selectPlaceholder")}
                      searchPlaceholder={t("inventory.recipe.searchPlaceholder")}
                      emptyLabel={t("inventory.recipe.empty")}
                    />
                  </div>
                  <div className="w-32 space-y-1">
                    <label className="text-xs text-muted-foreground" htmlFor={`recipe-qty-${index}`}>
                      {t("inventory.recipe.quantityLabel")}
                    </label>
                    <div className="flex items-center gap-1.5">
                      <Input
                        id={`recipe-qty-${index}`}
                        type="number"
                        min={0}
                        step="any"
                        value={line.quantity}
                        onChange={(e) => updateLine(index, { quantity: e.target.value })}
                      />
                      {line.input_unit && (
                        <span className="shrink-0 text-xs text-muted-foreground">
                          {line.input_unit}
                        </span>
                      )}
                    </div>
                  </div>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={t("inventory.recipe.remove")}
                    onClick={() => removeLine(index)}
                  >
                    <X className="size-4" />
                  </Button>
                </div>
              ))}
            </div>

            {error && <p className="text-sm text-destructive">{error}</p>}

            <div className="flex items-center justify-between">
              <Button type="button" variant="outline" size="sm" onClick={addLine}>
                <Plus className="size-4" />
                {t("inventory.recipe.add")}
              </Button>
              <Button type="button" size="sm" onClick={save} disabled={setRecipe.isPending}>
                {setRecipe.isPending && <Spinner />}
                {t("inventory.recipe.save")}
              </Button>
            </div>
          </>
        )}

      </CardContent>
    </Card>
  );
}
