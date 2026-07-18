// Sanitize the decrypted body before injecting it via dangerouslySetInnerHTML.
// The body is the publisher's OWN authored content (the same bytes the_content
// would render), but injecting decrypted bytes is an XSS sink in a tampered
// key-release scenario, so we run it through DOMPurify (the vetted standard;
// strips <script>, inline event handlers, javascript: URLs, etc.). Browser-only
// (DOMPurify needs a DOM) — the decrypt+render path runs only in the browser.

import DOMPurify from "dompurify";

// Red-team note (verified): DOMPurify strips every browser-executable XSS vector
// here (script, on* handlers, javascript:/data: URLs, iframe/object, mutation-XSS).
// The only survivors are legacy CSS-in-style vectors (expression()/-moz-binding/
// behavior) which are INERT in all current browsers (IE-only / Firefox<57). We
// keep inline `style` to preserve the paid content's fidelity; the proper fix is
// the planned server-side the_content re-render of the decrypted body (which drops
// raw injection entirely). To harden anyway, add `"style"` to FORBID_ATTR.
export function sanitizeBody(html: string): string {
  return DOMPurify.sanitize(String(html), {
    // Keep target=_blank rels safe; forbid event handlers + dangerous protocols
    // (DOMPurify does this by default — these just harden intent).
    FORBID_ATTR: ["onerror", "onload", "onclick"],
    ADD_ATTR: ["target", "rel"],
    USE_PROFILES: { html: true },
  });
}
