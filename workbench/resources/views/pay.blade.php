<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Workbench payment</title>
</head>
<body>
    {{-- Pays one Package-prepared payment so a live test can then verify it server-side. --}}
    <main data-workbench-pay data-result="pending">
        <button id="workbench-pay-button" type="button" disabled>Pay {{ $checkout['totalAmount'] }} KRW test payment</button>
    </main>
    <script id="workbench-pay-config" type="application/json">@json(['storeId' => $storeId, 'channelKey' => $channelKey, 'checkout' => $checkout])</script>
    <script type="module">
        import PortOne from "{{ $sdkUrl }}";

        const { storeId, channelKey, checkout } = JSON.parse(document.querySelector("#workbench-pay-config").textContent);
        const main = document.querySelector("[data-workbench-pay]");
        const button = document.querySelector("#workbench-pay-button");

        button.addEventListener("click", async () => {
            button.disabled = true;
            let hasError = true;

            try {
                const result = await PortOne.requestPayment({
                    storeId,
                    channelKey,
                    paymentId: checkout.paymentId,
                    orderName: checkout.orderName,
                    totalAmount: checkout.totalAmount,
                    customer: { customerId: checkout.customerId },
                    payMethod: "CARD",
                    currency: "KRW",
                });
                hasError = result === undefined || Boolean(result.code);
            } catch {
                hasError = true;
            }

            main.dataset.result = hasError ? "error" : "paid";
        });
        button.disabled = false;
    </script>
</body>
</html>
