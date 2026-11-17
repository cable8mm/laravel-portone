import PortOne from "@portone/browser-sdk/v2";

const page = document.querySelector("[data-portone-billing-key]");
const button = page?.querySelector("#portone-billing-key-button");

if (page && button) {
  const status = page.querySelector("#portone-billing-key-status");

  const setState = (state, text, disabled) => {
    page.dataset.state = state;
    status.textContent = text;
    button.disabled = disabled;
  };

  // A closed window may return nothing; back navigation must not leave Loading stuck.
  window.addEventListener("pageshow", () => {
    if (page.dataset.state === "loading") {
      setState("error", "Error", false);
    }
  });

  const prepare = async () => {
    const response = await fetch(page.dataset.prepareUrl, {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": page.dataset.csrfToken,
      },
      body: JSON.stringify({}),
    });

    if (!response.ok) {
      throw new Error(
        `Billing-key request failed with HTTP ${response.status}.`,
      );
    }

    return response.json();
  };

  if (!button.disabled) {
    button.addEventListener("click", async () => {
      setState("loading", "Loading", true);

      try {
        const parameters = await prepare();
        const result = await PortOne.requestIssueBillingKey(parameters);

        if (result && result.code) {
          setState("error", "Error", false);
          return;
        }

        // The redirect flow leaves this page; reaching here means it did not start.
        setState("error", "Error", false);
      } catch {
        setState("error", "Error", false);
      }
    });
  }
}
