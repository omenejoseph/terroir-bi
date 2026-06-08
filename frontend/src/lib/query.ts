import { QueryClient } from "@tanstack/react-query";

import { ApiError } from "@/lib/api/client";

/** Shared query client. Don't retry auth/permission failures — they won't recover. */
export function makeQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
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