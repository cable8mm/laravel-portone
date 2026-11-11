<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Workbench billing key result</title>
</head>
<body>
    {{-- The billing key itself is never shown. --}}
    <main data-workbench-billing-key-completed data-result="{{ $result }}" data-status="{{ $status }}">
        <p>Billing-key issuance {{ $issueId }}: {{ $result }} (record {{ $status }})</p>
        @if ($error !== null)
            <p>{{ $error }}</p>
        @endif
    </main>
</body>
</html>
