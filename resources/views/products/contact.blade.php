@extends('layouts.app')

@section('content')
<div class="mb-10 max-w-2xl">
    <p class="mb-3 text-xs font-bold uppercase tracking-[0.2em] text-[#8b7355]">Lensku</p>
    <h1 class="font-display text-4xl font-bold tracking-tight sm:text-5xl">Kontak</h1>
    <p class="mt-3 text-sm leading-6 text-[#765c47]">Hubungi pengembang Lensku melalui platform berikut.</p>
</div>

<div class="grid max-w-3xl gap-4 sm:grid-cols-3">
    <a href="https://wa.me/628815695295" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp Lensku: +62 881-5695-295 (membuka tab baru)" class="group flex items-center gap-4 rounded-2xl border border-[#eadfca] bg-[#fffdf4] p-5 transition duration-300 hover:-translate-y-1 hover:border-[#ffc20e] hover:shadow-[5px_5px_0_#fff200] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#8b5e00]">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[#543019] text-white" aria-hidden="true">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 2a8 8 0 1 1-4.1 14.9l-.3-.2-2.9.8.8-2.8-.2-.3A8 8 0 0 1 12 4zm-3.2 3.9c-.2 0-.4 0-.6.2-.2.2-.8.8-.8 2s.8 2.3.9 2.5c.1.2 1.6 2.6 4 3.5.6.3 1.1.4 1.5.5.6.2 1.2.2 1.6.1.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2 0-.1-.2-.2-.4-.3l-2-1c-.2-.1-.4-.1-.6 0l-.9.9c-.2.2-.4.2-.6.1a7.6 7.6 0 0 1-2.2-1.4 8.4 8.4 0 0 1-1.5-1.9c-.2-.3 0-.4.1-.6l.5-.6c.1-.2.2-.4.1-.6L8.3 8.3c-.1-.3-.4-.4-.5-.4z"/></svg>
        </span>
        <span class="min-w-0 flex-1">
            <span class="block text-sm font-bold text-[#543019]">WhatsApp</span>
            <span class="mt-0.5 block truncate text-sm font-semibold text-[#8b5e00]">+62 881-5695-295</span>
        </span>
        <span aria-hidden="true" class="shrink-0 text-lg text-[#8b7355] transition group-hover:translate-x-0.5 group-hover:text-[#543019]">↗</span>
    </a>

    <a href="https://www.instagram.com/satria_rangga_j/" target="_blank" rel="noopener noreferrer" aria-label="Instagram Lensku: @satria_rangga_j (membuka tab baru)" class="group flex items-center gap-4 rounded-2xl border border-[#eadfca] bg-[#fffdf4] p-5 transition duration-300 hover:-translate-y-1 hover:border-[#ffc20e] hover:shadow-[5px_5px_0_#fff200] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#8b5e00]">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[#543019] text-white" aria-hidden="true">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1.2" fill="currentColor" stroke="none"/></svg>
        </span>
        <span class="min-w-0 flex-1">
            <span class="block text-sm font-bold text-[#543019]">Instagram</span>
            <span class="mt-0.5 block truncate text-sm font-semibold text-[#8b5e00]">@satria_rangga_j</span>
        </span>
        <span aria-hidden="true" class="shrink-0 text-lg text-[#8b7355] transition group-hover:translate-x-0.5 group-hover:text-[#543019]">↗</span>
    </a>

    <a href="https://satriarangga.my.id/" target="_blank" rel="noopener noreferrer" aria-label="Website Lensku: satriarangga.my.id (membuka tab baru)" class="group flex items-center gap-4 rounded-2xl border border-[#eadfca] bg-[#fffdf4] p-5 transition duration-300 hover:-translate-y-1 hover:border-[#ffc20e] hover:shadow-[5px_5px_0_#fff200] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#8b5e00]">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[#543019] text-white" aria-hidden="true">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.7 3.8 9S14.5 18.4 12 21c-2.5-2.6-3.8-5.7-3.8-9S9.5 5.6 12 3z"/></svg>
        </span>
        <span class="min-w-0 flex-1">
            <span class="block text-sm font-bold text-[#543019]">Website</span>
            <span class="mt-0.5 block truncate text-sm font-semibold text-[#8b5e00]">satriarangga.my.id</span>
        </span>
        <span aria-hidden="true" class="shrink-0 text-lg text-[#765c47] transition group-hover:translate-x-0.5 group-hover:text-[#543019]">↗</span>
    </a>
</div>
@endsection
