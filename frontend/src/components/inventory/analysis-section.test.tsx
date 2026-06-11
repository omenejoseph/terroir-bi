import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import { AnalysisSection } from "./analysis-section";
import { API_URL } from "@/lib/config";
import { makeBottleAnalysis, makeItem } from "@/test/fixtures";
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

describe("AnalysisSection", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("shows the empty state", async () => {
    renderWithProviders(<AnalysisSection item={item} canManage />);
    expect(await screen.findByText("No bottle analyses recorded yet.")).toBeInTheDocument();
  });

  it("lists recorded analyses with their measurements", async () => {
    server.use(
      http.get(`${API_URL}/inventory-items/:id/bottle-analyses`, () =>
        HttpResponse.json({
          data: [makeBottleAnalysis({ ph: 3.45, alcohol: 13.5, note: "Pre-bottling" })],
        }),
      ),
    );

    renderWithProviders(<AnalysisSection item={item} canManage />);

    expect(await screen.findByText("Pre-bottling")).toBeInTheDocument();
    expect(screen.getByText(/pH:/)).toBeInTheDocument();
    expect(screen.getByText("3.45")).toBeInTheDocument();
    expect(screen.getByText("13.5")).toBeInTheDocument();
  });

  it("records a new analysis", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/inventory-items/:id/bottle-analyses`, async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeBottleAnalysis(body) }, { status: 201 });
      }),
    );

    renderWithProviders(<AnalysisSection item={item} canManage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: /Add analysis/ }));
    await user.type(screen.getByLabelText("pH"), "3.45");
    await user.type(screen.getByLabelText("Alcohol (%)"), "13.5");
    await user.type(screen.getByLabelText("Note"), "Pre-bottling");
    await user.click(screen.getByRole("button", { name: "Save Analysis" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ ph: 3.45, alcohol: 13.5, note: "Pre-bottling" });
    expect(body).toHaveProperty("analyzed_on"); // defaulted to today
    // Empty measurements are omitted, not sent as null/0.
    expect(body).not.toHaveProperty("ph_typo");
    expect(body).not.toHaveProperty("density");
  });

  it("deletes an analysis after confirming", async () => {
    let deleted = false;
    server.use(
      http.get(`${API_URL}/inventory-items/:id/bottle-analyses`, () =>
        HttpResponse.json({ data: [makeBottleAnalysis({ id: "ba_9", ph: 3.2 })] }),
      ),
      http.delete(`${API_URL}/inventory-items/:id/bottle-analyses/:analysisId`, ({ params }) => {
        if (params.analysisId === "ba_9") deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<AnalysisSection item={item} canManage />);
    const user = userEvent.setup();

    const row = (await screen.findByText(/pH:/)).closest("li") as HTMLElement;
    await user.click(within(row).getByRole("button", { name: "Remove" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Remove" }));

    await waitFor(() => expect(deleted).toBe(true));
  });
});
