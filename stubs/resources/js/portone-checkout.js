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
        const payment = await post(checkout.dataset.prepareUrl, {
          product: checkout.dataset.product,
        });
        const result = await PortOne.requestPayment({
          storeId: checkout.dataset.storeId,
          channelKey: checkout.dataset.channelKey,
          paymentId: payment.paymentId,
          orderName: payment.orderName,
          totalAmount: payment.totalAmount,
          customer: { customerId: payment.customerId },
          payMethod: PAYMENT_METHOD,
          currency: CURRENCY,
        });

        if (!result || result.code) {
          setState("error", "Error", false);
          return;
        }

        const completion = await post(checkout.dataset.completeUrl, {
          paymentId: payment.paymentId,
        });

        if (completion.status !== "PAID") {
          setState("error", "Error", false);
          return;
        }

        setState("success", "Success", true);
        window.location.assign(completion.redirectUrl);
      } catch {
        setState("error", "Error", false);
      }
    });
  }
}
