import { fileURLToPath } from "node:url";
import tailwindcss from "@tailwindcss/vite";
import laravel from "laravel-vite-plugin";
import { defineConfig } from "vite";

// The Workbench app is served from the Testbench skeleton, so its public
// directory receives the build; entries resolve from workbench/ like an
// Application's resources/.
export default defineConfig({
  root: fileURLToPath(new URL(".", import.meta.url)),
  plugins: [
    laravel({
      input: ["resources/css/app.css", "resources/js/portone-checkout.js"],
      publicDirectory: "../vendor/orchestra/testbench-core/laravel/public",
      refresh: ["resources/views/**", "routes/**"],
    }),
    tailwindcss(),
  ],
});
