import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import { DocumentsSection } from "./documents-section";
import { API_URL } from "@/lib/config";
import { makeInventoryDocument, makeItem } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedAuth,
  seedLocale,
  userEvent,
  waitFor,
  within,
} from "@/test/utils";

const item = makeItem();

describe("DocumentsSection", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("shows the empty state", async () => {
    renderWithProviders(<DocumentsSection item={item} canManage />);
    expect(await screen.findByText("No documents uploaded yet.")).toBeInTheDocument();
  });

  it("lists documents with a download link and size", async () => {
    server.use(
      http.get(`${API_URL}/inventory-items/:id/documents`, () =>
        HttpResponse.json({
          data: [makeInventoryDocument({ name: "Certificate.pdf", size_bytes: 204800 })],
        }),
      ),
    );

    renderWithProviders(<DocumentsSection item={item} canManage />);

    const link = await screen.findByRole("link", { name: /Certificate\.pdf/ });
    expect(link).toHaveAttribute("href", "https://bucket.test/read/doc_1.pdf");
    expect(screen.getByText("200 KB")).toBeInTheDocument();
  });

  it("uploads a document (presign → PUT → attach)", async () => {
    let presignPurpose: string | null = null;
    let attachBody: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/uploads/presign`, async ({ request }) => {
        const body = (await request.json()) as { purpose?: string; content_type?: string };
        presignPurpose = body.purpose ?? null;
        return HttpResponse.json({
          data: {
            key: "tenants/ten_a/inventory/docs/obj.pdf",
            url: "https://bucket.test/put/obj.pdf",
            method: "PUT",
            headers: { "Content-Type": body.content_type ?? "application/pdf" },
            content_type: body.content_type ?? "application/pdf",
            max_bytes: 20 * 1024 * 1024,
            expires_in: 300,
          },
        });
      }),
      http.post(`${API_URL}/inventory-items/:id/documents`, async ({ request }) => {
        attachBody = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeInventoryDocument(attachBody) }, { status: 201 });
      }),
    );

    renderWithProviders(<DocumentsSection item={item} canManage />);
    const user = userEvent.setup();

    const file = new File(["%PDF-1.4"], "lab-report.pdf", { type: "application/pdf" });
    await user.upload(screen.getByLabelText("Choose a file"), file);

    await waitFor(() => expect(attachBody).not.toBeNull());
    expect(presignPurpose).toBe("inventory_document");
    expect(attachBody).toMatchObject({
      key: "tenants/ten_a/inventory/docs/obj.pdf",
      name: "lab-report.pdf",
      content_type: "application/pdf",
    });
  });

  it("deletes a document after confirming", async () => {
    let deleted = false;
    server.use(
      http.get(`${API_URL}/inventory-items/:id/documents`, () =>
        HttpResponse.json({ data: [makeInventoryDocument({ id: "doc_9", name: "old.pdf" })] }),
      ),
      http.delete(`${API_URL}/inventory-items/:id/documents/:documentId`, ({ params }) => {
        if (params.documentId === "doc_9") deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<DocumentsSection item={item} canManage />);
    const user = userEvent.setup();

    const row = (await screen.findByText("old.pdf")).closest("li") as HTMLElement;
    await user.click(within(row).getByRole("button", { name: "Remove" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Remove" }));

    await waitFor(() => expect(deleted).toBe(true));
  });
});
