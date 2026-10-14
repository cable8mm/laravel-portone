@php
    [$label, $classes] = match ($status) {
        'PAID' => ['결제 완료', 'bg-mint-100 text-mint-500'],
        'PARTIALLY_CANCELLED' => ['부분 취소', 'bg-butter text-amber-600'],
        'CANCELLED' => ['전액 취소', 'bg-slate-100 text-slate-500'],
        'EXPIRED' => ['만료', 'bg-slate-200 text-slate-500'],
        default => ['결제 대기', 'bg-sky-soft text-sky-600'],
    };
@endphp
<span class="chip {{ $classes }}" data-status="{{ $status }}">{{ $label }}</span>
