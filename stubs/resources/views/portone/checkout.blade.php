<main
    data-portone-checkout
    data-store-id="{{ $storeId }}"
    data-channel-key="{{ $channelKey }}"
    data-prepare-url="{{ $prepareUrl }}"
    data-product="{{ $product->id }}"
    data-csrf-token="{{ csrf_token() }}"
    data-state="{{ $state }}"
>
    <h1>{{ $product->name }}</h1>
    <p>{{ number_format($product->price) }} KRW</p>
    <button id="portone-checkout-button" type="button" @disabled($missingConfiguration)>Start payment</button>
    <p id="portone-checkout-status" role="status">{{ $statusText }}</p>
</main>

@vite('resources/js/portone-checkout.js')
