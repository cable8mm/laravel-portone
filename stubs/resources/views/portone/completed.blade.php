<main data-portone-completed data-status="{{ $payment->status }}"@if($failed) data-failed @endif>
    <h1>{{ $failed ? 'Payment failed' : 'Thank you for your order' }}</h1>
    <dl>
        <dt>Order</dt>
        <dd>{{ $payment->order_name }}</dd>
        <dt>Amount</dt>
        <dd>{{ number_format($payment->amount) }} KRW</dd>
        <dt>Payment ID</dt>
        <dd>{{ $payment->payment_id }}</dd>
        <dt>Paid at</dt>
        <dd>{{ $payment->paid_at?->toDateTimeString() }}</dd>
        <dt>Status</dt>
        <dd>{{ $payment->status }}</dd>
    </dl>
    @if($failed)
        <button type="button" onclick="history.back()">Back</button>
    @endif
</main>
