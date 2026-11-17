<main
    data-portone-billing-key
    data-store-id="{{ $storeId }}"
    data-channel-key="{{ $channelKey }}"
    data-prepare-url="{{ $prepareUrl }}"
    data-csrf-token="{{ csrf_token() }}"
    data-state="{{ $state }}"
    @isset($issueId) data-issue-id="{{ $issueId }}" @endisset
>
    <h1>Register a card</h1>
    @if(in_array($state, ['issued', 'failed'], true))
        <p id="portone-billing-key-status" role="status">{{ $statusText }}</p>
        <a href="{{ route('portone.billing-key') }}">Back</a>
    @else
        <button id="portone-billing-key-button" type="button" @disabled($missingConfiguration)>Register card</button>
        <p id="portone-billing-key-status" role="status">{{ $statusText }}</p>
    @endif
</main>

@vite('resources/js/portone-billing-key.js')
