// Reader-side receipt (2026-09-25). EU/UK law expects the trader to confirm a
// digital-content purchase — including the reader's consent to immediate
// access and acknowledgement of losing the withdrawal right — on a durable
// medium. A plain-text file the reader saves is exactly that; nothing leaves
// the page and nothing is stored beyond what the reader downloads.

export interface Purchase {
  siteName: string;
  siteHost: string;
  title: string;
  url: string;
  item: string;
  price: string;
  rail: "stripe" | "x402";
  paidAt: number;
  waiverAt: number | null;
  waiverText: string;
  passId?: string;
  termsUrl?: string;
}

const iso = (sec: number) => new Date(sec * 1000).toISOString().replace("T", " ").replace(/\.\d{3}Z$/, " UTC");

export function receiptText(p: Purchase): string {
  const lines = [
    `Receipt — ${p.siteName || p.siteHost}`,
    "",
    `Seller:       ${p.siteName ? `${p.siteName} (${p.siteHost})` : p.siteHost}`,
    `Article:      ${p.title}`,
    `Link:         ${p.url}`,
    `Purchased:    ${p.item}`,
    `Price:        ${p.price}`,
    `Paid with:    ${p.rail === "stripe" ? "card (processed by Stripe on the seller's account)" : "USDC (x402), paid directly to the seller's wallet"}`,
    `Date:         ${iso(p.paidAt)}`,
  ];
  if (p.passId) lines.push(`Access pass:  ${p.passId}`);
  if (p.waiverAt) {
    lines.push(
      "",
      "Immediate access and right of withdrawal",
      `On ${iso(p.waiverAt)} you confirmed: "${p.waiverText}"`,
      "The content was unlocked for you straight after payment.",
    );
  }
  if (p.termsUrl) lines.push("", `Seller's terms: ${p.termsUrl}`);
  lines.push("", "Keep this file for your records. Questions about this purchase go to the seller named above.");
  return lines.join("\n") + "\n";
}

export function downloadReceipt(p: Purchase): void {
  const blob = new Blob([receiptText(p)], { type: "text/plain;charset=utf-8" });
  const href = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = href;
  a.download = `receipt-${p.siteHost.replace(/[^a-z0-9.-]/gi, "")}-${new Date(p.paidAt * 1000).toISOString().slice(0, 10)}.txt`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.setTimeout(() => URL.revokeObjectURL(href), 1000);
}
