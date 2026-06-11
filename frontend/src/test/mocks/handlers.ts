import { http, HttpResponse } from "msw";

import { API_URL } from "@/lib/config";
import type { BottleAnalysis } from "@/lib/types";
import {
  makeAnalytics,
  makeInventorySpend,
  makeInventoryCheck,
  makeInventoryCheckDetail,
  makeArAging,
  makeCashFlow,
  makeConsignmentSummary,
  makeCost,
  makeCostAnalytics,
  makeCustomer,
  makeCustomerAnalytics,
  makeMergePreview,
  makeCustomerOrderAnalytics,
  makePublicCatalog,
  makeDashboard,
  makeImage,
  makeInventoryDocument,
  makeInflow,
  makeInflowAnalytics,
  makeInflowChange,
  makeInvitation,
  makeItem,
  makeMember,
  makeBottleAnalysis,
  makeMovement,
  makeStockAnalytics,
  makeNotification,
  makeOrder,
  makeOrderComment,
  makeOrderPayments,
  makePriceItem,
  makePricingTier,
  makeSession,
  makeSettings,
  makeSupplier,
  makeSupplierMergePreview,
  makeSupplierOrder,
  makeSupplierPortal,
  makeWorkOrder,
  makeWorkOrderStats,
  tenantA,
  tenantB,
} from "@/test/fixtures";

const url = (path: string) => `${API_URL}${path}`;

const pageMeta = (total: number) => ({ current_page: 1, last_page: 1, per_page: 25, total });

const money = (minor: number) => ({ minor, currency: "EUR", formatted: `€${(minor / 100).toFixed(2)}` });

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

  // Inventory documents.
  http.get(url("/inventory-items/:id/documents"), () => HttpResponse.json({ data: [] })),
  http.post(url("/inventory-items/:id/documents"), async ({ request }) => {
    const body = (await request.json()) as { name?: string; content_type?: string };
    return HttpResponse.json(
      {
        data: makeInventoryDocument({
          id: "doc_new",
          name: body.name ?? "file.pdf",
          content_type: body.content_type ?? "application/pdf",
        }),
      },
      { status: 201 },
    );
  }),
  http.delete(url("/inventory-items/:id/documents/:documentId"), () =>
    new HttpResponse(null, { status: 204 }),
  ),

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

  // Inventory — per-item stock analytics.
  http.get(url("/inventory-items/:id/stock-analytics"), ({ request }) => {
    const period = new URL(request.url).searchParams.get("period") ?? "30d";
    return HttpResponse.json({ data: makeStockAnalytics({ period }) });
  }),

  // Inventory — bottle analyses.
  http.get(url("/inventory-items/:id/bottle-analyses"), () => HttpResponse.json({ data: [] })),
  http.post(url("/inventory-items/:id/bottle-analyses"), async ({ request }) => {
    const body = (await request.json()) as Partial<BottleAnalysis>;
    return HttpResponse.json({ data: makeBottleAnalysis(body) }, { status: 201 });
  }),
  http.delete(url("/inventory-items/:id/bottle-analyses/:analysisId"), () =>
    new HttpResponse(null, { status: 204 }),
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
  // Order payments (finance) — static sub-route before /orders/:id.
  http.get(url("/orders/:id/payments"), () => HttpResponse.json({ data: makeOrderPayments() })),
  http.post(url("/orders/:id/payments"), async ({ request }) => {
    const body = (await request.json()) as { amount: number };
    return HttpResponse.json(
      {
        data: makeOrderPayments({
          summary: { amount_paid: money(90000), balance_due: money(0), status: "PAID" },
          payments: [makeInflow(), makeInflow({ id: "inf_2", amount: money(body.amount) })],
        }),
      },
      { status: 201 },
    );
  }),
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

  // Customer order analytics + custom pricing + order token.
  http.get(url("/customers/analytics"), () =>
    HttpResponse.json({ data: makeCustomerAnalytics() }),
  ),
  http.get(url("/customers/:id/resolved-prices"), ({ request }) => {
    const ids = (new URL(request.url).searchParams.get("item_ids") ?? "").split(",").filter(Boolean);
    const data: Record<string, { minor: number; currency: string }> = {};
    for (const id of ids) data[id] = { minor: 1500, currency: "EUR" };
    return HttpResponse.json({ data });
  }),
  http.post(url("/customers/merge/preview"), async ({ request }) => {
    const body = (await request.json()) as { winner_id: string; loser_ids: string[] };
    return HttpResponse.json({ data: makeMergePreview(body.winner_id, body.loser_ids) });
  }),
  http.post(url("/customers/merge"), async ({ request }) => {
    const body = (await request.json()) as { winner_id: string; loser_ids: string[] };
    return HttpResponse.json({ data: makeMergePreview(body.winner_id, body.loser_ids, true) });
  }),
  http.get(url("/customers/:id/order-analytics"), () =>
    HttpResponse.json({ data: makeCustomerOrderAnalytics() }),
  ),
  http.get(url("/customers/:id/custom-prices"), () => HttpResponse.json({ data: [] })),
  http.get(url("/customers/:id/order-token"), () =>
    HttpResponse.json({ data: { order_token: "tok_demo" } }),
  ),
  http.post(url("/customers/:id/order-token"), ({ params }) =>
    HttpResponse.json({ data: { ...makeCustomer({ id: String(params.id), has_order_token: true }), order_token: "tok_demo" } }),
  ),
  http.delete(url("/customers/:id/order-token"), ({ params }) =>
    HttpResponse.json({ data: makeCustomer({ id: String(params.id), has_order_token: false }) }),
  ),
  http.put(url("/inventory-items/:item/customer-price/:customer"), () =>
    HttpResponse.json({ data: { minor: 1200, currency: "EUR", formatted: "€12.00" } }),
  ),
  http.delete(url("/inventory-items/:item/customer-price/:customer"), () =>
    new HttpResponse(null, { status: 204 }),
  ),

  // Inventory — per-item price books (tier + customer).
  http.get(url("/inventory-items/:id/tier-prices"), () => HttpResponse.json({ data: [] })),
  http.put(url("/inventory-items/:item/tier-price/:tier"), () =>
    HttpResponse.json({ data: { minor: 1999, currency: "EUR", formatted: "€19.99" } }),
  ),
  http.delete(url("/inventory-items/:item/tier-price/:tier"), () =>
    new HttpResponse(null, { status: 204 }),
  ),
  http.get(url("/inventory-items/:id/customer-prices"), () => HttpResponse.json({ data: [] })),

  // Public self-service order flow (token in URL, no auth).
  http.get(url("/public/:token/catalog"), () => HttpResponse.json({ data: makePublicCatalog() })),
  http.post(url("/public/:token/orders"), () =>
    HttpResponse.json({ data: { order_number: "ORD-2001" } }, { status: 201 }),
  ),

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
  http.get(url("/inventory-items/spend"), () => HttpResponse.json({ data: makeInventorySpend() })),

  // Inventory check (stocktake) + audit history.
  http.post(url("/inventory-items/check"), async ({ request }) => {
    const body = (await request.json()) as { items: { item_id: string; physical_count: number }[] };
    return HttpResponse.json({
      data: body.items.map((i) => ({ item_id: i.item_id, difference: "-10.000" })),
    });
  }),
  http.get(url("/inventory-checks"), () =>
    HttpResponse.json({
      data: [makeInventoryCheck()],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
    }),
  ),
  http.get(url("/inventory-checks/:id"), ({ params }) =>
    HttpResponse.json({ data: makeInventoryCheckDetail({ id: String(params.id) }) }),
  ),

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

  // ── Finance: A/R aging + cash flow ──────────────────────────────────────────
  http.get(url("/inflows/aging"), () => HttpResponse.json({ data: makeArAging() })),
  http.get(url("/inflows/analytics"), () => HttpResponse.json({ data: makeInflowAnalytics() })),
  http.get(url("/cash-flow"), () => HttpResponse.json({ data: makeCashFlow() })),

  // ── Inflows (money in) — static /aging is registered above. ─────────────────
  http.post(url("/inflows"), () => HttpResponse.json({ data: makeInflow({ id: "inf_new" }) }, { status: 201 })),
  http.patch(url("/inflows/:id/status"), async ({ params, request }) => {
    const body = (await request.json()) as { status: string };
    return HttpResponse.json({ data: makeInflow({ id: String(params.id), status: body.status as never }) });
  }),
  http.patch(url("/inflows/:id"), ({ params }) =>
    HttpResponse.json({ data: makeInflow({ id: String(params.id) }) }),
  ),
  http.delete(url("/inflows/:id"), () => new HttpResponse(null, { status: 204 })),
  http.get(url("/inflows/:id/changes"), () => HttpResponse.json({ data: [makeInflowChange()] })),
  http.get(url("/inflows/:id"), ({ params }) =>
    HttpResponse.json({ data: makeInflow({ id: String(params.id) }) }),
  ),
  http.get(url("/inflows"), ({ request }) => {
    const params = new URL(request.url).searchParams;
    const status = params.get("status");
    const customerId = params.get("customer_id");
    const search = params.get("search")?.toLowerCase();
    let all = [
      makeInflow(),
      makeInflow({ id: "inf_2", status: "PENDING", category: "Grant", reference: "G-2026", customer_id: null }),
    ];
    if (status) all = all.filter((i) => i.status === status);
    if (customerId) all = all.filter((i) => i.customer_id === customerId);
    if (search) {
      all = all.filter(
        (i) => (i.reference ?? "").toLowerCase().includes(search) || (i.notes ?? "").toLowerCase().includes(search),
      );
    }
    return HttpResponse.json({ data: all, meta: pageMeta(all.length) });
  }),

  // ── Suppliers ───────────────────────────────────────────────────────────────
  http.post(url("/suppliers/:id/price-items"), async ({ request }) => {
    const body = (await request.json()) as { description: string; unit_price: number };
    return HttpResponse.json(
      { data: makePriceItem({ id: "pli_new", description: body.description, unit_price: money(body.unit_price) }) },
      { status: 201 },
    );
  }),
  http.patch(url("/suppliers/:id/price-items/:priceItem"), async ({ request, params }) => {
    const body = (await request.json()) as { description: string; unit_price: number };
    return HttpResponse.json({
      data: makePriceItem({
        id: String(params.priceItem),
        description: body.description,
        unit_price: money(body.unit_price),
      }),
    });
  }),
  http.delete(url("/suppliers/:id/price-items/:priceItem"), () => new HttpResponse(null, { status: 204 })),
  http.post(url("/suppliers/merge/preview"), async ({ request }) => {
    const body = (await request.json()) as { winner_id: string; loser_ids: string[] };
    return HttpResponse.json({ data: makeSupplierMergePreview(body.winner_id, body.loser_ids) });
  }),
  http.post(url("/suppliers/merge"), async ({ request }) => {
    const body = (await request.json()) as { winner_id: string; loser_ids: string[] };
    return HttpResponse.json({ data: makeSupplierMergePreview(body.winner_id, body.loser_ids, true) });
  }),
  http.get(url("/suppliers/:id/stats"), () =>
    HttpResponse.json({ data: { price_items: 2, cost_entries: 3, total_costs: money(45000) } }),
  ),
  http.get(url("/suppliers/:id/price-changes"), () =>
    HttpResponse.json({
      data: [
        {
          id: "spc_1",
          description: "Natural cork 44mm",
          unit: "units",
          old_price: money(2000),
          new_price: money(2500),
          created_at: "2026-06-10T10:00:00+00:00",
        },
      ],
    }),
  ),

  // Supplier portal token (admin)
  http.get(url("/suppliers/:id/portal-token"), () => HttpResponse.json({ data: { portal_token: null } })),
  http.post(url("/suppliers/:id/portal-token"), ({ params }) =>
    HttpResponse.json({
      data: { ...makeSupplier({ id: String(params.id), has_portal_token: true }), portal_token: "tok_abc123" },
    }),
  ),
  http.delete(url("/suppliers/:id/portal-token"), ({ params }) =>
    HttpResponse.json({ data: makeSupplier({ id: String(params.id), has_portal_token: false }) }),
  ),

  // Public supplier portal
  http.get(url("/public/supplier/:token"), () => HttpResponse.json({ data: makeSupplierPortal() })),
  http.post(url("/public/supplier/:token/price-items/import"), async ({ request }) => {
    const body = (await request.json()) as { items: unknown[] };
    return HttpResponse.json({
      data: { added: body.items.length, updated: 0, total: body.items.length },
    });
  }),
  http.patch(url("/public/supplier/:token/orders/:order/confirm"), ({ params }) =>
    HttpResponse.json({
      data: makeSupplierOrder({ id: String(params.order), status: "CONFIRMED" }),
    }),
  ),
  http.post(url("/suppliers"), () =>
    HttpResponse.json({ data: makeSupplier({ id: "sup_new" }) }, { status: 201 }),
  ),
  http.patch(url("/suppliers/:id"), ({ params }) =>
    HttpResponse.json({ data: makeSupplier({ id: String(params.id) }) }),
  ),
  http.delete(url("/suppliers/:id"), () => new HttpResponse(null, { status: 204 })),
  http.get(url("/suppliers/:id"), ({ params }) =>
    HttpResponse.json({
      data: makeSupplier({ id: String(params.id), price_items: [makePriceItem()] }),
    }),
  ),
  http.get(url("/suppliers"), ({ request }) => {
    const params = new URL(request.url).searchParams;
    const search = params.get("search")?.toLowerCase();
    const isActive = params.has("is_active") ? params.get("is_active") === "true" : null;
    let all = [
      makeSupplier(),
      makeSupplier({ id: "sup_2", company_name: "Staklo Split", is_active: false }),
    ];
    if (search) all = all.filter((s) => s.company_name.toLowerCase().includes(search));
    if (isActive !== null) all = all.filter((s) => s.is_active === isActive);
    return HttpResponse.json({ data: all, meta: pageMeta(all.length) });
  }),

  // ── Purchase orders (supplier orders) ───────────────────────────────────────
  http.post(url("/supplier-orders"), () =>
    HttpResponse.json({ data: makeSupplierOrder({ id: "po_new", order_number: "PO-00002" }) }, { status: 201 }),
  ),
  http.patch(url("/supplier-orders/:id/status"), async ({ params, request }) => {
    const body = (await request.json()) as { status: string };
    return HttpResponse.json({
      data: makeSupplierOrder({ id: String(params.id), status: body.status as never }),
    });
  }),
  http.delete(url("/supplier-orders/:id"), () => new HttpResponse(null, { status: 204 })),
  http.get(url("/supplier-orders/:id"), ({ params }) =>
    HttpResponse.json({ data: makeSupplierOrder({ id: String(params.id) }) }),
  ),
  http.get(url("/supplier-orders"), ({ request }) => {
    const status = new URL(request.url).searchParams.get("status");
    let all = [
      makeSupplierOrder(),
      makeSupplierOrder({ id: "po_2", order_number: "PO-00002", status: "SENT", sent_at: "2026-06-02T00:00:00+00:00" }),
    ];
    if (status) all = all.filter((o) => o.status === status);
    return HttpResponse.json({ data: all, meta: pageMeta(all.length) });
  }),

  // ── Costs ───────────────────────────────────────────────────────────────────
  http.get(url("/costs/categories"), () =>
    HttpResponse.json({ data: ["Invoice", "Payment", "Utilities", "Glass", "Corks", "Labour"] }),
  ),
  http.get(url("/costs/group-counts"), () =>
    HttpResponse.json({ data: { all: 12, invoices: 5, payments: 4, others: 3 } }),
  ),
  http.get(url("/costs/analytics"), () => HttpResponse.json({ data: makeCostAnalytics() })),
  http.post(url("/costs"), () => HttpResponse.json({ data: makeCost({ id: "cost_new" }) }, { status: 201 })),
  http.patch(url("/costs/:id/status"), async ({ params, request }) => {
    const body = (await request.json()) as { status: string };
    return HttpResponse.json({ data: makeCost({ id: String(params.id), status: body.status as never }) });
  }),
  http.patch(url("/costs/:id"), ({ params }) =>
    HttpResponse.json({ data: makeCost({ id: String(params.id) }) }),
  ),
  http.delete(url("/costs/:id"), () => new HttpResponse(null, { status: 204 })),
  http.get(url("/costs/:id"), ({ params }) =>
    HttpResponse.json({ data: makeCost({ id: String(params.id) }) }),
  ),
  http.get(url("/costs"), ({ request }) => {
    const params = new URL(request.url).searchParams;
    const search = params.get("search")?.toLowerCase();
    const status = params.get("status");
    const category = params.get("category");
    let all = [
      makeCost(),
      makeCost({ id: "cost_2", category: "Glass", description: "Bottles", status: "PAID" }),
    ];
    if (search) all = all.filter((c) => (c.description ?? "").toLowerCase().includes(search));
    if (status) all = all.filter((c) => c.status === status);
    if (category) all = all.filter((c) => c.category === category);
    return HttpResponse.json({ data: all, meta: pageMeta(all.length) });
  }),

  // ── Tasks / work orders ─────────────────────────────────────────────────────
  http.get(url("/work-orders/stats"), () => HttpResponse.json({ data: makeWorkOrderStats() })),
  http.post(url("/work-orders/reorder"), () => new HttpResponse(null, { status: 204 })),
  http.post(url("/work-orders"), async ({ request }) => {
    const body = (await request.json()) as { title: string };
    return HttpResponse.json({ data: makeWorkOrder({ id: "task_new", title: body.title }) }, { status: 201 });
  }),
  http.patch(url("/work-orders/:id/status"), async ({ params, request }) => {
    const body = (await request.json()) as { status: string };
    return HttpResponse.json({ data: makeWorkOrder({ id: String(params.id), status: body.status as never }) });
  }),
  http.patch(url("/work-orders/:id"), ({ params }) =>
    HttpResponse.json({ data: makeWorkOrder({ id: String(params.id) }) }),
  ),
  http.delete(url("/work-orders/:id"), () => new HttpResponse(null, { status: 204 })),
  http.get(url("/work-orders/:id"), ({ params }) =>
    HttpResponse.json({ data: makeWorkOrder({ id: String(params.id) }) }),
  ),
  http.get(url("/work-orders"), ({ request }) => {
    const status = new URL(request.url).searchParams.get("status");
    let all = [
      makeWorkOrder(),
      makeWorkOrder({ id: "task_2", title: "Label batch", status: "IN_PROGRESS", sort_order: 2 }),
      makeWorkOrder({ id: "task_3", title: "Ship order", status: "DONE", sort_order: 3 }),
    ];
    if (status) all = all.filter((t) => t.status === status);
    return HttpResponse.json({ data: all });
  }),
];