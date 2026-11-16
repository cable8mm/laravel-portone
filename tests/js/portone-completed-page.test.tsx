import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";
import Completed from "../../stubs/react/resources/js/pages/portone/completed";

vi.mock("@inertiajs/react", () => ({
  Head: ({ title }: { title?: string; children?: ReactNode }) => (
    <title>{title}</title>
  ),
}));

const payment = {
  payment_id: "payment-123",
  order_name: "Order A",
  amount: 10000,
  paid_at: "2026-11-17 10:00:00",
  status: "PAID",
};

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe("published React completed page", () => {
  it("shows the paid order", () => {
    const { container } = render(
      <Completed payment={payment} failed={false} />,
    );
    const root = container.querySelector(
      "[data-portone-completed]",
    ) as HTMLElement;

    expect(root.dataset.status).toBe("PAID");
    expect(root.hasAttribute("data-failed")).toBe(false);
    expect(screen.getByRole("heading").textContent).toBe(
      "Thank you for your order",
    );
    expect(screen.getByText("Order A")).toBeTruthy();
    expect(screen.getByText("10,000 KRW")).toBeTruthy();
    expect(screen.getByText("payment-123")).toBeTruthy();
    expect(screen.getByText("2026-11-17 10:00:00")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Back" })).toBeNull();
  });

  it("shows the Failed state with a back button", () => {
    const back = vi.spyOn(window.history, "back").mockImplementation(() => {});
    const { container } = render(
      <Completed
        payment={{ ...payment, paid_at: null, status: "PENDING" }}
        failed
      />,
    );
    const root = container.querySelector(
      "[data-portone-completed]",
    ) as HTMLElement;

    expect(root.dataset.status).toBe("PENDING");
    expect(root.hasAttribute("data-failed")).toBe(true);
    expect(screen.getByRole("heading").textContent).toBe("Payment failed");

    fireEvent.click(screen.getByRole("button", { name: "Back" }));

    expect(back).toHaveBeenCalledTimes(1);
  });
});
