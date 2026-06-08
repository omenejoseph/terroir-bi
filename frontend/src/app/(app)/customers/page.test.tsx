import { beforeEach, describe, expect, it } from "vitest";

import CustomersPage from "./page";
import { renderWithProviders, screen, seedLocale } from "@/test/utils";

describe("CustomersPage", () => {
  beforeEach(() => seedLocale("en"));

  it("renders the customers placeholder", async () => {
    renderWithProviders(<CustomersPage />);

    expect(await screen.findByText("Customers")).toBeInTheDocument();
    expect(screen.getByText("Coming soon")).toBeInTheDocument();
  });
});