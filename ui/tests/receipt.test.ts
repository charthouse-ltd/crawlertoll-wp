// Run: npm test  (Node >= 22.6, native TypeScript stripping; no extra deps)
import { test } from "node:test";
import assert from "node:assert/strict";
import { receiptText, type Purchase } from "../src/unlock/receipt.ts";

const base: Purchase = {
  siteName: "The Ledger",
  siteHost: "ledger.example",
  title: "The agentic web",
  url: "https://ledger.example/the-agentic-web/",
  item: "30-day access · pay by card",
  price: "$4.00",
  rail: "stripe",
  paidAt: 1_790_000_100,
  waiverAt: 1_790_000_000,
  waiverText: "I want access right away. I agree that the content is unlocked immediately and understand that I therefore lose my 14-day right of withdrawal.",
  passId: "a1".repeat(16),
  termsUrl: "https://ledger.example/terms",
};

test("receipt names seller, item, price, method, date and pass", () => {
  const t = receiptText(base);
  for (const s of ["The Ledger (ledger.example)", "The agentic web", "30-day access", "$4.00", "card (processed by Stripe on the seller's account)", "2026-09-21", "a1a1"]) {
    assert.ok(t.includes(s), `missing: ${s}`);
  }
});

test("receipt states the withdrawal waiver verbatim with its time", () => {
  const t = receiptText(base);
  assert.ok(t.includes("Immediate access and right of withdrawal"));
  assert.ok(t.includes(`"${base.waiverText}"`));
  assert.ok(t.includes("Seller's terms: https://ledger.example/terms"));
});

test("no waiver section when the reader was not asked", () => {
  const t = receiptText({ ...base, waiverAt: null, termsUrl: undefined });
  assert.ok(!t.includes("right of withdrawal"));
  assert.ok(!t.includes("Seller's terms"));
});

test("USDC receipts say where the money went", () => {
  const t = receiptText({ ...base, rail: "x402" });
  assert.ok(t.includes("USDC (x402), paid directly to the seller's wallet"));
});
