import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { resolve } from "node:path";

// One bundle per app, built separately (CT_APP=free|pro|unlock) into its own dir
// with its own manifest, so build.sh can strip the pro bundle from the free zip
// with a plain `rm -rf assets/app/pro-app` — no shared-manifest surgery. React 19
// is bundled (no manualChunks, never externalized to wp-element) so it stays
// isolated from WordPress core's React 18. 'unlock' is the FRONT-END public app
// (ships free); 'pro' is the only premium-stripped bundle.
const APP = ["pro", "unlock"].includes(process.env.CT_APP ?? "") ? (process.env.CT_APP as string) : "free";

export default defineConfig({
  root: __dirname,
  plugins: [react(), tailwindcss()],
  build: {
    outDir: resolve(__dirname, `../assets/app/${APP}-app`),
    emptyOutDir: true,
    manifest: "manifest.json",
    cssCodeSplit: false,
    rollupOptions: {
      input: resolve(__dirname, `src/${APP}/main.tsx`),
      output: {
        entryFileNames: `${APP}-app.[hash].js`,
        assetFileNames: `${APP}-app.[hash].[ext]`,
        manualChunks: undefined,
      },
    },
  },
});
