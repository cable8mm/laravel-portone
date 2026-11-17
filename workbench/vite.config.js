import { fileURLToPath } from "node:url";
import path from "node:path";
import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import laravel from "laravel-vite-plugin";
import { defineConfig } from "vite";

const root = fileURLToPath(new URL(".", import.meta.url));

// The Testbench skeleton that serves the Workbench app: testbench-core for
// `composer serve`, testbench-dusk for live tests (WORKBENCH_APP_PATH).
const application = path.resolve(
  root,
  process.env.WORKBENCH_APP_PATH ??
    "../vendor/orchestra/testbench-core/laravel",
);

// The skeleton's public directory (relative to root, as the plugin expects) receives the build; entries resolve from
// workbench/ like an Application's resources/. The Inertia entry renders the
// React stubs published into the skeleton (@application).
export default defineConfig({
  root,
  resolve: {
    alias: { "@application": path.join(application, "resources/js") },
  },
  plugins: [
    laravel({
      input: [
        "resources/css/app.css",
        "resources/js/portone-checkout.js",
        "resources/js/portone-billing-key.js",
        "resources/js/inertia.tsx",
      ],
      publicDirectory: path.relative(root, path.join(application, "public")),
      refresh: ["resources/views/**", "routes/**"],
    }),
    react(),
    tailwindcss(),
  ],
});
