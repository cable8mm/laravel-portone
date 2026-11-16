import { Head } from "@inertiajs/react";

type CompletedProps = {
  payment: {
    payment_id: string;
    order_name: string;
    amount: number;
    paid_at: string | null;
    status: string;
  };
  failed: boolean;
};

export default function Completed({ payment, failed }: CompletedProps) {
  const title = failed ? "Payment failed" : "Thank you for your order";

  return (
    <>
      <Head title={title} />
      <main
        data-portone-completed
        data-status={payment.status}
        data-failed={failed ? "" : undefined}
      >
        <h1>{title}</h1>
        <dl>
          <dt>Order</dt>
          <dd>{payment.order_name}</dd>
          <dt>Amount</dt>
          <dd>{new Intl.NumberFormat("en-US").format(payment.amount)} KRW</dd>
          <dt>Payment ID</dt>
          <dd>{payment.payment_id}</dd>
          <dt>Paid at</dt>
          <dd>{payment.paid_at}</dd>
          <dt>Status</dt>
          <dd>{payment.status}</dd>
        </dl>
        {failed && (
          <button type="button" onClick={() => window.history.back()}>
            Back
          </button>
        )}
      </main>
    </>
  );
}
