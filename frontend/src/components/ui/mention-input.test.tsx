import * as React from "react";
import { describe, expect, it, vi } from "vitest";

import { MentionInput, type MentionMember } from "./mention-input";
import { renderWithProviders, screen, userEvent, waitFor } from "@/test/utils";

const MEMBERS: MentionMember[] = [
  { user_id: "usr_1", name: "Ada Lovelace", email: "ada@example.com" },
  { user_id: "usr_2", name: "Bob Wilson", email: "bob@example.com" },
];

function Harness({ onMentions }: { onMentions: (ids: string[]) => void }) {
  const [value, setValue] = React.useState("");
  return (
    <MentionInput
      value={value}
      onChange={setValue}
      onMentionsChange={onMentions}
      members={MEMBERS}
      placeholder="Write a comment…"
    />
  );
}

describe("MentionInput", () => {
  it("opens on @, lists members, and inserts the chosen one by click", async () => {
    const onMentions = vi.fn();
    renderWithProviders(<Harness onMentions={onMentions} />);
    const user = userEvent.setup();

    const box = screen.getByPlaceholderText("Write a comment…");
    await user.type(box, "Hi @ada");
    expect(await screen.findByText("Ada Lovelace")).toBeInTheDocument();

    await user.click(screen.getByText("Ada Lovelace"));
    expect(box).toHaveValue("Hi @Ada Lovelace ");
    await waitFor(() => expect(onMentions).toHaveBeenLastCalledWith(["usr_1"]));
  });

  it("supports arrow-key navigation + Enter to select", async () => {
    const onMentions = vi.fn();
    renderWithProviders(<Harness onMentions={onMentions} />);
    const user = userEvent.setup();

    const box = screen.getByPlaceholderText("Write a comment…");
    await user.type(box, "@");
    expect(await screen.findByText("Bob Wilson")).toBeInTheDocument();

    // First option (Ada) is highlighted; ArrowDown → Bob, Enter selects.
    await user.keyboard("{ArrowDown}{Enter}");
    expect(box).toHaveValue("@Bob Wilson ");
    await waitFor(() => expect(onMentions).toHaveBeenLastCalledWith(["usr_2"]));
  });

  it("drops a mention when its token is deleted", async () => {
    const onMentions = vi.fn();
    renderWithProviders(<Harness onMentions={onMentions} />);
    const user = userEvent.setup();

    const box = screen.getByPlaceholderText("Write a comment…");
    await user.type(box, "@ada");
    await user.click(await screen.findByText("Ada Lovelace"));
    await waitFor(() => expect(onMentions).toHaveBeenLastCalledWith(["usr_1"]));

    await user.clear(box);
    await waitFor(() => expect(onMentions).toHaveBeenLastCalledWith([]));
  });
});
