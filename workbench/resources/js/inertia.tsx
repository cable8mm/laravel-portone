import { createInertiaApp } from "@inertiajs/react";
import type { ComponentType } from "react";
import { createRoot } from "react-dom/client";

// The pages published by `vendor:publish --tag=portone-stubs-react` live in
// the Testbench skeleton, which is the Application of the Workbench app.
const pages = import.meta.glob<{ default: ComponentType }>(
  "@application/pages/**/*.tsx",
  { eager: true },
);

createInertiaApp({
  resolve: (name) => {
    const page = Object.entries(pages).find(([file]) =>
      file.endsWith(`/pages/${name}.tsx`),
    )?.[1];

    if (!page) {
      throw new Error(`Publish the React stubs first: ${name} is missing.`);
    }

    return page;
  },
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />);
  },
});
