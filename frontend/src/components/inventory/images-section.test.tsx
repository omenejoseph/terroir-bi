import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it, vi } from "vitest";

// Canvas/Image aren't available in jsdom — stub the processing module.
vi.mock("@/lib/image", () => ({
  processImage: vi.fn(async () => new Blob(["x"], { type: "image/webp" })),
  extensionForType: () => "webp",
}));

import { ImagesSection } from "./images-section";
import { API_URL } from "@/lib/config";
import { makeImage, makeItem } from "@/test/fixtures";
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

describe("ImagesSection", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("lists existing images", async () => {
    server.use(
      http.get(`${API_URL}/inventory-items/:id/images`, () =>
        HttpResponse.json({ data: [makeImage({ alt: "Front label" })] }),
      ),
    );

    renderWithProviders(<ImagesSection item={item} canManage />);
    expect(await screen.findByAltText("Front label")).toBeInTheDocument();
  });

  it("uploads a picked image (presign → bucket → attach)", async () => {
    let attached: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/inventory-items/:id/images`, async ({ request }) => {
        attached = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeImage({ id: "img_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<ImagesSection item={item} canManage />);
    const user = userEvent.setup();

    const file = new File(["bytes"], "bottle.png", { type: "image/png" });
    await user.upload(await screen.findByLabelText("Choose image file"), file);

    // Editor appears; upload it.
    await user.click(await screen.findByRole("button", { name: "Upload" }));

    await waitFor(() => expect(attached).not.toBeNull());
    expect(attached).toMatchObject({ key: expect.stringContaining("inventory/images") });
  });

  it("calls the background-removal proxy", async () => {
    let calledBg = false;
    server.use(
      http.post(`${API_URL}/uploads/remove-background`, () => {
        calledBg = true;
        return HttpResponse.arrayBuffer(new ArrayBuffer(8), {
          headers: { "Content-Type": "image/png" },
        });
      }),
    );

    renderWithProviders(<ImagesSection item={item} canManage />);
    const user = userEvent.setup();

    const file = new File(["bytes"], "bottle.jpg", { type: "image/jpeg" });
    await user.upload(await screen.findByLabelText("Choose image file"), file);
    await user.click(await screen.findByRole("button", { name: "Remove background" }));

    await waitFor(() => expect(calledBg).toBe(true));
  });

  it("deletes an image after confirming", async () => {
    let deleted = false;
    server.use(
      http.get(`${API_URL}/inventory-items/:id/images`, () =>
        HttpResponse.json({ data: [makeImage()] }),
      ),
      http.delete(`${API_URL}/inventory-items/:id/images/:imageId`, () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<ImagesSection item={item} canManage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: "Delete image" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Delete image" }));

    await waitFor(() => expect(deleted).toBe(true));
  });

  it("hides the uploader for non-managers", async () => {
    renderWithProviders(<ImagesSection item={item} canManage={false} />);
    await screen.findByText("No images yet.");
    expect(screen.queryByRole("button", { name: "Add image" })).not.toBeInTheDocument();
  });
});
