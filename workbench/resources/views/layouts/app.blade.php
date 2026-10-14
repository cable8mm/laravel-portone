<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '샘플 상점') · laravel-portone Workbench</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-candy-50 bg-[radial-gradient(var(--color-candy-100)_1.5px,transparent_1.5px)] bg-size-[22px_22px] font-sans text-slate-700 antialiased">
    <header class="sticky top-0 z-10 border-b-2 border-candy-100 bg-white/85 backdrop-blur">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-3 px-4 py-3">
            <a href="{{ auth()->check() ? route('shop.products.index') : url('/') }}" class="flex items-center gap-2 text-lg font-black text-candy-600">
                <span class="grid size-9 place-items-center rounded-2xl bg-candy-100 text-xl">🍓</span>
                포트원 샘플 상점
            </a>

            @auth
                <nav class="flex flex-wrap gap-1 text-sm font-bold">
                    <a href="{{ route('shop.products.index') }}" @class(['rounded-full px-3 py-1.5 hover:bg-candy-50', 'bg-candy-100 text-candy-600' => request()->routeIs('shop.products.*', 'portone.*')])>상점</a>
                    <a href="{{ route('shop.payments.index') }}" @class(['rounded-full px-3 py-1.5 hover:bg-candy-50', 'bg-candy-100 text-candy-600' => request()->routeIs('shop.payments.*')])>결제 내역</a>
                    <a href="{{ route('shop.customer.show') }}" @class(['rounded-full px-3 py-1.5 hover:bg-candy-50', 'bg-candy-100 text-candy-600' => request()->routeIs('shop.customer.*')])>고객</a>
                    <a href="{{ route('shop.webhooks.index') }}" @class(['rounded-full px-3 py-1.5 hover:bg-candy-50', 'bg-candy-100 text-candy-600' => request()->routeIs('shop.webhooks.*')])>웹훅 로그</a>
                </nav>
            @endauth

            <div class="ml-auto flex items-center gap-2 text-sm">
                <a href="{{ url('/') }}" class="rounded-full px-3 py-1.5 font-bold text-slate-500 hover:bg-slate-100">🛠 개발자 안내</a>
                @auth
                    <span class="chip bg-mint-100 text-mint-500">🙂 {{ auth()->user()->name }}</span>
                    <a href="{{ url('/_workbench/logout') }}" class="text-slate-400 hover:text-candy-600">로그아웃</a>
                @endauth
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-4 py-8">
        @if (session('status'))
            <p class="mb-6 rounded-2xl bg-mint-100 px-4 py-3 font-bold text-mint-500" role="status">✨ {{ session('status') }}</p>
        @endif

        @yield('content')

        @hasSection('code')
            <aside class="mt-10 overflow-hidden rounded-3xl bg-slate-900 text-slate-100 shadow-[0_6px_0_0_var(--color-candy-200)]" data-workbench-code>
                <h2 class="flex items-center gap-2 border-b border-white/10 px-6 py-3 text-sm font-black tracking-wide text-candy-200">🔍 이 화면의 코드</h2>
                <div class="divide-y divide-white/10">
                    @yield('code')
                </div>
            </aside>
        @endif
    </main>
</body>
</html>
