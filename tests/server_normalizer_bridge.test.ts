import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { test } from "node:test";
import { visibleEmailText } from "../../../artifacts/api-server/src/lib/classifier-features";

test("actual visibleEmailText cannot decode the adapter's long base64-looking payload", () => {
  const fixture = JSON.parse(execFileSync(
    "php",
    ["tests/normalizer_payload_fixture.php"],
    { encoding: "utf8" },
  )) as {
    encodedLiteral: string;
    safePayload: string;
    cssRaw: string;
    cssRejected: boolean;
  };

  assert.ok(fixture.encodedLiteral.length > 300);
  const normalized = visibleEmailText(fixture.safePayload);
  assert.ok(normalized.includes(fixture.encodedLiteral));
  assert.ok(!normalized.includes("dangerous-visible-phrase"));

  // This proves the backend's real brace removal would hide displayed text;
  // the adapter must therefore reject it before any paid call.
  assert.ok(!visibleEmailText(fixture.cssRaw).includes("dangerous-css-marker"));
  assert.equal(fixture.cssRejected, true);
});