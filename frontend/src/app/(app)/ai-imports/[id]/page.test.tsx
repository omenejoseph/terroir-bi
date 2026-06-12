import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import AiImportReviewPage from "./page";
import { API_URL } from "@/lib/config";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedAuth,
  seedLocale,
  userEvent,
  waitFor,
} from "@/test/utils";

// useParams() is mocked in src/test/setup.ts to return { id: "itm_1", ... }.
const IMPORT_ID = "itm_1";

const makeLine = (over: Record<string, unknown> = {}) => ({
  id: "ln_1",
  index: 0,
  target_type: "cost",
  target_label: "Cost",
  category: "Packaging",
  confidence: 0.9,
  status: "pending",
  payload: { date: "2026-05-01", total_amount: 12345, status: "PENDING", payment_method: "bank_transfer", category: "Packaging" },
  edited_payload: null,
  effective_payload: { date: "2026-05-01", total_amount: 12345, status: "PENDING", payment_method: "bank_transfer", category: "Packaging" },
  committed_id: null,
  ...over,
});

const makeImport = (over: Record<string, unknown> = {}) => ({
  id: IMPORT_ID,
  type: "bank_statement",
  type_label: "Bank statement",
  status: "ready",
  status_label: "Ready for review",
  source_filename: "s.pdf",
  source_mime: null,
  provider: null,
  model: null,
  prompt_tokens: 0,
  completion_tokens: 0,
  error: null,
  created_at: null,
  lines: [makeLine()],
  lines_total: 1,
  lines_pending: 1,
  lines_committed: 0,
  ...over,
});

describe("AiImportReviewPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders proposed lines and approves one", async () => {
    let patched: { status?: string } | null = null;
    server.use(
      http.get(`${API_URL}/ai-imports/${IMPORT_ID}`, () => HttpResponse.json({ data: makeImport() })),
      http.patch(`${API_URL}/ai-imports/${IMPORT_ID}/lines/ln_1`, async ({ request }) => {
        patched = (await request.json()) as { status?: string };
        return HttpResponse.json({ data: makeLine({ status: "approved" }) });
      }),
    );

    renderWithProviders(<AiImportReviewPage />);
    const user = userEvent.setup();

    expect(await screen.findByText("Cost")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Approve" }));

    await waitFor(() => expect(patched?.status).toBe("approved"));
  });

  it("edits a line with proper dropdowns and saves money in major units", async () => {
    let patched: { status?: string; edited_payload?: { status?: string; total_amount?: number } } | null = null;
    server.use(
      http.get(`${API_URL}/ai-imports/${IMPORT_ID}`, () => HttpResponse.json({ data: makeImport() })),
      http.patch(`${API_URL}/ai-imports/${IMPORT_ID}/lines/ln_1`, async ({ request }) => {
        patched = (await request.json()) as { status?: string; edited_payload?: { status?: string; total_amount?: number } };
        return HttpResponse.json({ data: makeLine({ status: "edited" }) });
      }),
    );

    renderWithProviders(<AiImportReviewPage />);
    const user = userEvent.setup();

    await screen.findByText("Cost");
    await user.click(screen.getByRole("button", { name: "Edit" }));

    // status renders as a dropdown (a real <select>), not a free-text box.
    const status = await screen.findByLabelText("status");
    expect(status.tagName).toBe("SELECT");
    // money is shown/edited in major units (12345 minor → "123.45").
    expect((screen.getByLabelText("total_amount") as HTMLInputElement).value).toBe("123.45");

    await user.selectOptions(status, "PAID");
    await user.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() => {
      expect(patched?.status).toBe("edited");
      expect(patched?.edited_payload?.status).toBe("PAID");
      // major units converted back to minor on save.
      expect(patched?.edited_payload?.total_amount).toBe(12345);
    });
  });

  it("hides the Approve button once a line is approved", async () => {
    server.use(
      http.get(`${API_URL}/ai-imports/${IMPORT_ID}`, () =>
        HttpResponse.json({ data: makeImport({ lines: [makeLine({ status: "approved" })] }) }),
      ),
    );

    renderWithProviders(<AiImportReviewPage />);
    await screen.findByText("Cost");

    expect(screen.queryByRole("button", { name: "Approve" })).toBeNull();
    expect(screen.getByRole("button", { name: "Reject" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Edit" })).toBeInTheDocument();
  });

  it("commits approved lines and shows a confirmation", async () => {
    let committed = false;
    server.use(
      http.get(`${API_URL}/ai-imports/${IMPORT_ID}`, () => HttpResponse.json({ data: makeImport() })),
      http.post(`${API_URL}/ai-imports/${IMPORT_ID}/commit`, () => {
        committed = true;
        return HttpResponse.json({
          data: makeImport({
            status: "committed",
            status_label: "Committed",
            lines_committed: 1,
            lines: [makeLine({ status: "committed", committed_id: "cost_1" })],
          }),
          meta: { committed: 1, failed: 0, errors: {} },
        });
      }),
    );

    renderWithProviders(<AiImportReviewPage />);
    const user = userEvent.setup();

    await screen.findByText("Cost");
    await user.click(screen.getByRole("button", { name: "Commit approved" }));

    await waitFor(() => expect(committed).toBe(true));
    expect(await screen.findByText(/Committed 1 record/)).toBeInTheDocument();
  });

  it("renders order line items as a table, not raw JSON", async () => {
    const items = [
      { description: "Brochure Design", quantity: 1, unit_price: 90000 },
      { description: "Web Design package", quantity: 2, unit_price: 1000000 },
    ];
    const orderLine = makeLine({
      target_type: "order",
      target_label: "Order",
      payload: { customer_name: "Acme", status: "RECEIVED", items },
      effective_payload: { customer_name: "Acme", status: "RECEIVED", items },
    });
    server.use(
      http.get(`${API_URL}/ai-imports/${IMPORT_ID}`, () =>
        HttpResponse.json({ data: makeImport({ type: "invoice", type_label: "Invoice / order", lines: [orderLine] }) }),
      ),
    );

    renderWithProviders(<AiImportReviewPage />);

    // Item descriptions appear as their own cells…
    expect(await screen.findByText("Brochure Design")).toBeInTheDocument();
    expect(screen.getByText("Web Design package")).toBeInTheDocument();
    // …and the raw JSON blob is not shown.
    expect(screen.queryByText(/"unit_price"/)).toBeNull();
  });

  it("links an existing supplier to a cost line on save", async () => {
    let patched: { edited_payload?: { supplier_id?: string } } | null = null;
    server.use(
      http.get(`${API_URL}/suppliers`, () =>
        HttpResponse.json({ data: [{ id: "sup_9", company_name: "Acme Supplies" }] }),
      ),
      http.get(`${API_URL}/ai-imports/${IMPORT_ID}`, () => HttpResponse.json({ data: makeImport() })),
      http.patch(`${API_URL}/ai-imports/${IMPORT_ID}/lines/ln_1`, async ({ request }) => {
        patched = (await request.json()) as { edited_payload?: { supplier_id?: string } };
        return HttpResponse.json({ data: makeLine({ status: "edited" }) });
      }),
    );

    renderWithProviders(<AiImportReviewPage />);
    const user = userEvent.setup();
    await screen.findByText("Cost");
    await user.click(screen.getByRole("button", { name: "Edit" }));

    await user.selectOptions(await screen.findByLabelText("Supplier"), "sup_9");
    await user.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() => expect(patched?.edited_payload?.supplier_id).toBe("sup_9"));
  });

  it("auto-matches the AI customer name to an existing customer and links it", async () => {
    let patched: { edited_payload?: { customer_id?: string } } | null = null;
    const orderLine = makeLine({
      target_type: "order",
      target_label: "Order",
      payload: { customer_name: "Acme Co", status: "RECEIVED", items: [] },
      effective_payload: { customer_name: "Acme Co", status: "RECEIVED", items: [] },
    });
    server.use(
      http.get(`${API_URL}/customers`, () =>
        HttpResponse.json({ data: [{ id: "cus_5", company_name: "Acme Co" }] }),
      ),
      http.get(`${API_URL}/ai-imports/${IMPORT_ID}`, () =>
        HttpResponse.json({ data: makeImport({ type: "invoice", type_label: "Invoice / order", lines: [orderLine] }) }),
      ),
      http.patch(`${API_URL}/ai-imports/${IMPORT_ID}/lines/ln_1`, async ({ request }) => {
        patched = (await request.json()) as { edited_payload?: { customer_id?: string } };
        return HttpResponse.json({ data: makeLine({ status: "edited" }) });
      }),
    );

    renderWithProviders(<AiImportReviewPage />);
    const user = userEvent.setup();
    await screen.findByText("Order");
    await user.click(screen.getByRole("button", { name: "Edit" }));

    // The picker is preselected to the matched customer.
    const select = (await screen.findByLabelText("Customer")) as HTMLSelectElement;
    expect(select.value).toBe("cus_5");

    await user.click(screen.getByRole("button", { name: "Save" }));
    await waitFor(() => expect(patched?.edited_payload?.customer_id).toBe("cus_5"));
  });

  it("shows the processing state while extraction runs", async () => {
    server.use(
      http.get(`${API_URL}/ai-imports/${IMPORT_ID}`, () =>
        HttpResponse.json({ data: makeImport({ status: "processing", status_label: "Processing", lines: undefined }) }),
      ),
    );

    renderWithProviders(<AiImportReviewPage />);

    expect(await screen.findByText("Extracting data from your document…")).toBeInTheDocument();
  });
});
