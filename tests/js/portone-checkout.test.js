import { afterEach, describe, expect, it, vi } from "vitest";

const { requestPayment } = vi.hoisted(() => ({
  requestPayment: vi.fn(),
}));

vi.mock("@portone/browser-sdk/v2", () => ({
  default: { requestPayment },
}));

const parameters = {
  storeId: "store-123",
  channelKey: "channel-456",
  paymentId: "payment-123",
  orderName: "Order A",
  totalAmount: 10000,
  redirectUrl: "http://localhost/portone/checkout/completed/payment-123",
  customer: { customerId: "customer-123" },
  forceRedirect: true,
};

const response = (body, ok = true, status = 200) => ({
  ok,
  status,
  json: vi.fn().mockResolvedValue(body),
});

async function renderCheckout() {
  document.body.innerHTML = `
		<main
			data-portone-checkout
			data-store-id="store-123"
			data-channel-key="channel-456"
			data-prepare-url="/portone/checkout/prepare"
			data-product="1"
			data-csrf-token="csrf-token"
			data-state="initial"
		>
			<button id="portone-checkout-button" type="button">Start payment</button>
			<p id="portone-checkout-status" role="status">Idle</p>
		</main>`;

  vi.resetModules();
  await import("../../stubs/resources/js/portone-checkout.js");

  return {
    root: document.querySelector("[data-portone-checkout]"),
    button: document.querySelector("#portone-checkout-button"),
    status: document.querySelector("#portone-checkout-status"),
  };
}

afterEach(() => {
  document.body.replaceChildren();
  requestPayment.mockReset();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("published checkout script", () => {
  it("prepares the product and requests payment with the prepare values plus the constants", async () => {
    const fetch = vi.fn().mockResolvedValueOnce(response(parameters));
    vi.stubGlobal("fetch", fetch);
    const location = { assign: vi.fn() };
    vi.stubGlobal("location", location);
    requestPayment.mockResolvedValue(undefined);
    const { root, button, status } = await renderCheckout();

    button.click();

    expect(root.dataset.state).toBe("loading");
    expect(button.disabled).toBe(true);
    expect(status.textContent).toBe("Loading");
    await vi.waitFor(() => expect(requestPayment).toHaveBeenCalledTimes(1));

    expect(requestPayment).toHaveBeenCalledWith({
      ...parameters,
      payMethod: "CARD",
      currency: "KRW",
    });
    expect(fetch).toHaveBeenNthCalledWith(1, "/portone/checkout/prepare", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": "csrf-token",
      },
      body: JSON.stringify({ product: "1" }),
    });
    // The redirect flow leaves the page; the script never requests complete or navigates.
    await vi.waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(location.assign).not.toHaveBeenCalled();
  });

  it("returns to Error without navigating when PortOne reports an error code", async () => {
    const fetch = vi.fn().mockResolvedValueOnce(response(parameters));
    vi.stubGlobal("fetch", fetch);
    requestPayment.mockResolvedValue({ code: "FAILURE_TYPE_PG" });
    const location = { assign: vi.fn() };
    vi.stubGlobal("location", location);
    const { root, button, status } = await renderCheckout();

    button.click();

    await vi.waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(button.disabled).toBe(false);
    expect(status.textContent).toBe("Error");
    expect(location.assign).not.toHaveBeenCalled();
  });

  it("returns to Error when the PortOne SDK rejects", async () => {
    const fetch = vi.fn().mockResolvedValueOnce(response(parameters));
    vi.stubGlobal("fetch", fetch);
    requestPayment.mockRejectedValue(new Error("SDK failure"));
    const location = { assign: vi.fn() };
    vi.stubGlobal("location", location);
    const { root, button } = await renderCheckout();

    button.click();

    await vi.waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(button.disabled).toBe(false);
    expect(location.assign).not.toHaveBeenCalled();
  });

  it("returns to Error when prepare fails", async () => {
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(response({ error: "failed" }, false, 500));
    vi.stubGlobal("fetch", fetch);
    const location = { assign: vi.fn() };
    vi.stubGlobal("location", location);
    const { root, button } = await renderCheckout();

    button.click();

    await vi.waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(requestPayment).not.toHaveBeenCalled();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(button.disabled).toBe(false);
    expect(location.assign).not.toHaveBeenCalled();
  });

  it("returns to Error on back navigation while loading", async () => {
    const fetch = vi.fn().mockResolvedValueOnce(response(parameters));
    vi.stubGlobal("fetch", fetch);
    requestPayment.mockImplementation(() => new Promise(() => {}));
    const { root, button, status } = await renderCheckout();

    button.click();

    await vi.waitFor(() => expect(root.dataset.state).toBe("loading"));
    window.dispatchEvent(new Event("pageshow"));

    expect(root.dataset.state).toBe("error");
    expect(button.disabled).toBe(false);
    expect(status.textContent).toBe("Error");
  });

  it("ignores back navigation while not loading", async () => {
    vi.stubGlobal("fetch", vi.fn());
    const { root } = await renderCheckout();

    window.dispatchEvent(new Event("pageshow"));

    expect(root.dataset.state).toBe("initial");
  });
});
