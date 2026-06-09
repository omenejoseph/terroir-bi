import "@testing-library/jest-dom/vitest";

import { cleanup } from "@testing-library/react";
import { afterAll, afterEach, beforeAll, vi } from "vitest";

import { server } from "@/test/mocks/server";

// Recharts' ResponsiveContainer relies on ResizeObserver, absent in jsdom.
globalThis.ResizeObserver ??= class {
  observe() {}
  unobserve() {}
  disconnect() {}
};

/**
 * Shared Next router mock. Tests import this to assert navigation, e.g.
 * `expect(mockRouter.replace).toHaveBeenCalledWith("/dashboard")`.
 */
export const mockRouter = {
  push: vi.fn(),
  replace: vi.fn(),
  refresh: vi.fn(),
  back: vi.fn(),
  forward: vi.fn(),
  prefetch: vi.fn(),
};

/**
 * Shared, mutable query string. Tests set values (e.g.
 * `mockSearchParams.set("token", "tok_1")`); it is cleared after each test.
 */
export const mockSearchParams = new URLSearchParams();

vi.mock("next/navigation", () => ({
  useRouter: () => mockRouter,
  usePathname: () => "/",
  useSearchParams: () => mockSearchParams,
  // Detail pages read a route param; fixtures use these ids.
  useParams: () => ({ id: "itm_1", userId: "usr_1", invitationId: "inv_1" }),
  redirect: vi.fn(),
}));

// MSW: fail loudly on any request we didn't explicitly mock.
beforeAll(() => server.listen({ onUnhandledRequest: "error" }));

afterEach(() => {
  cleanup();
  server.resetHandlers();
  window.localStorage.clear();
  for (const key of [...mockSearchParams.keys()]) mockSearchParams.delete(key);
  vi.clearAllMocks();
});

afterAll(() => server.close());