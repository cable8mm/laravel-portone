@extends('workbench::layouts.app')

@section('title', '개발자 안내')

@section('content')
    {{-- Developer guide of `composer serve`: what is running, where the sample code is, how to try it. --}}
    <div data-workbench-guide class="space-y-6">
        <section class="card text-center">
            <p class="text-5xl">🍓🧁🎁</p>
            <h1 class="mt-3 text-3xl font-black text-slate-800">laravel-portone Workbench</h1>
            <p class="mx-auto mt-2 max-w-xl text-slate-500">
                패키지를 설치한 Laravel 앱이 실제로 어떻게 동작하는지 보여 주는 샘플 상점이에요.
                SQLite 위에서 상품 → 주문서 → 포트원 결제창 → 결제 내역 → 전액·부분 취소까지 그대로 동작하고, 결제창은 직접 조작합니다.
            </p>

            @if ($signInUrl)
                <a href="{{ $signInUrl }}" class="btn-primary mt-6 px-8 py-4 text-lg" data-sign-in>🚀 실제 구현 체험하기</a>
                <p class="mt-2 text-xs text-slate-400">시드된 사용자 test@example.com으로 로그인해 상점으로 이동합니다.</p>
            @else
                <p class="mt-6 text-sm text-candy-600" data-sign-in>orchestra/workbench가 등록되지 않아 로그인 링크를 만들 수 없어요.</p>
            @endif
        </section>

        <div class="grid gap-6 md:grid-cols-2">
            <section class="card">
                <h2 class="text-lg font-black text-slate-800">⚙️ 설정</h2>
                <p class="mt-1 text-sm text-slate-500">루트 <code class="font-mono">.env</code>에서 읽어요. 값은 표시하지 않아요.</p>
                <ul class="mt-4 space-y-2 text-sm">
                    @foreach ($configured as $name => $isConfigured)
                        <li class="flex items-center justify-between" data-workbench-setting="{{ $name }}">
                            <code class="font-mono text-xs">{{ $name }}</code>
                            <span @class(['chip', 'bg-mint-100 text-mint-500' => $isConfigured, 'bg-candy-100 text-candy-600' => ! $isConfigured])>{{ $isConfigured ? 'configured' : 'not configured' }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="card">
                <h2 class="text-lg font-black text-slate-800">🛣 라우트</h2>
                <ul class="mt-4 space-y-3 text-sm">
                    @if ($webhookPath)
                        <li data-workbench-route="webhook"><code class="font-mono">POST {{ $webhookPath }}</code><br><span class="text-slate-500">웹훅 수신 (패키지가 자동 등록)</span></li>
                    @endif
                    <li data-workbench-route="checkout">
                        <code class="font-mono">GET /portone/checkout?product={id}</code><br>
                        <span class="text-slate-500">
                            @switch($checkoutStubs)
                                @case('published') 주문서: 앱에 발행된 체크아웃 스텁을 쓰고 있어요. @break
                                @case('sample') 주문서: 워크벤치에 있는 스텁 사본(product()만 수정)을 쓰고 있어요. @break
                                @default 체크아웃 스텁이 등록되지 않았어요.
                            @endswitch
                        </span>
                    </li>
                    <li><code class="font-mono">/shop</code><br><span class="text-slate-500">샘플 상점 (로그인 필요)</span></li>
                </ul>
            </section>
        </div>

        <section class="card">
            <h2 class="text-lg font-black text-slate-800">🧭 결제 흐름</h2>
            <ol class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <li class="rounded-2xl bg-candy-50 p-4"><b>1. 상품 상세</b><br>앱의 화면. 아무것도 저장하지 않고 상품 ID만 들고 주문서로 이동</li>
                <li class="rounded-2xl bg-candy-50 p-4"><b>2. 주문서</b><br>체크아웃 스텁 <code>show</code>. <code>product()</code>로 상품을 조회해 보여 줌</li>
                <li class="rounded-2xl bg-candy-50 p-4"><b>3. 결제하기</b><br><code>prepare</code>가 같은 <code>product()</code>로 금액을 정해 PENDING 생성 → 결제창</li>
                <li class="rounded-2xl bg-candy-50 p-4"><b>4. 완료</b><br><code>complete</code>가 포트원 조회로 금액을 검증해 PAID → 내역·전액/부분 취소</li>
            </ol>
        </section>

        <section class="card" data-workbench-features>
            <h2 class="text-lg font-black text-slate-800">🗺 패키지 기능 지도</h2>
            <p class="mt-1 text-sm text-slate-500">패키지가 제공하는 기능과, 상점에서 직접 해 볼 수 있는 곳이에요.</p>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-xl text-left text-sm">
                    <thead class="text-xs text-slate-400"><tr><th class="py-2">기능</th><th>코드</th><th>해 보는 곳</th></tr></thead>
                    <tbody class="divide-y divide-candy-100">
                        <tr><td class="py-2 font-bold">포트원 고객</td><td class="font-mono text-xs">hasPortOneId() · portOneId() · createOrGetPortOneCustomerId()</td><td>고객</td></tr>
                        <tr><td class="py-2 font-bold">결제 준비</td><td class="font-mono text-xs">preparePayment() · checkoutParameters()</td><td>주문서 → 결제하기</td></tr>
                        <tr><td class="py-2 font-bold">결제 완료</td><td class="font-mono text-xs">completePayment()</td><td>결제창 직후 자동 · 결제 대기 건의 결제 확인</td></tr>
                        <tr><td class="py-2 font-bold">전액 취소</td><td class="font-mono text-xs">refund($id, $reason)</td><td>결제 상세 → 전액 취소</td></tr>
                        <tr><td class="py-2 font-bold">부분 취소</td><td class="font-mono text-xs">refund($id, $reason, $amount)</td><td>결제 상세 → 부분 취소 (여러 번)</td></tr>
                        <tr><td class="py-2 font-bold">결제 내역</td><td class="font-mono text-xs">payments() · findPayment()</td><td>결제 내역 · 결제 상세</td></tr>
                        <tr><td class="py-2 font-bold">결제 대기 만료</td><td class="font-mono text-xs">portone:expire-pending · ExpirePendingPayment</td><td>결제 내역 → 지금 만료 검사 · 스케줄러(10분마다)</td></tr>
                        <tr><td class="py-2 font-bold">만료 건 정리</td><td class="font-mono text-xs">model:prune --model=Payment (MassPrunable)</td><td>스케줄러(매일)</td></tr>
                        <tr><td class="py-2 font-bold">만료 건 복구</td><td class="font-mono text-xs">completePayment() on EXPIRED</td><td>만료된 결제 상세 → 결제 확인</td></tr>
                        <tr><td class="py-2 font-bold">웹훅</td><td class="font-mono text-xs">POST {{ $webhookPath }} · WebhookReceived</td><td>웹훅 로그 (ngrok 필요)</td></tr>
                        <tr><td class="py-2 font-bold">체크아웃 스텁</td><td class="font-mono text-xs">portone.checkout · prepare · complete</td><td>상품 상세 → 결제</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card" data-workbench-background>
            <h2 class="text-lg font-black text-slate-800">⚙️ 백그라운드 작업 (큐 · 스케줄러)</h2>
            <p class="mt-1 text-sm text-slate-500">
                실서비스처럼 별도 터미널에서 띄워 두세요. 샘플은 결제 대기 {{ $pendingExpireAfter }}분 뒤 만료 검사를 해요(실서비스 기본값 1440분).
            </p>
            <ul class="mt-4 space-y-2 text-sm">
                <li><code class="font-mono">vendor/bin/testbench queue:work</code> 만료 확인 잡, 웹훅 → 결제 확정 잡 처리</li>
                <li><code class="font-mono">vendor/bin/testbench schedule:work</code> 10분마다 <code class="font-mono">portone:expire-pending</code>, 매일 <code class="font-mono">model:prune</code></li>
                <li><code class="font-mono">vendor/bin/testbench schedule:list</code> 등록된 스케줄 확인</li>
            </ul>
            <p class="mt-4 text-sm text-slate-500">
                <code class="font-mono">Transaction.Paid</code> 웹훅이 오면 앱의 리스너가 큐 잡으로 <code class="font-mono">completePayment()</code>를 불러요.
                패키지는 웹훅 처리 중 레코드를 바꾸지 않으므로, 이 연결은 앱 코드(<code class="font-mono">workbench/app/Listeners/QueuePaymentCompletion.php</code>)입니다.
            </p>
        </section>

        <section class="card">
            <h2 class="text-lg font-black text-slate-800">📦 내 앱에 설치할 때</h2>
            <ul class="mt-4 space-y-2 text-sm">
                <li><code class="font-mono">php artisan vendor:publish --tag=portone-config</code> 설정 파일 <span class="text-slate-500">(선택)</span></li>
                <li><code class="font-mono">php artisan vendor:publish --tag=portone-migrations</code> 마이그레이션 → <code class="font-mono">php artisan migrate</code></li>
                <li><code class="font-mono">php artisan vendor:publish --tag=portone-stubs</code> 체크아웃 스텁 → <code class="font-mono">routes/portone.php</code>를 라우트에 포함하고 <code class="font-mono">product()</code>를 상품 조회로 바꾸기</li>
                <li>사용자 모델에 <code class="font-mono">use Billable;</code></li>
                <li>스케줄러에 <code class="font-mono">portone:expire-pending</code>과 <code class="font-mono">model:prune</code>을 등록하고 큐 워커 실행</li>
            </ul>
        </section>

        <section class="card">
            <h2 class="text-lg font-black text-slate-800">🧪 테스트</h2>
            <ul class="mt-4 space-y-2 text-sm">
                <li><code class="font-mono">composer test</code> 오프라인 테스트</li>
                <li><code class="font-mono">composer live</code> 실제 포트원에 붙는 live 테스트 (브라우저 결제창을 직접 조작)</li>
                <li><code class="font-mono">composer build</code> 에셋(Vite + Tailwind)과 SQLite DB를 다시 만들기</li>
            </ul>
            <p class="mt-4 text-sm text-slate-500">자세한 내용은 <code class="font-mono">docs/TESTING.md</code>를 보세요.</p>
        </section>
    </div>
@endsection

@section('code')
    @include('workbench::partials.code', [
        'file' => 'workbench/routes/web.php',
        'note' => '안내 페이지, 샘플 상점 라우트, 체크아웃 스텁(발행본 또는 워크벤치 사본) 포함',
        'code' => "Route::middleware('auth')->prefix('shop')->name('shop.')->group(...);",
    ])
    @include('workbench::partials.code', [
        'file' => 'workbench/app/Http/Controllers/PortOneCheckoutController.php',
        'note' => '발행된 스텁과 다른 곳은 namespace와 product() 본문뿐입니다.',
        'code' => "return Product::findOrFail(\$request->input('product'));",
    ])
    @include('workbench::partials.code', [
        'file' => 'workbench/app/Http/Controllers/PaymentController.php',
        'code' => "\$request->user()->payments()->get();\n\$request->user()->refund(\$paymentId, \$reason, \$amount);",
    ])
@endsection
