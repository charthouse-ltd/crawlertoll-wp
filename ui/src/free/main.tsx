import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "../index.css";
import { App } from "./App";

// Mount into our own node with our own bundled React 19 root — never share a
// tree with WordPress core's React 18 (wp-element). Guard on the node so a page
// without the mount div doesn't throw.
const mount = document.getElementById("crawlertoll-free-app");
if (mount) {
  createRoot(mount).render(
    <StrictMode>
      <App />
    </StrictMode>,
  );
}
