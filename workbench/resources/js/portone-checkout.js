import PortOne from "@portone/browser-sdk/v2";

const PAYMENT_METHOD = "CARD";
const CURRENCY = "KRW";

const checkout = document.querySelector("[data-portone-checkout]");

if (checkout) {
  const button = checkout.querySelector("#portone-checkout-button");
  const status = checkout.querySelector("#portone-checkout-status");

  const setState = (state, text, disabled) => {
    checkout.dataset.state = state;
    status.textContent = text;
    button.disabled = disabled;
  };

  // A closed payment window returns nothing; back navigation must not leave Loading stuck.
  window.addEventListener("pageshow", () => {
    if (checkout.dataset.state === "loading") {
      setState("error", "Error", false);
    }
  });

  const post = async (url, body) => {
    const response = await fetch(url, {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": checkout.dataset.csrfToken,
      },
      body: JSON.stringify(body),
    });

    if (!response.ok) {
      throw new Error(`Checkout request failed with HTTP ${response.status}.`);
    }

    return response.json();
  };

  if (!button.disabled) {
    button.addEventListener("click", async () => {
      setState("loading", "Loading", true);

      try {
        const parameters = await post(checkout.dataset.prepareUrl, {
          product: checkout.dataset.product,
        });
        const result = await PortOne.requestPayment({
          ...parameters,
          payMethod: PAYMENT_METHOD,
          currency: CURRENCY,
        });

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
