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
import BillingKey from "../../stubs/react/resources/js/pages/portone/billing-key";

const { requestIssueBillingKey } = vi.hoisted(() => ({
  requestIssueBillingKey: vi.fn(),
}));

vi.mock("@portone/browser-sdk/v2", () => ({
  default: { requestIssueBillingKey },
}));

vi.mock("@inertiajs/react", () => ({
  Head: ({ title }: { title?: string; children?: ReactNode }) => (
    <title>{title}</title>
  ),
  Link: ({ href, children }: { href: string; children?: ReactNode }) => (
    <a href={href}>{children}</a>
  ),
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

const props: ComponentProps<typeof BillingKey> = {
  storeId: "store-123",
  channelKey: "billing-channel-789",
  prepareUrl: "/portone/billing-key/prepare",
  billingKeyUrl: "/portone/billing-key",
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

function renderPage(
  overrides: Partial<ComponentProps<typeof BillingKey>> = {},
) {
  const { container } = render(<BillingKey {...props} {...overrides} />);

  return {
    root: container.querySelector("[data-portone-billing-key]") as HTMLElement,
    button: screen.queryByRole("button", {
      name: "Register card",
    }) as HTMLButtonElement | null,
    status: screen.getByRole("status"),
  };
}

afterEach(() => {
  cleanup();
  requestIssueBillingKey.mockReset();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("published React billing-key page", () => {
  it("shows the register button before the status in the Initial state", () => {
    vi.stubGlobal("fetch", vi.fn());
    const { root, button, status } = renderPage();

    expect(root.dataset.state).toBe("initial");
    expect(root.hasAttribute("data-issue-id")).toBe(false);
    expect(screen.getByRole("heading").textContent).toBe("Register a card");
    expect(button?.disabled).toBe(false);
    expect(status.textContent).toBe("Idle");
    expect(
      button!.compareDocumentPosition(status) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
  });

  it("disables the button in the Error state when configuration is missing", () => {
    const fetch = vi.fn();
    vi.stubGlobal("fetch", fetch);
    const { root, button, status } = renderPage({
      channelKey: null,
      missingConfiguration: true,
      state: "error",
      statusText: "Error",
    });

    fireEvent.click(button!);

    expect(root.dataset.state).toBe("error");
    expect(button?.disabled).toBe(true);
    expect(status.textContent).toBe("Error");
    expect(fetch).not.toHaveBeenCalled();
    expect(requestIssueBillingKey).not.toHaveBeenCalled();
  });

  it("posts to prepare without an Inertia visit and requests the issuance with the prepare values unchanged", async () => {
    const fetch = vi.fn().mockResolvedValueOnce(response(parameters));
    vi.stubGlobal("fetch", fetch);
    const location = { assign: vi.fn() };
    vi.stubGlobal("location", location);
    requestIssueBillingKey.mockResolvedValue(undefined);
    const { root, button, status } = renderPage();

    fireEvent.click(button!);

    expect(root.dataset.state).toBe("loading");
    expect(button?.disabled).toBe(true);
    expect(status.textContent).toBe("Loading");
    await waitFor(() =>
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
    await waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(location.assign).not.toHaveBeenCalled();
  });

  it("returns to Error when PortOne reports an error code", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValueOnce(response(parameters)));
    requestIssueBillingKey.mockResolvedValue({ code: "FAILURE_TYPE_PG" });
    const { root, button, status } = renderPage();

    fireEvent.click(button!);

    await waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(button?.disabled).toBe(false);
    expect(status.textContent).toBe("Error");
  });

  it("returns to Error when the PortOne SDK rejects", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValueOnce(response(parameters)));
    requestIssueBillingKey.mockRejectedValue(new Error("SDK failure"));
    const { root, button } = renderPage();

    fireEvent.click(button!);

    await waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(button?.disabled).toBe(false);
  });

  it("returns to Error without calling the SDK when prepare fails", async () => {
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(response({ message: "Server Error" }, false, 500));
    vi.stubGlobal("fetch", fetch);
    const { root, button } = renderPage();

    fireEvent.click(button!);

    await waitFor(() => expect(root.dataset.state).toBe("error"));
    expect(requestIssueBillingKey).not.toHaveBeenCalled();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(button?.disabled).toBe(false);
  });

  it("returns to Error on back navigation while loading", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValueOnce(response(parameters)));
    requestIssueBillingKey.mockImplementation(() => new Promise(() => {}));
    const { root, button, status } = renderPage();

    fireEvent.click(button!);
    await waitFor(() =>
      expect(requestIssueBillingKey).toHaveBeenCalledTimes(1),
    );

    act(() => {
      window.dispatchEvent(new Event("pageshow"));
    });

    expect(root.dataset.state).toBe("error");
    expect(button?.disabled).toBe(false);
    expect(status.textContent).toBe("Error");
  });

  it("ignores back navigation while not loading", () => {
    vi.stubGlobal("fetch", vi.fn());
    const { root } = renderPage();

    act(() => {
      window.dispatchEvent(new Event("pageshow"));
    });

    expect(root.dataset.state).toBe("initial");
  });

  it.each([
    ["issued", "Issued"],
    ["failed", "Failed"],
  ] as const)(
    "shows the %s result with a way back and no register button",
    (state, statusText) => {
      const fetch = vi.fn();
      vi.stubGlobal("fetch", fetch);
      const { root, button, status } = renderPage({
        storeId: null,
        channelKey: null,
        state,
        statusText,
        issueId: "bk-123",
      });

      act(() => {
        window.dispatchEvent(new Event("pageshow"));
      });

      expect(root.dataset.state).toBe(state);
      expect(root.dataset.issueId).toBe("bk-123");
      expect(button).toBeNull();
      expect(status.textContent).toBe(statusText);
      expect(
        screen.getByRole("link", { name: "Back" }).getAttribute("href"),
      ).toBe("/portone/billing-key");
      expect(fetch).not.toHaveBeenCalled();
    },
  );
});
