"use client";

import * as React from "react";
import { Check, ChevronsUpDown, Plus } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * Searchable, creatable text field. The value is free text (what you type IS the
 * value), and existing `options` surface as suggestions you can click. If the
 * typed text matches no option, a "Create …" row lets you commit it explicitly.
 *
 * Decoupled + token-styled — no external dependency. Used for inventory group /
 * subcategory, which are free-text on the backend.
 */
export function Combobox({
  id,
  value,
  onChange,
  options,
  placeholder,
  createLabel,
  emptyLabel,
}: {
  id?: string;
  value: string;
  onChange: (value: string) => void;
  options: string[];
  placeholder?: string;
  /** Renders the "create" row label, e.g. (q) => `Create "${q}"`. */
  createLabel: (query: string) => string;
  emptyLabel: string;
}) {
  const [open, setOpen] = React.useState(false);
  const [query, setQuery] = React.useState("");
  const containerRef = React.useRef<HTMLDivElement>(null);

  // Close on outside click.
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

  const q = query.trim();
  const filtered = options.filter((o) => o.toLowerCase().includes(q.toLowerCase()));
  const exactExists = options.some((o) => o.toLowerCase() === q.toLowerCase());
  const showCreate = q.length > 0 && !exactExists;

  function commit(next: string) {
    onChange(next);
    setQuery("");
    setOpen(false);
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        id={id}
        onClick={() => setOpen((o) => !o)}
        className="flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:text-sm"
      >
        <span className={cn("truncate", !value && "text-muted-foreground")}>
          {value || placeholder}
        </span>
        <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
      </button>

      {open && (
        <div className="absolute z-30 mt-1 w-full overflow-hidden rounded-md border border-border bg-popover shadow-md">
          <input
            autoFocus
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={placeholder}
            className="w-full border-b border-border bg-transparent px-3 py-2 text-base focus-visible:outline-none md:text-sm"
          />
          <ul className="max-h-56 overflow-auto p-1">
            {filtered.map((option) => (
              <li key={option}>
                <button
                  type="button"
                  onClick={() => commit(option)}
                  className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
                >
                  <Check
                    className={cn("size-4", option === value ? "opacity-100" : "opacity-0")}
                  />
                  <span className="truncate">{option}</span>
                </button>
              </li>
            ))}

            {showCreate && (
              <li>
                <button
                  type="button"
                  onClick={() => commit(q)}
                  className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm text-primary hover:bg-accent"
                >
                  <Plus className="size-4" />
                  <span className="truncate">{createLabel(q)}</span>
                </button>
              </li>
            )}

            {filtered.length === 0 && !showCreate && (
              <li className="px-2 py-1.5 text-sm text-muted-foreground">{emptyLabel}</li>
            )}
          </ul>

          {value && (
            <button
              type="button"
              onClick={() => commit("")}
              className="w-full border-t border-border px-3 py-1.5 text-left text-xs text-muted-foreground hover:bg-accent"
            >
              ✕ {value}
            </button>
          )}
        </div>
      )}
    </div>
  );
}