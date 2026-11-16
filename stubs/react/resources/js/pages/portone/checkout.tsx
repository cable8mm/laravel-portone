import { Head } from "@inertiajs/react";
import PortOne from "@portone/browser-sdk/v2";
import { useEffect, useState } from "react";

const PAYMENT_METHOD = "CARD";
const CURRENCY = "KRW";

type CheckoutState = "initial" | "loading" | "error";

type CheckoutProps = {
  product: { id: number | string; name: string; price: number };
  storeId: string | null;
  channelKey: string | null;
  prepareUrl: string;
  csrfToken: string;
  missingConfiguration: boolean;
  state: CheckoutState;
  statusText: string;
};

export default function Checkout({
  product,
  prepareUrl,
  csrfToken,
  missingConfiguration,
  state: initialState,
  statusText: initialStatusText,
}: CheckoutProps) {
  const [state, setState] = useState<CheckoutState>(initialState);
  const [statusText, setStatusText] = useState(initialStatusText);

  const fail = () => {
    setState("error");
    setStatusText("Error");
  };

  // A closed payment window returns nothing; back navigation must not leave Loading stuck.
  useEffect(() => {
    const onPageShow = () => {
      setState((current) => {
        if (current === "loading") {
          setStatusText("Error");
          return "error";
        }

        return current;
      });
    };

    window.addEventListener("pageshow", onPageShow);

    return () => window.removeEventListener("pageshow", onPageShow);
  }, []);

  const startPayment = async () => {
    setState("loading");
    setStatusText("Loading");

    try {
      // The prepare route responds with JSON, so it is a fetch request, not an Inertia visit.
      const response = await fetch(prepareUrl, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": csrfToken,
        },
        body: JSON.stringify({ product: product.id }),
      });

      if (!response.ok) {
        throw new Error(
          `Checkout request failed with HTTP ${response.status}.`,
        );
      }

      const parameters = await response.json();
      await PortOne.requestPayment({
        ...parameters,
        payMethod: PAYMENT_METHOD,
        currency: CURRENCY,
      });

      // The redirect flow leaves this page; reaching here means it did not start.
      fail();
    } catch {
      fail();
    }
  };

  return (
    <>
      <Head title="Checkout" />
      <main data-portone-checkout data-state={state}>
        <h1>{product.name}</h1>
        <p>{new Intl.NumberFormat("en-US").format(product.price)} KRW</p>
        <button
          id="portone-checkout-button"
          type="button"
          disabled={missingConfiguration || state === "loading"}
          onClick={startPayment}
        >
          Start payment
        </button>
        <p id="portone-checkout-status" role="status">
          {statusText}
        </p>
      </main>
    </>
  );
}
