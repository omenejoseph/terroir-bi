"use client";

import { cn } from "@/lib/utils";

/**
 * A compact segmented Yes/No control for a boolean field — slicker than a
 * checkbox and shows both states explicitly. Exposed as a radiogroup for a11y.
 */
export function YesNoToggle({
  id,
  value,
  onChange,
  yesLabel,
  noLabel,
  disabled,
}: {
  id?: string;
  value: boolean;
  onChange: (value: boolean) => void;
  yesLabel: string;
  noLabel: string;
  disabled?: boolean;
}) {
  const options = [
    { v: true, label: yesLabel },
    { v: false, label: noLabel },
  ];
  return (
    <div
      id={id}
      role="radiogroup"
      className="inline-flex rounded-md border border-input p-0.5 text-sm"
    >
      {options.map(({ v, label }) => {
        const active = value === v;
        return (
          <button
            key={label}
            type="button"
            role="radio"
            aria-checked={active}
            disabled={disabled}
            onClick={() => onChange(v)}
            className={cn(
              "rounded px-3 py-1 font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50",
              active
                ? "bg-primary text-primary-foreground shadow-sm"
                : "text-muted-foreground hover:text-foreground",
            )}
          >
            {label}
          </button>
        );
      })}
    </div>
  );
}
