import { describe, expect, it } from "vitest";

import { urlBase64ToUint8Array } from "./push";

describe("urlBase64ToUint8Array", () => {
  it("decodes a url-safe base64 VAPID key to bytes", () => {
    // "hello" → base64 "aGVsbG8="; url-safe, unpadded form is "aGVsbG8".
    const bytes = urlBase64ToUint8Array("aGVsbG8");
    expect(Array.from(bytes)).toEqual([104, 101, 108, 108, 111]);
  });

  it("handles url-safe chars (- and _) that replace + and /", () => {
    // 0xFB 0xFF 0xBF encodes to "+/+/" in std base64, "-_-_" url-safe.
    const bytes = urlBase64ToUint8Array("-_-_");
    expect(bytes).toBeInstanceOf(Uint8Array);
    expect(bytes.length).toBe(3);
  });
});
