"use client";

import * as React from "react";
import { ChevronsUpDown } from "lucide-react";

import { useCustomers } from "@/hooks/use-customers";
import type { Customer } from "@/lib/types";
import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";

/**
 * Searchable picker over customers (server-side search as you type). Mirrors
 * InventoryItemPicker; `valueLabel` keeps an existing selection visible before a
 * search runs.
 */
export function CustomerPicker({
  id,
  valueLabel,
  onChange,
  placeholder,
  searchPlaceholder,
  emptyLabel,
}: {
  id?: string;
  valueLabel?: string;
  onChange: (customer: Customer) => void;
  placeholder: string;
  searchPlaceholder: string;
  emptyLabel: string;
}) {
  const [open, setOpen] = React.useState(false);
  const [query, setQuery] = React.useState("");
  const [debounced, setDebounced] = React.useState("");
  const containerRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    const t = setTimeout(() => setDebounced(query.trim()), 250);
    return () => clearTimeout(t);
  }, [query]);

  React.useEffect(() => {
    if (!open) return;
    const onDocClick = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener("mousedown", onDocClick);
    return () => document.removeEventListener("mousedown", onDocClick);
  }, [open]);

  const listQ = useCustomers(debounced ? { search: debounced } : {});
  const customers = listQ.data?.data ?? [];

  function commit(customer: Customer) {
    onChange(customer);
    setQuery("");
    setDebounced("");
    setOpen(false);
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        id={id}
        onClick={() => setOpen((o) => !o)}
        className="flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <span className={cn("truncate", !valueLabel && "text-muted-foreground")}>
          {valueLabel || placeholder}
        </span>
        <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
      </button>

      {open && (
        <div className="absolute z-30 mt-1 w-full overflow-hidden rounded-md border border-border bg-popover shadow-md">
          <input
            autoFocus
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={searchPlaceholder}
            className="w-full border-b border-border bg-transparent px-3 py-2 text-sm focus-visible:outline-none"
          />
          <ul className="max-h-56 overflow-auto p-1">
            {listQ.isLoading ? (
              <li className="flex justify-center py-3">
                <Spinner className="size-4 text-muted-foreground" />
              </li>
            ) : customers.length === 0 ? (
              <li className="px-2 py-1.5 text-sm text-muted-foreground">{emptyLabel}</li>
            ) : (
              customers.map((customer) => (
                <li key={customer.id}>
                  <button
                    type="button"
                    onClick={() => commit(customer)}
                    className="flex w-full items-center rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
                  >
                    <span className="truncate">
                      {customer.company_name}
                      {customer.contact_name && (
                        <span className="text-muted-foreground"> · {customer.contact_name}</span>
                      )}
                    </span>
                  </button>
                </li>
              ))
            )}
          </ul>
        </div>
      )}
    </div>
  );
}
