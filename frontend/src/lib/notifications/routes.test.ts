import { describe, expect, it } from "vitest";

import { resolveNotificationRoute } from "./routes";

describe("resolveNotificationRoute", () => {
  it("routes order notifications to the order page using data.order_id", () => {
    for (const type of ["NEW_ORDER", "ORDER_STATUS", "MENTION", "REPLY"] as const) {
      expect(resolveNotificationRoute(type, { order_id: "ord_9" })).toBe("/orders/ord_9");
    }
  });

  it("returns null for order types missing the id", () => {
    expect(resolveNotificationRoute("NEW_ORDER", {})).toBeNull();
    expect(resolveNotificationRoute("NEW_ORDER", null)).toBeNull();
  });

  it("returns null for announcements (display-only)", () => {
    expect(resolveNotificationRoute("ANNOUNCEMENT", {})).toBeNull();
  });

  it("routes AI import notifications to the review page using data.ai_import_id", () => {
    for (const type of ["AI_IMPORT_READY", "AI_IMPORT_FAILED"] as const) {
      expect(resolveNotificationRoute(type, { ai_import_id: "imp_3" })).toBe("/ai-imports/imp_3");
    }
    expect(resolveNotificationRoute("AI_IMPORT_READY", {})).toBeNull();
  });
});
