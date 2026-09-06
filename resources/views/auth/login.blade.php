<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ asset('favicon_io/favicon.ico') }}">
    <link rel="apple-touch-icon" sizes="192x192" href="{{ asset('favicon_io/android-chrome-192x192.png') }}">
    <link rel="manifest" href="{{ asset('favicon_io/site.webmanifest') }}">
    <title>Login admin · Lensku</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-[#fff8d6] px-5 text-[#543019]">
    <main class="grid w-full max-w-4xl overflow-hidden rounded-3xl border border-[#ead9b8] bg-[#fffdf4] shadow-[8px_8px_0_#fff200] md:grid-cols-[0.9fr_1.1fr]">
        <section class="hidden bg-[#543019] p-10 text-white md:flex md:flex-col md:justify-between"><div><a href="{{ route('products.index') }}" class="font-display text-2xl font-bold">lensku</a><p class="mt-20 max-w-xs font-display text-4xl font-bold leading-tight">Rawat katalog. Temukan barang.</p></div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#fff200]">Admin access / visual catalog</p></section>
        <section class="p-7 sm:p-10"><a href="{{ route('products.index') }}" class="font-display text-2xl font-bold md:hidden">lensku</a><p class="mt-8 text-xs font-bold uppercase tracking-[0.2em] text-[#8b7355] md:mt-0">Admin access</p><h1 class="mt-3 font-display text-4xl font-bold tracking-tight">Selamat datang.</h1><p class="mt-3 text-sm leading-6 text-[#765c47]">Masuk untuk mengelola foto produk dan katalog SKU.</p>
            @if ($errors->any())<div class="mt-6 rounded-xl border border-[#ed1c24] bg-[#fff0eb] px-4 py-3 text-sm text-[#8d1f24]">{{ $errors->first() }}</div>@endif
            <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5">@csrf<label class="block"><span class="text-sm font-bold">Email</span><input type="email" name="email" value="{{ old('email') }}" required autofocus class="mt-2 w-full rounded-xl border border-[#ead9b8] bg-white px-4 py-3 text-sm outline-none focus:border-[#ffc20e]"></label><label class="block"><span class="text-sm font-bold">Password</span><input type="password" name="password" required class="mt-2 w-full rounded-xl border border-[#ead9b8] bg-white px-4 py-3 text-sm outline-none focus:border-[#ffc20e]"></label><label class="flex items-center gap-2 text-sm text-[#765c47]"><input type="checkbox" name="remember" value="1" class="accent-[#ffc20e]"> Ingat saya</label><button class="w-full rounded-xl bg-[#543019] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#6b4025]">Masuk ke admin →</button></form>
        </section>
    </main>
</body>
</html>
