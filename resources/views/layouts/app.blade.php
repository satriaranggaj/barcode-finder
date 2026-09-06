<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('favicon_io/favicon.ico') }}">
    <link rel="apple-touch-icon" sizes="192x192" href="{{ asset('favicon_io/android-chrome-192x192.png') }}">
    <link rel="manifest" href="{{ asset('favicon_io/site.webmanifest') }}">
    <title>{{ $title ?? 'Lensku' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#fff8d6] text-[#543019] antialiased">
    <div class="min-h-screen">
        <header class="sticky top-0 z-20 border-b border-[#ead9b8] bg-[#fffdf4]/95 px-5 py-4 backdrop-blur lg:px-10">
            <div class="mx-auto flex max-w-[1400px] items-center justify-between gap-6">
                <a href="{{ route('products.index') }}" class="flex shrink-0 items-center gap-3">
                    <img src="{{ asset('favicon_io/android-chrome-192x192.png') }}" alt="Lensku" class="h-10 w-10 rounded-xl object-cover">
                    <span><span class="block font-display text-xl font-bold tracking-tight">lensku</span><span class="hidden text-[10px] font-semibold uppercase tracking-[0.2em] text-[#8b7355] sm:block">Barcode finder</span></span>
                </a>
                <nav class="hidden items-center gap-2 text-sm font-semibold md:flex">
                    <a href="{{ route('products.index') }}" class="rounded-xl px-4 py-2.5 transition {{ request()->routeIs('products.index') ? 'bg-[#543019] text-white' : 'text-[#765c47] hover:bg-[#fff8d6]' }}">Katalog</a>
                    @auth
                        <a href="{{ route('admin.index') }}" class="rounded-xl px-4 py-2.5 transition {{ request()->routeIs('admin.index') ? 'bg-[#543019] text-white' : 'text-[#765c47] hover:bg-[#fff8d6]' }}">Admin workspace</a>
                        @if (auth()->user()->isSuperAdmin())
                            <a href="{{ route('admin.users.index') }}" class="rounded-xl px-4 py-2.5 transition {{ request()->routeIs('admin.users.index') ? 'bg-[#543019] text-white' : 'text-[#765c47] hover:bg-[#fff8d6]' }}">User admin</a>
                        @endif
                    @endauth
                </nav>
                <div class="hidden items-center gap-4 text-xs font-semibold text-[#765c47] md:flex"><span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-[#ffc20e]"></span> AI ready</span>@auth<div class="flex items-center gap-3"><span>{{ auth()->user()->name }}</span><form method="POST" action="{{ route('logout') }}">@csrf<button class="font-bold text-[#ed1c24]">Keluar</button></form></div>@else<a href="{{ route('login') }}" class="font-bold text-[#8b5e00]">Login</a>@endauth</div>
                <button type="button" data-nav-toggle aria-expanded="false" aria-controls="mobile-navigation" class="flex h-10 w-10 items-center justify-center rounded-xl border border-[#ead9b8] text-lg text-[#543019] md:hidden">☰</button>
            </div>
            <nav id="mobile-navigation" hidden class="mx-auto mt-4 max-w-[1400px] border-t border-[#eadfca] pt-4 md:hidden">
                <div class="flex flex-col gap-1 text-sm font-semibold">
                    <a href="{{ route('products.index') }}" class="rounded-xl px-4 py-3 {{ request()->routeIs('products.index') ? 'bg-[#543019] text-white' : 'text-[#765c47]' }}">Katalog</a>
                    @auth
                        <a href="{{ route('admin.index') }}" class="rounded-xl px-4 py-3 {{ request()->routeIs('admin.index') ? 'bg-[#543019] text-white' : 'text-[#765c47]' }}">Admin workspace</a>
                        @if (auth()->user()->isSuperAdmin())<a href="{{ route('admin.users.index') }}" class="rounded-xl px-4 py-3 {{ request()->routeIs('admin.users.index') ? 'bg-[#543019] text-white' : 'text-[#765c47]' }}">User admin</a>@endif
                        <div class="mt-2 flex items-center justify-between border-t border-[#eadfca] px-4 pt-3 text-xs text-[#765c47]"><span>{{ auth()->user()->name }}</span><form method="POST" action="{{ route('logout') }}">@csrf<button class="font-bold text-[#ed1c24]">Keluar</button></form></div>
                    @else
                        <a href="{{ route('login') }}" class="rounded-xl px-4 py-3 font-bold text-[#8b5e00]">Login admin</a>
                    @endauth
                </div>
            </nav>
        </header>

        <main class="min-w-0">
            <div class="mx-auto max-w-[1400px] px-5 py-8 lg:px-10 lg:py-12">
                @if (session('success'))
                    <div class="mb-6 flex items-center gap-3 rounded-xl border border-[#ffc20e] bg-[#fff8d6] px-4 py-3 text-sm font-semibold text-[#543019]">✓ {{ session('success') }}</div>
                @endif
                @if ($errors->any())
                    <div class="mb-6 rounded-xl border border-[#ed1c24] bg-[#fff0eb] px-4 py-3 text-sm text-[#8d1f24]">{{ $errors->first() }}</div>
                @endif
                @yield('content')
            </div>
        </main>
    </div>
</body>
</html>
