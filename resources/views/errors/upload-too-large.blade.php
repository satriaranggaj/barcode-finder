@extends('layouts.app')
@section('content')
<h1 class="text-2xl font-bold">Foto terlalu besar untuk diunggah</h1>
<p class="mt-4">{{ $message }}</p>
<a href="{{ route('products.index') }}" class="mt-5 inline-block rounded-xl bg-[#543019] px-5 py-3 text-white">Kembali ke katalog</a>
@endsection
