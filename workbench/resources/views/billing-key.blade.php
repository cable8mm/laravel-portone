<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Workbench billing key</title>
</head>
<body>
    {{-- Issues one Package-prepared billing key; the PG window redirects to the completed page (forceRedirect). --}}
    <main data-workbench-billing-key data-result="pending">
        <button id="workbench-billing-key-button" type="button" disabled>Register a test card ({{ $issuance['issueName'] }})</button>
    </main>
    <script id="workbench-billing-key-config" type="application/json">@json(['storeId' => $storeId, 'channelKey' => $channelKey, 'issuance' => $issuance, 'redirectUrl' => $redirectUrl])</script>
    <script type="module">
        import PortOne from "{{ $sdkUrl }}";

        const { storeId, channelKey, issuance, redirectUrl } = JSON.parse(document.querySelector("#workbench-billing-key-config").textContent);
        const main = document.querySelector("[data-workbench-billing-key]");
        const button = document.querySelector("#workbench-billing-key-button");

        // KCP opens its window only when the SDK is called synchronously in the click handler (T-045: "[3017]").
        button.addEventListener("click", () => {
            button.disabled = true;

            PortOne.requestIssueBillingKey({
                storeId,
                channelKey,
                billingKeyMethod: "CARD",
                issueId: issuance.issueId,
                issueName: issuance.issueName,
                customer: {
                    customerId: issuance.customerId,
                    fullName: "Workbench Tester",
                    phoneNumber: "01000000000",
                    email: "workbench@example.com",
                },
                redirectUrl,
                forceRedirect: true,
            }).then(
                // With forceRedirect a success leaves the page; a resolved result here is an SDK-side failure.
                (result) => { if (result === undefined || result.code) main.dataset.result = "error"; },
                () => { main.dataset.result = "error"; },
            );
        });
        button.disabled = false;
    </script>
</body>
</html>
