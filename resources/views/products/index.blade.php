@extends('layouts.app')

@section('content')
<div class="mb-10 flex flex-col justify-between gap-6 md:flex-row md:items-end">
    <div>
        <p class="mb-3 text-xs font-bold uppercase tracking-[0.2em] text-[#8b7355]">Barcode finder</p>
        <h1 class="font-display max-w-2xl text-4xl font-bold leading-[0.95] tracking-[-0.04em] sm:text-6xl">Kenali barang.<br><span class="text-[#543019]">Temukan SKU.</span></h1>
        <p class="mt-5 max-w-xl text-sm leading-6 text-[#765c47]">Barcode Finder perusahaan untuk menemukan informasi produk dengan cepat.</p>
    </div>
</div>

<div class="mb-7">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#8b7355]">All products</p><h2 class="mt-1 font-display text-3xl font-bold tracking-tight">Katalog produk</h2>
    </div>
    <div class="mt-4 rounded-2xl p-3 sm:p-4">
        <div id="home-search-preview" hidden class="mb-3 rounded-xl border border-[#ffc20e] bg-[#fff8d6] p-3">
            <div class="flex items-center gap-4">
                <img data-preview-image alt="Preview foto pencarian" class="h-24 w-24 rounded-xl object-cover sm:h-28 sm:w-28">
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-[#8b5e00]">Preview pencarian</p>
                    <p data-preview-name class="mt-1 truncate text-xs font-semibold text-[#765c47] sm:text-sm">
                        </p>
                    </div>
                </div>
                <button id="home-search-submit" data-preview-submit type="submit" form="home-image-search-form" hidden class="mt-3 w-full rounded-xl bg-[#543019] px-4 py-2.5 text-xs font-bold text-white">Konfirmasi & cari →</button>
            </div>
        <div class="grid gap-3 sm:grid-cols-[minmax(190px,0.8fr)_minmax(280px,1.2fr)]">
            <form
                id="home-image-search-form"
                action="{{ route('products.search') }}"
                method="POST"
                enctype="multipart/form-data"
                class="relative"
            >
                @csrf

                <button
                    type="button"
                    data-photo-picker
                    data-photo-menu="search-photo-menu"
                    class="flex h-full min-h-12 w-full cursor-pointer items-center justify-between gap-2 rounded-xl bg-[#fff200] px-5 py-3 text-sm font-bold text-[#543019] shadow-[4px_4px_0_#543019] transition hover:bg-[#ffc20e]"
                >
                    <span>Pilih foto pencarian</span>
                    <span>↗</span>
                </button>

                <div
                    id="search-photo-menu"
                    hidden
                    class="absolute left-0 top-full z-50 mt-3 w-full overflow-hidden rounded-xl border-2 border-[#543019] bg-white shadow-[4px_4px_0_#543019]"
                >
                    <label
                        for="search-camera"
                        class="flex cursor-pointer items-center gap-3 border-b border-[#543019]/20 px-4 py-4 text-[#543019] transition hover:bg-[#fff8cc]"
                    >
                        <span class="text-xl">📷</span>

                        <div>
                            <p class="text-sm font-bold">Ambil Foto</p>
                            <p class="text-xs opacity-70">Gunakan kamera HP</p>
                        </div>
                    </label>

                    <label
                        for="search-gallery"
                        class="flex cursor-pointer items-center gap-3 px-4 py-4 text-[#543019] transition hover:bg-[#fff8cc]"
                    >
                        <span class="text-xl">🖼️</span>

                        <div>
                            <p class="text-sm font-bold">Pilih dari Galeri</p>
                            <p class="text-xs opacity-70">Pilih foto yang sudah ada</p>
                        </div>
                    </label>
                </div>

                <input
                    id="search-camera"
                    type="file"
                    accept="image/*"
                    capture="environment"
                    class="sr-only"
                    data-photo-source
                    data-photo-target="search-image"
                    data-photo-mode="replace"
                >

                <input
                    id="search-gallery"
                    type="file"
                    accept="image/*"
                    class="sr-only"
                    data-photo-source
                    data-photo-target="search-image"
                    data-photo-mode="replace"
                >

                <input
                    id="search-image"
                    type="file"
                    name="image"
                    accept="image/*"
                    class="sr-only"
                    required
                    data-image-preview
                    data-preview-target="home-search-preview"
                    data-submit-target="home-search-submit"
                >
            </form>
            <form method="GET" action="{{ route('products.index') }}" class="flex min-h-12 items-center rounded-xl border border-[#ead9b8] bg-white px-3 focus-within:border-[#ffc20e]">
                <span class="text-lg text-[#8b7355]">⌕</span><input type="search" name="q" value="{{ $search }}" placeholder="Cari SKU atau deskripsi..." class="w-full border-0 bg-transparent px-3 py-3 text-sm outline-none placeholder:text-[#a38f78]"><button class="text-xs font-bold text-[#8b5e00]">Cari</button>
            </form>
        </div>
    </div>
</div>

@if ($products->isEmpty())
    <div class="rounded-2xl border border-dashed border-[#d8bd68] bg-[#fffdf4] px-6 py-16 text-center"><div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-[#fff200] text-2xl text-[#543019]">⌕</div><h2 class="mt-4 font-display text-2xl font-bold">Produk belum tersedia</h2><p class="mt-2 text-sm text-[#765c47]">Admin dapat mengisi katalog melalui import Excel.</p></div>
@else
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @foreach ($products as $product)
            <a href="{{ route('products.show', $product) }}" class="group overflow-hidden rounded-2xl border border-[#eadfca] bg-[#fffdf4] transition duration-300 hover:-translate-y-1 hover:border-[#ffc20e] hover:shadow-[5px_5px_0_#fff200]">
                <div class="aspect-square overflow-hidden bg-[#f5e6bd]">
                    @if ($product->photos->first())
                        <img src="{{ asset('storage/'.$product->photos->first()->path) }}" alt="{{ $product->description }}" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                    @else
                        <div class="flex h-full flex-col items-center justify-center text-[#8b7355]"><span class="text-4xl">▧</span><span class="mt-3 text-xs font-bold uppercase tracking-wider">Foto belum tersedia</span></div>
                    @endif
                </div>
                <div class="p-4"><p class="font-mono text-sm font-bold text-[#8b5e00]">{{ $product->sku }}</p><p class="mt-2 line-clamp-2 text-sm font-semibold leading-5 text-[#543019]">{{ $product->description ?: 'Deskripsi belum tersedia' }}</p></div>
            </a>
        @endforeach
    </div>
    <div class="mt-8">{{ $products->links() }}</div>
@endif
@endsection
