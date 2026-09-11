@extends('layouts.app')

@section('content')
<div class="mb-8 flex items-center gap-3 text-sm font-semibold text-[#765c47]"><a href="{{ request()->routeIs('admin.*') ? route('admin.index') : route('products.index') }}" class="transition hover:text-[#8b5e00]">← {{ request()->routeIs('admin.*') ? 'Admin' : 'Katalog' }}</a><span>/</span><span class="text-[#543019]">Hasil pencarian visual</span></div>
<div class="mb-10"><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#8b7355]">Visual search</p><h1 class="mt-2 font-display text-4xl font-bold tracking-tight sm:text-5xl">Barang yang paling mirip</h1><p class="mt-3 max-w-xl text-sm leading-6 text-[#765c47]">Hasil diurutkan berdasarkan kedekatan visual dengan foto yang kamu upload.</p></div>
@if ($error)
    <div class="rounded-2xl border border-[#ed1c24] bg-[#fff0eb] px-6 py-10 text-center"><p class="font-display text-2xl font-bold text-[#543019]">Pencarian sedang beristirahat</p><p class="mt-2 text-sm text-[#8d1f24]">{{ $error }}</p><a href="{{ route('products.index') }}" class="mt-5 inline-flex rounded-xl bg-[#543019] px-5 py-3 text-sm font-bold text-white">Kembali ke katalog</a></div>
@elseif ($results->isEmpty())
    <div class="rounded-2xl border border-dashed border-[#d8bd68] bg-[#fffdf4] px-6 py-16 text-center"><div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-[#fff200] text-2xl text-[#543019]">⌕</div><h2 class="mt-4 font-display text-2xl font-bold text-[#543019]">Tidak ditemukan barang yang cukup mirip</h2><p class="mt-2 text-sm text-[#765c47]">Coba foto dari sudut yang lebih dekat, terang, dan fokus pada barang.</p></div>
@else
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @foreach ($results as $product)
            <a href="{{ request()->routeIs('admin.*') ? route('admin.products.show', $product) : route('products.show', $product) }}" class="group overflow-hidden rounded-2xl border border-[#eadfca] bg-[#fffdf4] transition duration-300 hover:-translate-y-1 hover:border-[#ffc20e] hover:shadow-[5px_5px_0_#fff200]">
                <div class="aspect-square overflow-hidden bg-[#f5e6bd]"><img src="{{ asset('storage/'.$product->photo) }}" alt="{{ $product->description }}" class="h-full w-full object-cover transition duration-500 group-hover:scale-105"></div>
                <div class="p-4"><div class="flex items-center justify-between gap-3"><p class="font-mono text-sm font-bold text-[#8b5e00]">{{ $product->sku }}</p><span title="Skor perbandingan visual, bukan persentase keyakinan AI" class="rounded-full bg-[#fff8d6] px-2 py-1 text-[10px] font-bold text-[#8b5e00]">Skor {{ number_format((float) ($product->final_score ?? $product->similarity), 2, ',', '.') }}</span></div><p class="mt-2 line-clamp-2 text-sm font-semibold leading-5 text-[#543019]">{{ $product->description ?: 'Deskripsi belum tersedia' }}</p></div>
            </a>
        @endforeach
    </div>
@endif
@endsection
