import { http, HttpResponse } from "msw";

import { API_URL } from "@/lib/config";
import { makeItem, makeSession, tenantA, tenantB } from "@/test/fixtures";

const url = (path: string) => `${API_URL}${path}`;

/**
 * Default happy-path handlers. Individual tests override these with
 * `server.use(...)` to exercise failure scenarios (401/403/422/500).
 */
export const handlers = [
  // Auth
  http.post(url("/auth/login"), async ({ request }) => {
    const body = (await request.json()) as { email?: string };
    return HttpResponse.json({
      data: makeSession({ token: "tok_new", user: makeSession().user, ...(body.email ? {} : {}) }),
    });
  }),

  http.get(url("/auth/me"), () => HttpResponse.json({ data: makeSession() })),

  http.get(url("/auth/tenants"), () => HttpResponse.json({ data: [tenantA, tenantB] })),

  http.post(url("/auth/switch-tenant"), async ({ request }) => {
    const body = (await request.json()) as { tenant_id: string };
    return HttpResponse.json({
      data: makeSession({ token: "tok_switched", active_tenant_id: body.tenant_id }),
    });
  }),

  http.post(url("/auth/logout"), () => new HttpResponse(null, { status: 204 })),

  // Translations (i18n overrides) — empty by default.
  http.get(url("/translations"), () => HttpResponse.json({ data: {} })),

  // Inventory — supports the `search` filter.
  http.get(url("/inventory-items"), ({ request }) => {
    const search = new URL(request.url).searchParams.get("search")?.toLowerCase();
    const all = [
      makeItem(),
      makeItem({ id: "itm_2", name: "Graševina 2022", sku: "GR-2022", is_active: false }),
    ];
    const items = search
      ? all.filter((i) => i.name.toLowerCase().includes(search) || i.sku.toLowerCase().includes(search))
      : all;

    return HttpResponse.json({
      data: items,
      meta: { current_page: 1, last_page: 1, per_page: 15, total: items.length },
    });
  }),
];