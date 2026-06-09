import { http, HttpResponse } from "msw";

import { API_URL } from "@/lib/config";
import {
  makeAnalytics,
  makeConsignmentSummary,
  makeCustomer,
  makeDashboard,
  makeImage,
  makeInvitation,
  makeItem,
  makeMember,
  makeMovement,
  makeNotification,
  makeOrder,
  makeOrderComment,
  makePricingTier,
  makeSession,
  makeSettings,
  tenantA,
  tenantB,
} from "@/test/fixtures";

const url = (path: string) => `${API_URL}${path}`;

const pageMeta = (total: number) => ({ current_page: 1, last_page: 1, per_page: 25, total });

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

  // Uploads — presign + background-removal proxy.
  http.post(url("/uploads/presign"), async ({ request }) => {
    const body = (await request.json()) as { content_type?: string };
    return HttpResponse.json({
      data: {
        key: "tenants/ten_a/inventory/images/obj.webp",
        url: "https://bucket.test/put/obj.webp",
        method: "PUT",
        headers: { "Content-Type": body.content_type ?? "image/webp" },
        content_type: body.content_type ?? "image/webp",
        max_bytes: 5 * 1024 * 1024,
        expires_in: 300,
      },
    });
  }),
  http.put("https://bucket.test/*", () => new HttpResponse(null, { status: 200 })),
  http.post(url("/uploads/remove-background"), () =>
    HttpResponse.arrayBuffer(new ArrayBuffer(8), { headers: { "Content-Type": "image/png" } }),
  ),

  // Inventory images.
  http.get(url("/inventory-items/:id/images"), () => HttpResponse.json({ data: [] })),
  http.post(url("/inventory-items/:id/images"), async ({ request }) => {
    const body = (await request.json()) as { content_type?: string; alt?: string | null };
    return HttpResponse.json(
      { data: makeImage({ id: "img_new", content_type: body.content_type, alt: body.alt ?? null }) },
      { status: 201 },
    );
  }),
  http.delete(url("/inventory-items/:id/images/:imageId"), () => new HttpResponse(null, { status: 204 })),

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

  // Inventory — produce (production run).
  http.post(url("/inventory-items/:id/produce"), ({ params }) =>
    HttpResponse.json({ data: makeItem({ id: String(params.id) }) }),
  ),

  // Orders — sub-resources first (static before :id).
  http.get(url("/orders/analytics"), () =>
    HttpResponse.json({
      data: {
        period: { from: "2026-05-01", to: "2026-06-01" },
        revenue: { minor: 900000, currency: "EUR", formatted: "€9,000.00" },
        cogs: { minor: 400000, currency: "EUR", formatted: "€4,000.00" },
        gross_profit: { minor: 500000, currency: "EUR", formatted: "€5,000.00" },
        margin_percent: "55.56",
        order_count: 12,
        avg_order_value: { minor: 75000, currency: "EUR", formatted: "€750.00" },
        items_with_unknown_cost: 0,
        consignment_revenue: { minor: 0, currency: "EUR", formatted: "€0.00" },
        top_customers: [
          { customer_id: "cus_1", company_name: "Acme Corporation", revenue: { minor: 600000, currency: "EUR", formatted: "€6,000.00" } },
        ],
        top_products: [
          { inventory_item_id: "itm_1", name: "Plavac Mali 2021", quantity: 40, revenue: { minor: 600000, currency: "EUR", formatted: "€6,000.00" } },
        ],
        low_margin_orders: [],
      },
    }),
  ),
  http.get(url("/orders/:id/consignment"), () => HttpResponse.json({ data: makeConsignmentSummary() })),
  http.post(url("/orders/:id/consignment/sale"), () => HttpResponse.json({ data: makeConsignmentSummary() })),
  http.post(url("/orders/:id/consignment/return"), () => HttpResponse.json({ data: makeConsignmentSummary() })),
  http.post(url("/orders/:id/consignment/close"), () =>
    HttpResponse.json({ data: makeConsignmentSummary({ closed_at: "2026-06-03T00:00:00+00:00" }) }),
  ),
  http.post(url("/orders/:id/comments"), () => HttpResponse.json({ data: makeOrderComment() }, { status: 201 })),
  http.post(url("/orders/:id/items"), ({ params }) =>
    HttpResponse.json({ data: makeOrder({ id: String(params.id) }) }),
  ),
  http.patch(url("/orders/:id/status"), ({ params }) =>
    HttpResponse.json({ data: makeOrder({ id: String(params.id) }) }),
  ),
  http.patch(url("/orders/:id/shipping"), ({ params }) =>
    HttpResponse.json({ data: makeOrder({ id: String(params.id) }) }),
  ),
  http.patch(url("/orders/:id/notes"), ({ params }) =>
    HttpResponse.json({ data: makeOrder({ id: String(params.id) }) }),
  ),
  http.patch(url("/orders/:id/backorder"), ({ params }) =>
    HttpResponse.json({ data: makeOrder({ id: String(params.id) }) }),
  ),
  http.get(url("/orders/:id"), ({ params }) =>
    HttpResponse.json({ data: makeOrder({ id: String(params.id) }) }),
  ),
  http.get(url("/orders"), ({ request }) => {
    const params = new URL(request.url).searchParams;
    const status = params.get("status");
    const search = params.get("search")?.toLowerCase();
    let all = [makeOrder(), makeOrder({ id: "ord_2", order_number: "ORD-1002", status: "SHIPPED" })];
    if (status) all = all.filter((o) => o.status === status);
    if (params.get("hide_shipped") === "true") all = all.filter((o) => o.status !== "SHIPPED");
    if (search) all = all.filter((o) => o.order_number.toLowerCase().includes(search));
    return HttpResponse.json({ data: all, meta: pageMeta(all.length) });
  }),
  http.post(url("/orders"), () => HttpResponse.json({ data: makeOrder({ id: "ord_new" }) }, { status: 201 })),

  // Order items + comments.
  http.patch(url("/order-items/:id/cost"), () => HttpResponse.json({ data: makeOrder() })),
  http.patch(url("/order-items/:id"), () => HttpResponse.json({ data: makeOrder() })),
  http.delete(url("/order-items/:id"), () => HttpResponse.json({ data: makeOrder({ items: [] }) })),
  http.patch(url("/order-comments/:id"), () => HttpResponse.json({ data: makeOrderComment() })),
  http.delete(url("/order-comments/:id"), () => new HttpResponse(null, { status: 204 })),

  // Customer-level consignment.
  http.get(url("/customers/:id/consignment"), () =>
    HttpResponse.json({ data: { products: [], placements: [] } }),
  ),
  http.post(url("/customers/:id/consignment/place"), () =>
    HttpResponse.json({ data: { order_number: "ORD-1003" } }, { status: 201 }),
  ),
  http.post(url("/customers/:id/consignment/sale"), () => new HttpResponse(null, { status: 204 })),
  http.post(url("/customers/:id/consignment/return"), () => new HttpResponse(null, { status: 204 })),

  // Notifications.
  http.get(url("/notifications"), () => HttpResponse.json({ data: [makeNotification()] })),
  http.post(url("/notifications/read"), () => new HttpResponse(null, { status: 204 })),

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