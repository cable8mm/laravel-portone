@extends('workbench::layouts.app')

@section('title', '고객 정보')

@section('content')
    <h1 class="text-3xl font-black text-slate-800">🪪 포트원 고객</h1>
    <p class="mt-1 text-slate-500">로그인한 사용자(Billable 모델)에 연결된 포트원 고객 ID예요. 첫 결제하기 때 자동으로 만들어져요.</p>

    <section class="card mt-6 max-w-xl" data-customer>
        <dl class="space-y-3 text-sm">
            <div class="flex items-center justify-between">
                <dt class="text-slate-500"><code class="font-mono">hasPortOneId()</code></dt>
                <dd><span @class(['chip', 'bg-mint-100 text-mint-500' => $hasPortOneId, 'bg-slate-100 text-slate-500' => ! $hasPortOneId]) data-has-portone-id="{{ $hasPortOneId ? 'true' : 'false' }}">{{ $hasPortOneId ? 'true' : 'false' }}</span></dd>
            </div>
            <div class="flex items-center justify-between gap-4">
                <dt class="text-slate-500"><code class="font-mono">portOneId()</code></dt>
                <dd class="truncate font-mono text-xs" data-portone-id>{{ $portOneId ?? 'null' }}</dd>
            </div>
        </dl>

        <form method="post" action="{{ route('shop.customer.store') }}" class="mt-6">
            @csrf
            <button class="btn-primary w-full">{{ $hasPortOneId ? '다시 호출해도 같은 ID예요 · createOrGetPortOneCustomerId()' : '고객 ID 만들기 · createOrGetPortOneCustomerId()' }}</button>
        </form>
    </section>
@endsection

@section('code')
    @include('workbench::partials.code', [
        'file' => 'workbench/app/Http/Controllers/CustomerController.php',
        'note' => '고객 레코드는 패키지의 portone_customers 테이블에 저장되고, 결제창에 customerId로 전달됩니다.',
        'code' => "\$request->user()->hasPortOneId();\n\$request->user()->portOneId();\n\$request->user()->createOrGetPortOneCustomerId();",
    ])
@endsection
