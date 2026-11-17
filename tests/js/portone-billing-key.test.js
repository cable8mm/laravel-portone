import { afterEach, describe, expect, it, vi } from "vitest";

const { requestIssueBillingKey } = vi.hoisted(() => ({
  requestIssueBillingKey: vi.fn(),
}));

vi.mock("@portone/browser-sdk/v2", () => ({
  default: { requestIssueBillingKey },
}));

// The prepare response of the billing-key stub controller (BrowserBillingKeyRequest::toArray()).
const parameters = {
  storeId: "store-123",
  channelKey: "billing-channel-789",
  billingKeyMethod: "CARD",
  issueId: "bk-123",
  issueName: "Card registration",
  redirectUrl: "http://localhost/portone/billing-key/completed/bk-123",
  customer: { customerId: "customer-123" },
  forceRedirect: true,
};

const response = (body, ok = true, status = 200) => ({
  ok,
  status,
  json: vi.fn().mockResolvedValue(body),
});

async function renderPage({ disabled = false, state = "initial" } = {}) {
  document.body.innerHTML = `
		<main
			data-portone-billing-key
			data-store-id="store-123"
			data-channel-key="billing-channel-789"
			data-prepare-url="/portone/billing-key/prepare"
			data-csrf-token="csrf-token"
			data-state="${state}"
		>
			<button id="portone-billing-key-button" type="button"${disabled ? " disabled" : ""}>Register card</button>
			<p id="portone-billing-key-status" role="status">Idle</p>
		</main>`;

  vi.resetModules();
  await import("../../stubs/resources/js/portone-billing-key.js");

  return {
    root: document.querySelector("[data-portone-billing-key]"),
    button: document.querySelector("#portone-billing-key-button"),
    status: document.querySelector("#portone-billing-key-status"),
  };
}

afterEach(() => {
  document.body.replaceChildren();
  requestIssueBillingKey.mockReset();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("published billing-key script", () => {
  it("prepares the issuance and requests it with the prepare values unchanged", async () => {
    const fetch = vi.fn().mockResolvedValueOnce(response(parameters));
    vi.stubGlobal("fetch", fetch);
    const location = { assign: vi.fn() };
    vi.stubGlobal("location", location);
    requestIssueBillingKey.mockResolvedValue(undefined);
    const { root, button, status } = await renderPage();

    button.click();

    expect(root.dataset.state).toBe("loading");
    expect(button.disabled).toBe(true);
    expect(status.textContent).toBe("Loading");
    await vi.waitFor(() =>
      expect(requestIssueBillingKey).toHaveBeenCalledTimes(1),
    );

    expect(requestIssueBillingKey).toHaveBeenCalledWith(parameters);
    expect(fetch).toHaveBeenCalledWith("/portone/billing-key/prepare", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": "csrf-token",
      },
      body: JSON.stringify({}),
    });
    // The redirect flow leaves the page; still being here means it did not start.
    await vi.waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(location.assign).not.toHaveBeenCalled();
  });

  it("returns to Error when PortOne reports an error code", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValueOnce(response(parameters)));
    requestIssueBillingKey.mockResolvedValue({ code: "FAILURE_TYPE_PG" });
    const { root, button, status } = await renderPage();

    button.click();

    await vi.waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(button.disabled).toBe(false);
    expect(status.textContent).toBe("Error");
  });

  it("returns to Error when the PortOne SDK rejects", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValueOnce(response(parameters)));
    requestIssueBillingKey.mockRejectedValue(new Error("SDK failure"));
    const { root, button } = await renderPage();

    button.click();

    await vi.waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(button.disabled).toBe(false);
  });

  it("returns to Error without calling the SDK when prepare fails", async () => {
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(response({ message: "Server Error" }, false, 500));
    vi.stubGlobal("fetch", fetch);
    const { root, button } = await renderPage();

    button.click();

    await vi.waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(requestIssueBillingKey).not.toHaveBeenCalled();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(button.disabled).toBe(false);
  });

  it("returns to Error on back navigation while loading", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValueOnce(response(parameters)));
    requestIssueBillingKey.mockImplementation(() => new Promise(() => {}));
    const { root, button, status } = await renderPage();

    button.click();

    await vi.waitFor(() => expect(root.dataset.state).toBe("loading"));
    window.dispatchEvent(new Event("pageshow"));

    expect(root.dataset.state).toBe("error");
    expect(button.disabled).toBe(false);
    expect(status.textContent).toBe("Error");
  });

  it("ignores back navigation while not loading", async () => {
    vi.stubGlobal("fetch", vi.fn());
    const { root } = await renderPage();

    window.dispatchEvent(new Event("pageshow"));

    expect(root.dataset.state).toBe("initial");
  });

  it("does nothing when the register button is disabled by missing configuration", async () => {
    const fetch = vi.fn();
    vi.stubGlobal("fetch", fetch);
    const { root, button } = await renderPage({
      disabled: true,
      state: "error",
    });

    button.click();

    expect(root.dataset.state).toBe("error");
    expect(fetch).not.toHaveBeenCalled();
    expect(requestIssueBillingKey).not.toHaveBeenCalled();
  });

  it("does nothing on the Issued or Failed page, which has no register button", async () => {
    document.body.innerHTML = `
			<main data-portone-billing-key data-state="issued">
				<p id="portone-billing-key-status" role="status">Issued</p>
			</main>`;
    vi.resetModules();

    await import("../../stubs/resources/js/portone-billing-key.js");
    window.dispatchEvent(new Event("pageshow"));

    expect(
      document.querySelector("[data-portone-billing-key]").dataset.state,
    ).toBe("issued");
  });
});
