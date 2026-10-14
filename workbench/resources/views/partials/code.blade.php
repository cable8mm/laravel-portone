{{-- One file of the "이 화면의 코드" panel: where it lives and the call that matters. --}}
<section class="px-6 py-4">
    <p class="font-mono text-xs text-mint-100">{{ $file }}</p>
    @isset($note)
        <p class="mt-1 text-sm text-slate-400">{{ $note }}</p>
    @endisset
    <pre class="mt-2 overflow-x-auto rounded-xl bg-black/40 p-3 text-xs leading-relaxed text-slate-200"><code>{{ $code }}</code></pre>
</section>
