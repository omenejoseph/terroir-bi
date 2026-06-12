import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it, vi } from "vitest";

import AiImportsPage from "./page";
import { API_URL } from "@/lib/config";
import { mockRouter } from "@/test/setup";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedAuth,
  seedLocale,
  userEvent,
  waitFor,
} from "@/test/utils";

const makeImport = (over: Record<string, unknown> = {}) => ({
  id: "imp_1",
  type: "bank_statement",
  type_label: "Bank statement",
  status: "ready",
  status_label: "Ready for review",
  source_filename: "statement.pdf",
  source_mime: "application/pdf",
  provider: null,
  model: null,
  prompt_tokens: 0,
  completion_tokens: 0,
  error: null,
  created_at: "2026-05-01T00:00:00Z",
  lines_total: 2,
  lines_pending: 2,
  lines_committed: 0,
  ...over,
});

describe("AiImportsPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
    vi.clearAllMocks();
  });

  it("lists recent imports", async () => {
    server.use(http.get(`${API_URL}/ai-imports`, () => HttpResponse.json({ data: [makeImport()] })));

    renderWithProviders(<AiImportsPage />);

    expect(await screen.findByText("statement.pdf")).toBeInTheDocument();
    expect(screen.getByText("Ready for review")).toBeInTheDocument();
  });

  it("uploads a document (presign → PUT → create) and opens the review page", async () => {
    let created = false;
    server.use(
      http.get(`${API_URL}/ai-imports`, () => HttpResponse.json({ data: [] })),
      http.post(`${API_URL}/uploads/presign`, () =>
        HttpResponse.json({
          data: {
            key: "k1",
            url: "https://bucket.example/k1",
            method: "PUT",
            headers: {},
            content_type: "application/pdf",
            max_bytes: 26214400,
            expires_in: 300,
          },
        }),
      ),
      http.put("https://bucket.example/k1", () => new HttpResponse(null, { status: 200 })),
      http.post(`${API_URL}/ai-imports`, () => {
        created = true;
        return HttpResponse.json({ data: makeImport({ id: "imp_new", status: "uploaded" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<AiImportsPage />);
    const user = userEvent.setup();

    const file = new File(["pdf-bytes"], "statement.pdf", { type: "application/pdf" });
    await user.upload(await screen.findByLabelText("Upload & extract"), file);

    await waitFor(() => expect(created).toBe(true));
    await waitFor(() => expect(mockRouter.push).toHaveBeenCalledWith("/ai-imports/imp_new"));
  });
});
