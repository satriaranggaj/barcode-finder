@extends('layouts.app')
@section('content')
<h1 class="text-2xl font-bold">Edit area barang — {{ $photo->product->sku }}</h1>
<p class="my-3">Foto katalog tetap utuh. Area ini digunakan untuk referensi pencarian.</p>
<form method="POST" action="{{ route('admin.photos.selection.update', $photo) }}">
    @csrf
    @method('PUT')
    <x-object-selection :image="route('admin.photos.selection.image', $photo)" :crop="$photo->crop" :source="$photo->selection_source" />
    <button class="mt-4 rounded-xl bg-[#543019] px-5 py-3 text-white">Simpan area</button>
    <a class="ml-3" href="{{ route('admin.products.show', $photo->product) }}">Kembali</a>
</form>
@endsection
