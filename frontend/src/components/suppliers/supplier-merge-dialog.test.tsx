import { http, HttpResponse } from "msw";
import { describe, expect, it, beforeEach } from "vitest";

import { SupplierMergeDialog } from "./supplier-merge-dialog";
import { API_URL } from "@/lib/config";
import { makeSupplier, makeSupplierMergePreview } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

function noop() {}

describe("SupplierMergeDialog", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
    server.use(
      http.get(`${API_URL}/suppliers`, () =>
        HttpResponse.json({
          data: [
            makeSupplier({ id: "sup_1", company_name: "Vinogradar d.o.o.", email: "a@vin.hr" }),
            makeSupplier({ id: "sup_2", company_name: "Glass Co.", email: "b@glass.hr" }),
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
      http.post(`${API_URL}/suppliers/merge/preview`, async ({ request }) => {
        previewBody = (await request.json()) as typeof previewBody;
        return HttpResponse.json({ data: makeSupplierMergePreview("sup_1", ["sup_2"]) });
      }),
      http.post(`${API_URL}/suppliers/merge`, async ({ request }) => {
        mergeBody = (await request.json()) as typeof mergeBody;
        return HttpResponse.json({ data: makeSupplierMergePreview("sup_1", ["sup_2"], true) });
      }),
    );

    let merged: string | null = null;
    renderWithProviders(
      <SupplierMergeDialog open onOpenChange={noop} onMerged={(m) => (merged = m)} />,
    );
    const user = userEvent.setup();

    await screen.findByText("Vinogradar d.o.o.");
    await user.click(screen.getByLabelText("Keep Vinogradar d.o.o."));
    await user.click(screen.getByLabelText("Select Glass Co."));

    await user.click(screen.getByRole("button", { name: "Preview merge" }));
    await waitFor(() => expect(previewBody).not.toBeNull());
    expect(previewBody).toEqual({ winner_id: "sup_1", loser_ids: ["sup_2"] });

    expect(await screen.findByText(/will be merged/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Merge" }));

    await waitFor(() => expect(mergeBody).not.toBeNull());
    expect(mergeBody).toEqual({ winner_id: "sup_1", loser_ids: ["sup_2"] });
    await waitFor(() => expect(merged).toContain("Merged 1"));
  });

  it("disables preview until a keeper and a duplicate are chosen", async () => {
    renderWithProviders(<SupplierMergeDialog open onOpenChange={noop} onMerged={noop} />);
    const user = userEvent.setup();

    await screen.findByText("Vinogradar d.o.o.");
    expect(screen.getByRole("button", { name: "Preview merge" })).toBeDisabled();

    await user.click(screen.getByLabelText("Keep Vinogradar d.o.o."));
    expect(screen.getByRole("button", { name: "Preview merge" })).toBeDisabled();

    await user.click(screen.getByLabelText("Select Glass Co."));
    expect(screen.getByRole("button", { name: "Preview merge" })).toBeEnabled();
  });
});
