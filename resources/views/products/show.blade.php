@extends('layouts.app')

@section('content')
<div class="mb-8 flex items-center gap-3 text-sm font-semibold text-[#765c47]"><a href="{{ request()->routeIs('admin.*') ? route('admin.index') : route('products.index') }}" class="transition hover:text-[#8b5e00]">← {{ request()->routeIs('admin.*') ? 'Admin' : 'Katalog' }}</a><span>/</span><span class="font-mono text-[#543019]">{{ $product->sku }}</span></div>
<div class="grid gap-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(360px,0.9fr)]">
    <section class="overflow-hidden rounded-3xl border border-[#eadfca] bg-[#fffdf4]">
        <div class="flex min-h-[420px] items-center justify-center bg-[#f5e6bd] p-6 sm:min-h-[560px]">
            @if ($product->photo)
                <img src="{{ Storage::disk(config('filesystems.product'))->url($product->photo) }}" alt="{{ $product->description }}" class="max-h-[510px] w-full rounded-2xl object-contain shadow-xl">
            @else
                <div class="text-center text-[#8b7355]"><div class="mx-auto flex h-24 w-24 items-center justify-center rounded-3xl border-2 border-dashed border-[#d8bd68] text-4xl">▧</div><p class="mt-5 text-sm font-semibold">Belum ada foto untuk SKU ini</p></div>
            @endif
        </div>
        <div class="flex items-center justify-between border-t border-[#eadfca] px-5 py-4 text-xs font-semibold text-[#765c47]"><span>Product image</span><span>{{ $product->photo ? 'Embedding siap dicari' : 'Menunggu upload' }}</span></div>
    </section>
    <section class="flex flex-col justify-center">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#8b7355]">Product detail</p>
        <h1 class="mt-3 font-mono text-4xl font-bold tracking-tight text-[#8b5e00]">{{ $product->sku }}</h1>
        <p class="mt-4 text-xl font-semibold leading-8 text-[#543019]">{{ $product->description ?: 'Deskripsi belum tersedia' }}</p>
        <div class="my-8 h-px bg-[#eadfca]"></div>
        @if (! request()->routeIs('admin.*'))
            <div class="rounded-2xl border border-[#eadfca] bg-[#fff8d6] p-5 text-sm leading-6 text-[#765c47]">Foto produk dikelola oleh administrator katalog.</div>
        @else
        <form action="{{ route('admin.products.photo.store', $product) }}" method="POST" enctype="multipart/form-data" class="rounded-2xl bg-[#543019] p-5 text-white">
            @csrf
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-[#fff200]">{{ $product->photo ? 'Ganti foto produk' : 'Lengkapi produk ini' }}</p>
            <p class="mt-2 text-sm leading-6 text-[#f8e9c2]">Gunakan foto yang terang dan fokus. Sistem akan membuat sidik visual untuk pencarian berikutnya.</p>
            <label class="mt-5 block cursor-pointer rounded-xl border border-dashed border-[#8b5e00] bg-[#6b4025] p-5 text-center transition hover:border-[#fff200]">
                <span class="block text-sm font-bold">Pilih foto dari perangkat</span><span class="mt-1 block text-xs text-[#f8e9c2]">JPG, PNG, atau WEBP · maksimal 10 MB</span>
                <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="sr-only" required data-image-preview data-preview-target="product-upload-preview">
            </label>
            <div id="product-upload-preview" hidden class="mt-4 rounded-2xl border border-[#8b5e00] bg-[#6b4025] p-3"><div class="flex items-center gap-3"><img data-preview-image alt="Preview foto produk" class="h-20 w-20 rounded-xl object-cover"><div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-wider text-[#fff200]">Preview foto SKU</p><p data-preview-name class="mt-1 truncate text-xs font-semibold text-[#f8e9c2]"></p></div></div><button data-preview-submit type="submit" hidden class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-[#fff200] px-4 py-3 text-sm font-bold text-[#543019] transition hover:bg-[#ffc20e]">Konfirmasi & simpan foto <span>→</span></button></div>
        </form>
        @endif
    </section>
</div>
@endsection
