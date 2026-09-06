@extends('layouts.app')

@section('content')
<div class="mb-10 flex flex-col justify-between gap-6 md:flex-row md:items-end">
    <div><p class="mb-3 text-xs font-bold uppercase tracking-[0.2em] text-[#8b7355]">Admin workspace</p><h1 class="font-display text-4xl font-bold leading-[0.95] tracking-[-0.04em] sm:text-6xl">Rawat katalog<br><span class="text-[#543019]">tetap hidup.</span></h1><p class="mt-5 max-w-xl text-sm leading-6 text-[#765c47]">Import data terbaru, lengkapi foto SKU, dan cari produk dengan gambar dari satu tempat.</p></div>
    <div class="rounded-2xl bg-[#543019] p-5 text-white md:w-64"><p class="text-xs font-bold uppercase tracking-[0.16em] text-[#fff200]">Kelengkapan foto</p><p class="mt-2 font-display text-4xl font-bold">{{ $totalProducts ? number_format(($productsWithPhotos / $totalProducts) * 100, 0) : 0 }}%</p><p class="mt-1 text-xs text-[#f8e9c2]">{{ $productsWithPhotos }} dari {{ $totalProducts }} produk</p></div>
</div>

<div class="mb-10 grid gap-4 {{ auth()->user()->isSuperAdmin() ? 'xl:grid-cols-2 lg:grid-cols-2' : 'lg:grid-cols-1' }}">
    <form action="{{ route('admin.products.store') }}" method="POST" class="rounded-2xl border border-[#eadfca] bg-[#fffdf4] p-6">
        @csrf
        <div class="flex items-start justify-between"><div><p class="text-xs font-bold uppercase tracking-[0.16em] text-[#8b7355]">01 · New item</p><h2 class="mt-2 font-display text-2xl font-bold">Tambah item satuan</h2></div><span class="rounded-xl bg-[#fff200] px-3 py-2 text-xl text-[#543019]">+</span></div>
        <div class="mt-5 space-y-4"><label class="block"><span class="text-sm font-bold">SKU</span><input name="sku" value="{{ old('sku') }}" required class="mt-2 w-full rounded-xl border border-[#ead9b8] bg-white px-4 py-3 font-mono text-sm outline-none focus:border-[#ffc20e]"></label><label class="block"><span class="text-sm font-bold">Deskripsi</span><textarea name="description" rows="3" class="mt-2 w-full resize-none rounded-xl border border-[#ead9b8] bg-white px-4 py-3 text-sm outline-none focus:border-[#ffc20e]">{{ old('description') }}</textarea></label></div>
        <button class="mt-4 rounded-xl bg-[#543019] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#6b4025]">Simpan item →</button>
    </form>
    @if (auth()->user()->isSuperAdmin())
    <form action="{{ route('admin.import') }}" method="POST" enctype="multipart/form-data" class="rounded-2xl border border-[#eadfca] bg-[#fffdf4] p-6">
        @csrf
        <div class="flex items-start justify-between"><div><p class="text-xs font-bold uppercase tracking-[0.16em] text-[#8b7355]">02 · Catalog data</p><h2 class="mt-2 font-display text-2xl font-bold">Import Excel terbaru</h2></div><span class="rounded-xl bg-[#fff200] px-3 py-2 text-xl text-[#543019]">↑</span></div>
        <p class="mt-3 text-sm leading-6 text-[#765c47]">Gunakan kolom <span class="font-mono text-[#8b5e00]">sku</span> dan <span class="font-mono text-[#8b5e00]">desc</span>. SKU yang sama tidak dibuat ulang; hanya deskripsi yang berubah akan diperbarui.</p>
        <label class="mt-5 flex cursor-pointer items-center justify-between rounded-xl border border-dashed border-[#d8bd68] bg-[#fff8d6] px-4 py-4 transition hover:border-[#ffc20e]"><span id="excel-file-name" class="text-sm font-bold text-[#765c47]">Pilih file Excel</span><span class="text-xs font-bold text-[#8b5e00]">XLSX / CSV</span><input type="file" name="file" accept=".xlsx,.xls,.csv" class="sr-only" required onchange="document.getElementById('excel-file-name').textContent = this.files[0]?.name || 'Pilih file Excel'"></label>
        <button class="mt-4 rounded-xl bg-[#543019] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#6b4025]">Import data →</button>
    </form>
    @endif
</div>

<section id="without-photo">
    <div class="mb-5 flex flex-col justify-between gap-4 sm:flex-row sm:items-end"><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#8b7355]">03 · Product imagery</p><h2 class="mt-1 font-display text-3xl font-bold tracking-tight">SKU belum ada foto</h2></div><form method="GET" action="{{ route('admin.index') }}" class="flex w-full max-w-sm items-center rounded-xl border border-[#ead9b8] bg-[#fffdf4] px-3 focus-within:border-[#ffc20e]"><span class="text-lg text-[#8b7355]">⌕</span><input type="search" name="q" value="{{ $search }}" placeholder="Cari SKU atau deskripsi..." class="w-full border-0 bg-transparent px-3 py-3 text-sm outline-none placeholder:text-[#a38f78]"><button class="text-xs font-bold text-[#8b5e00]">Cari</button></form></div>
    @if ($productsWithoutPhotos->isEmpty())
        <div class="rounded-2xl border border-dashed border-[#d8bd68] bg-[#fff8d6] px-6 py-16 text-center"><div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-[#fff200] text-2xl text-[#543019]">✓</div><h3 class="font-display text-2xl font-bold">Semua produk sudah difoto</h3><p class="mt-2 text-sm text-[#765c47]">Katalog visual siap digunakan untuk pencarian.</p></div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">@foreach ($productsWithoutPhotos as $product)<a href="{{ route('admin.products.show', $product) }}" class="group flex min-h-[170px] flex-col justify-between rounded-2xl border border-[#eadfca] bg-[#fffdf4] p-5 transition duration-300 hover:-translate-y-1 hover:border-[#ffc20e] hover:shadow-[5px_5px_0_#fff200]"><div class="flex items-start justify-between"><span class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#f5e6bd] text-xl text-[#8b7355]">▧</span><span class="rounded-full bg-[#fff8d6] px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-[#8b5e00]">Belum ada foto</span></div><div class="mt-6"><p class="font-mono text-sm font-bold tracking-wide text-[#8b5e00]">{{ $product->sku }}</p><h3 class="mt-1 line-clamp-2 text-base font-bold text-[#543019]">{{ $product->description ?: 'Deskripsi belum tersedia' }}</h3><p class="mt-3 text-xs font-semibold text-[#8b7355] transition group-hover:text-[#8b5e00]">Upload foto <span class="ml-1">→</span></p></div></a>@endforeach</div>
        <div class="mt-8">{{ $productsWithoutPhotos->links() }}</div>
    @endif
</section>
@endsection
