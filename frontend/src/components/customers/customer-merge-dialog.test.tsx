import { http, HttpResponse } from "msw";
import { describe, expect, it, beforeEach } from "vitest";

import { CustomerMergeDialog } from "./customer-merge-dialog";
import { API_URL } from "@/lib/config";
import { makeCustomer, makeMergePreview } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

function noop() {}

describe("CustomerMergeDialog", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
    server.use(
      http.get(`${API_URL}/customers`, () =>
        HttpResponse.json({
          data: [
            makeCustomer({ id: "cus_1", company_name: "Acme Corporation", email: "a@acme.com" }),
            makeCustomer({ id: "cus_2", company_name: "Retail Shop LLC", email: "r@shop.com" }),
          ],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 2 },
        }),
      ),
    );
  });

  it("selects duplicates, picks a keeper, previews and merges", async () => {
    let previewBody: { winner_id: string; loser_ids: string[] } | null = null;
    let mergeBody: typeof previewBody = null;
    server.use(
      http.post(`${API_URL}/customers/merge/preview`, async ({ request }) => {
        previewBody = (await request.json()) as typeof previewBody;
        return HttpResponse.json({ data: makeMergePreview("cus_1", ["cus_2"]) });
      }),
      http.post(`${API_URL}/customers/merge`, async ({ request }) => {
        mergeBody = (await request.json()) as typeof mergeBody;
        return HttpResponse.json({ data: makeMergePreview("cus_1", ["cus_2"], true) });
      }),
    );

    let merged: string | null = null;
    renderWithProviders(
      <CustomerMergeDialog open onOpenChange={noop} onMerged={(m) => (merged = m)} />,
    );
    const user = userEvent.setup();

    await screen.findByText("Acme Corporation");
    // Keep Acme, select Retail Shop as a duplicate.
    await user.click(screen.getByLabelText("Keep Acme Corporation"));
    await user.click(screen.getByLabelText("Select Retail Shop LLC"));

    await user.click(screen.getByRole("button", { name: "Preview merge" }));
    await waitFor(() => expect(previewBody).not.toBeNull());
    expect(previewBody).toEqual({ winner_id: "cus_1", loser_ids: ["cus_2"] });

    // Preview view → confirm.
    expect(await screen.findByText(/will be merged/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Merge" }));

    await waitFor(() => expect(mergeBody).not.toBeNull());
    expect(mergeBody).toEqual({ winner_id: "cus_1", loser_ids: ["cus_2"] });
    await waitFor(() => expect(merged).toContain("Merged 1"));
  });

  it("disables preview until a keeper and a duplicate are chosen", async () => {
    renderWithProviders(
      <CustomerMergeDialog open onOpenChange={noop} onMerged={noop} />,
    );
    const user = userEvent.setup();

    await screen.findByText("Acme Corporation");
    expect(screen.getByRole("button", { name: "Preview merge" })).toBeDisabled();

    // Only a keeper, no loser → still disabled.
    await user.click(screen.getByLabelText("Keep Acme Corporation"));
    expect(screen.getByRole("button", { name: "Preview merge" })).toBeDisabled();

    await user.click(screen.getByLabelText("Select Retail Shop LLC"));
    expect(screen.getByRole("button", { name: "Preview merge" })).toBeEnabled();
  });
});
