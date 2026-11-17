{{-- The published billing-key view (stubs/resources/views/portone/billing-key.blade.php),
     given the shop layout; its data attributes and ids are unchanged. --}}
@extends('workbench::layouts.app')

@section('title', '카드 등록')

@section('content')
    <main
        data-portone-billing-key
        data-store-id="{{ $storeId }}"
        data-channel-key="{{ $channelKey }}"
        data-prepare-url="{{ $prepareUrl }}"
        data-csrf-token="{{ csrf_token() }}"
        data-state="{{ $state }}"
        @isset($issueId) data-issue-id="{{ $issueId }}" @endisset
        class="card mx-auto max-w-md"
    >
        <h1 class="text-center text-2xl font-black text-slate-800">Register a card</h1>
        @if(in_array($state, ['issued', 'failed'], true))
            <p id="portone-billing-key-status" role="status" class="mt-4 text-center text-sm font-bold">{{ $statusText }}</p>
            <a href="{{ route('portone.billing-key') }}" class="btn-ghost mt-4 flex w-full">Back</a>
        @else
            <button id="portone-billing-key-button" type="button" class="btn-primary mt-6 w-full py-4 text-lg" @disabled($missingConfiguration)>Register card</button>
            <p id="portone-billing-key-status" role="status" class="mt-4 text-center text-sm font-bold">{{ $statusText }}</p>
        @endif
    </main>

    @vite('resources/js/portone-billing-key.js')
@endsection
