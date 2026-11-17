import { Head, Link } from "@inertiajs/react";
import PortOne from "@portone/browser-sdk/v2";
import { useEffect, useState } from "react";

type BillingKeyState = "initial" | "loading" | "error" | "issued" | "failed";

type BillingKeyProps = {
  storeId: string | null;
  channelKey: string | null;
  prepareUrl: string;
  billingKeyUrl: string;
  csrfToken: string;
  missingConfiguration: boolean;
  state: BillingKeyState;
  statusText: string;
  issueId?: string;
};

export default function BillingKey({
  prepareUrl,
  billingKeyUrl,
  csrfToken,
  missingConfiguration,
  state: initialState,
  statusText: initialStatusText,
  issueId,
}: BillingKeyProps) {
  const [state, setState] = useState<BillingKeyState>(initialState);
  const [statusText, setStatusText] = useState(initialStatusText);

  const fail = () => {
    setState("error");
    setStatusText("Error");
  };

  // A closed window may return nothing; back navigation must not leave Loading stuck.
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

  const registerCard = async () => {
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
        body: JSON.stringify({}),
      });

      if (!response.ok) {
        throw new Error(
          `Billing-key request failed with HTTP ${response.status}.`,
        );
      }

      const parameters = await response.json();
      await PortOne.requestIssueBillingKey(parameters);

      // The redirect flow leaves this page; reaching here means it did not start.
      fail();
    } catch {
      fail();
    }
  };

  const returned = state === "issued" || state === "failed";

  return (
    <>
      <Head title="Register a card" />
      <main data-portone-billing-key data-state={state} data-issue-id={issueId}>
        <h1>Register a card</h1>
        {returned ? (
          <>
            <p id="portone-billing-key-status" role="status">
              {statusText}
            </p>
            <Link href={billingKeyUrl}>Back</Link>
          </>
        ) : (
          <>
            <button
              id="portone-billing-key-button"
              type="button"
              disabled={missingConfiguration || state === "loading"}
              onClick={registerCard}
            >
              Register card
            </button>
            <p id="portone-billing-key-status" role="status">
              {statusText}
            </p>
          </>
        )}
      </main>
    </>
  );
}
