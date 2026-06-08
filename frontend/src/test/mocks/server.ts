import { setupServer } from "msw/node";

import { handlers } from "@/test/mocks/handlers";

/** Node MSW server used across all tests. Tests add overrides via server.use(). */
export const server = setupServer(...handlers);