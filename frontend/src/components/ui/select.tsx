import * as React from "react";

import { cn } from "@/lib/utils";

/** Styled native <select>. Token-driven; pass <option> children. */
const Select = React.forwardRef<HTMLSelectElement, React.SelectHTMLAttributes<HTMLSelectElement>>(
  ({ className, ...props }, ref) => (
    <select
      ref={ref}
      className={cn(
        // text-base (16px) on mobile avoids iOS Safari's focus auto-zoom; text-sm from md up.
        "flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm",
        className,
      )}
      {...props}
    />
  ),
);
Select.displayName = "Select";

export { Select };