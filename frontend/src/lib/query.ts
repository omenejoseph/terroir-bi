import { QueryClient } from "@tanstack/react-query";

import { ApiError } from "@/lib/api/client";

/** Shared query client. Don't retry auth/permission failures — they won't recover. */
export function makeQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        // Don't refetch on every window/tab focus — it surprises the server with
        // calls and can clobber in-progress edits. Data refreshes on explicit
        // invalidation (after mutations) and when it goes stale.
        refetchOnWindowFocus: false,
        retry: (failureCount, error) => {
          if (error instanceof ApiError && [401, 403, 404, 422].includes(error.status)) {
            return false;
          }
          return failureCount < 2;
        },
      },
    },
  });
}