import * as React from "react";

import { cn } from "@/lib/utils";

/*
  Scroll-safe table wrapper. A bare <table> wider than its container pushes the
  whole page sideways on mobile; wrapping it here keeps any overflow contained to
  a horizontally-scrollable box so the viewport never scrolls. Use for dense data
  tables that are occasionally scanned (analytics, imports, modals). For primary,
  frequently-read tables prefer reflowing into stacked cards under `sm:` instead.
*/
const Table = React.forwardRef<
  HTMLTableElement,
  React.TableHTMLAttributes<HTMLTableElement> & { containerClassName?: string }
>(({ className, containerClassName, ...props }, ref) => (
  <div className={cn("w-full overflow-x-auto", containerClassName)}>
    <table ref={ref} className={cn("w-full text-sm", className)} {...props} />
  </div>
));
Table.displayName = "Table";

export { Table };
