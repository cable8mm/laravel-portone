import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import type { ComponentProps, ReactNode } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";
import Checkout from "../../stubs/react/resources/js/pages/portone/checkout";

const { requestPayment } = vi.hoisted(() => ({
  requestPayment: vi.fn(),
}));

vi.mock("@portone/browser-sdk/v2", () => ({
  default: { requestPayment },
}));

vi.mock("@inertiajs/react", () => ({
  Head: ({ title }: { title?: string; children?: ReactNode }) => (
    <title>{title}</title>
  ),
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

const props: ComponentProps<typeof Checkout> = {
  product: { id: 1, name: "Order A", price: 10000 },
  storeId: "store-123",
  channelKey: "channel-456",
  prepareUrl: "/portone/checkout/prepare",
  csrfToken: "csrf-token",
  missingConfiguration: false,
  state: "initial",
  statusText: "Idle",
};

const response = (body: unknown, ok = true, status = 200) => ({
  ok,
  status,
  json: vi.fn().mockResolvedValue(body),
});

function renderCheckout(
  overrides: Partial<ComponentProps<typeof Checkout>> = {},
) {
  const { container } = render(<Checkout {...props} {...overrides} />);

  return {
    root: container.querySelector("[data-portone-checkout]") as HTMLElement,
    button: screen.getByRole("button", { name: "Start payment" }),
    status: screen.getByRole("status"),
  };
}

afterEach(() => {
  cleanup();
  requestPayment.mockReset();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("published React checkout page", () => {
  it("shows the product in the Initial state", () => {
    vi.stubGlobal("fetch", vi.fn());
    const { root, button, status } = renderCheckout();

    expect(root.dataset.state).toBe("initial");
    expect(screen.getByRole("heading").textContent).toBe("Order A");
    expect(screen.getByText("10,000 KRW")).toBeTruthy();
    expect((button as HTMLButtonElement).disabled).toBe(false);
    expect(status.textContent).toBe("Idle");
  });

  it("disables the button in the Error state when configuration is missing", () => {
    const fetch = vi.fn();
    vi.stubGlobal("fetch", fetch);
    const { root, button, status } = renderCheckout({
      storeId: null,
      missingConfiguration: true,
      state: "error",
      statusText: "Error",
    });

    fireEvent.click(button);

    expect(root.dataset.state).toBe("error");
    expect((button as HTMLButtonElement).disabled).toBe(true);
    expect(status.textContent).toBe("Error");
    expect(fetch).not.toHaveBeenCalled();
  });

  it("posts to prepare without an Inertia visit and requests payment with the prepare values plus the constants", async () => {
    const fetch = vi.fn().mockResolvedValueOnce(response(parameters));
    vi.stubGlobal("fetch", fetch);
    const location = { assign: vi.fn() };
    vi.stubGlobal("location", location);
    requestPayment.mockResolvedValue(undefined);
    const { root, button, status } = renderCheckout();

    fireEvent.click(button);

    expect(root.dataset.state).toBe("loading");
    expect((button as HTMLButtonElement).disabled).toBe(true);
    expect(status.textContent).toBe("Loading");
    await waitFor(() => expect(requestPayment).toHaveBeenCalledTimes(1));

    expect(requestPayment).toHaveBeenCalledWith({
      ...parameters,
      payMethod: "CARD",
      currency: "KRW",
    });
    expect(fetch).toHaveBeenCalledWith("/portone/checkout/prepare", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": "csrf-token",
      },
      body: JSON.stringify({ product: 1 }),
    });
    // The redirect flow leaves the page; the page never requests complete or navigates.
    await waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(location.assign).not.toHaveBeenCalled();
  });

  it("returns to Error when PortOne reports an error code", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValueOnce(response(parameters)));
    requestPayment.mockResolvedValue({ code: "FAILURE_TYPE_PG" });
    const { root, button, status } = renderCheckout();

    fireEvent.click(button);

    await waitFor(() => expect(root.dataset.state).toBe("error"));
    expect((button as HTMLButtonElement).disabled).toBe(false);
    expect(status.textContent).toBe("Error");
  });

  it("returns to Error when the PortOne SDK rejects", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValueOnce(response(parameters)));
    requestPayment.mockRejectedValue(new Error("SDK failure"));
    const { root, button } = renderCheckout();

    fireEvent.click(button);

    await waitFor(() => expect(root.dataset.state).toBe("error"));
    expect((button as HTMLButtonElement).disabled).toBe(false);
  });

  it("returns to Error when prepare fails", async () => {
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(response({ error: "failed" }, false, 500));
    vi.stubGlobal("fetch", fetch);
    const { root, button } = renderCheckout();

    fireEvent.click(button);

    await waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(requestPayment).not.toHaveBeenCalled();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect((button as HTMLButtonElement).disabled).toBe(false);
  });

  it("returns to Error on back navigation while loading", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValueOnce(response(parameters)));
    requestPayment.mockImplementation(() => new Promise(() => {}));
    const { root, button, status } = renderCheckout();

    fireEvent.click(button);
    await waitFor(() => expect(requestPayment).toHaveBeenCalledTimes(1));

    act(() => {
      window.dispatchEvent(new Event("pageshow"));
    });

    expect(root.dataset.state).toBe("error");
    expect((button as HTMLButtonElement).disabled).toBe(false);
    expect(status.textContent).toBe("Error");
  });

  it("ignores back navigation while not loading", () => {
    vi.stubGlobal("fetch", vi.fn());
    const { root } = renderCheckout();

    act(() => {
      window.dispatchEvent(new Event("pageshow"));
    });

    expect(root.dataset.state).toBe("initial");
  });
});
