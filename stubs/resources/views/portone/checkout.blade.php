<main
    data-portone-checkout
    data-store-id="{{ $storeId }}"
    data-channel-key="{{ $channelKey }}"
    data-state="{{ $state }}"
>
    <button id="portone-checkout-button" type="button" @disabled($missingConfiguration)>Start payment</button>
    <p id="portone-checkout-status" role="status">{{ $statusText }}</p>
</main>
