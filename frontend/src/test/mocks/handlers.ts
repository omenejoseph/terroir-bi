import { http, HttpResponse } from "msw";

import { API_URL } from "@/lib/config";
import {
  makeAnalytics,
  makeCustomer,
  makeDashboard,
  makeInvitation,
  makeItem,
  makeMember,
  makeMovement,
  makePricingTier,
  makeSession,
  makeSettings,
  tenantA,
  tenantB,
} from "@/test/fixtures";

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

  http.post(url("/auth/invitations/accept"), () =>
    HttpResponse.json({ data: makeSession({ token: "tok_accept" }) }),
  ),

  http.get(url("/auth/tenants"), () => HttpResponse.json({ data: [tenantA, tenantB] })),

  http.post(url("/auth/switch-tenant"), async ({ request }) => {
    const body = (await request.json()) as { tenant_id: string };
    return HttpResponse.json({
      data: makeSession({ token: "tok_switched", active_tenant_id: body.tenant_id }),
    });
  }),

  http.post(url("/auth/logout"), () => new HttpResponse(null, { status: 204 })),

  // Organisation settings.
  http.get(url("/settings"), () => HttpResponse.json({ data: makeSettings() })),
  http.patch(url("/settings"), async ({ request }) => {
    const body = (await request.json()) as Partial<ReturnType<typeof makeSettings>>;
    return HttpResponse.json({ data: makeSettings(body) });
  }),

  // Translations (i18n overrides) — empty by default.
  http.get(url("/translations"), () => HttpResponse.json({ data: {} })),
  http.put(url("/translations"), async ({ request }) => {
    const body = (await request.json()) as { locale: string; key: string; value: string };
    return HttpResponse.json({ data: { id: "ovr_1", ...body } });
  }),
  http.delete(url("/translations"), () => new HttpResponse(null, { status: 204 })),

  // Inventory — create (echoes a new item; tests override to assert the payload).
  http.post(url("/inventory-items"), () =>
    HttpResponse.json({ data: makeItem({ id: "itm_new" }) }, { status: 201 }),
  ),

  // Inventory — update.
  http.patch(url("/inventory-items/:id"), ({ params }) =>
    HttpResponse.json({ data: makeItem({ id: String(params.id) }) }),
  ),

  // Inventory — stock adjustment.
  http.post(url("/inventory-items/:id/stock"), ({ params }) =>
    HttpResponse.json({ data: makeItem({ id: String(params.id) }) }),
  ),

  // Inventory — stock movements ledger.
  http.get(url("/inventory-items/:id/movements"), () =>
    HttpResponse.json({ data: [makeMovement()] }),
  ),

  // Inventory — recipe (read + replace).
  http.get(url("/inventory-items/:id/recipe"), () => HttpResponse.json({ data: [] })),
  http.put(url("/inventory-items/:id/recipe"), () => HttpResponse.json({ data: [] })),

  // Customers + pricing tiers.
  http.get(url("/pricing-tiers"), () => HttpResponse.json({ data: [makePricingTier()] })),
  http.get(url("/customers"), ({ request }) => {
    const params = new URL(request.url).searchParams;
    const search = params.get("search")?.toLowerCase();
    const isActive = params.has("is_active") ? params.get("is_active") === "true" : null;
    let all = [
      makeCustomer(),
      makeCustomer({ id: "cus_2", company_name: "Vinoteka Zagreb", email: "info@vinoteka.hr", is_active: false }),
    ];
    if (search) {
      all = all.filter(
        (c) => c.company_name.toLowerCase().includes(search) || c.email.toLowerCase().includes(search),
      );
    }
    if (isActive !== null) all = all.filter((c) => c.is_active === isActive);
    return HttpResponse.json({
      data: all,
      meta: { current_page: 1, last_page: 1, per_page: 25, total: all.length },
    });
  }),
  http.post(url("/pricing-tiers"), () =>
    HttpResponse.json({ data: makePricingTier({ id: "tier_new", name: "New Tier" }) }, { status: 201 }),
  ),
  http.post(url("/customers"), () => HttpResponse.json({ data: makeCustomer({ id: "cus_new" }) }, { status: 201 })),
  http.patch(url("/customers/:id"), ({ params }) =>
    HttpResponse.json({ data: makeCustomer({ id: String(params.id) }) }),
  ),
  http.delete(url("/customers/:id"), () => new HttpResponse(null, { status: 204 })),
  // Single customer (detail page) — after the more specific routes above.
  http.get(url("/customers/:id"), ({ params }) =>
    HttpResponse.json({ data: makeCustomer({ id: String(params.id) }) }),
  ),

  // Team — members & invitations.
  http.get(url("/members"), () => HttpResponse.json({ data: [makeMember()] })),
  http.patch(url("/members/:userId"), ({ params }) =>
    HttpResponse.json({ data: makeMember({ user_id: String(params.userId) }) }),
  ),
  http.delete(url("/members/:userId"), () => new HttpResponse(null, { status: 204 })),
  http.get(url("/invitations"), () => HttpResponse.json({ data: [makeInvitation()] })),
  http.post(url("/invitations"), () =>
    HttpResponse.json({ data: makeInvitation({ id: "inv_new", accept_token: "tok_abc123" }) }, { status: 201 }),
  ),
  http.delete(url("/invitations/:id"), () => new HttpResponse(null, { status: 204 })),

  // Dashboard summary.
  http.get(url("/dashboard"), ({ request }) => {
    const range = new URL(request.url).searchParams.get("range") ?? "30D";
    return HttpResponse.json({ data: makeDashboard({ range }) });
  }),

  // Inventory — taxonomy + analytics. Static segments must precede the /:id matcher.
  http.get(url("/inventory-items/taxonomy"), () => HttpResponse.json({ data: [] })),
  http.get(url("/inventory-items/analytics"), () => HttpResponse.json({ data: makeAnalytics() })),

  // Inventory — single item (detail page). Must come after the more specific
  // /movements, /recipe and /taxonomy routes above so it doesn't shadow them.
  http.get(url("/inventory-items/:id"), ({ params }) =>
    HttpResponse.json({ data: makeItem({ id: String(params.id) }) }),
  ),

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