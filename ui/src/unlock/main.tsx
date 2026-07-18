import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "../index.css";
import { App } from "./App";
import type { SealedBlob } from "./unseal";

// Front-end unlock app. Mounts into the gate's locked region (3.3 emits
// <div class="ct-sealed-body crawlertoll-locked" data-...> + an inline
// <script type="application/ct-sealed+json"> blob). No fixed #id — query the
// marker the gate already renders. Guard so a page without it never throws.
const mount = document.querySelector<HTMLElement>(".ct-sealed-body.crawlertoll-locked");
const blobEl = document.querySelector('script[type="application/ct-sealed+json"]');

let blob: SealedBlob | null = null;
if (blobEl?.textContent) {
  try {
    blob = JSON.parse(blobEl.textContent) as SealedBlob;
  } catch {
    blob = null;
  }
}

if (mount) {
  createRoot(mount).render(
    <StrictMode>
      <App mount={mount} blob={blob} />
    </StrictMode>,
  );
}
