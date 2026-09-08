@extends('layouts.app')

@section('content')
<div class="mb-8 flex flex-col justify-between gap-5 sm:flex-row sm:items-end"><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-[#8b7355]">Super admin</p><h1 class="mt-2 font-display text-4xl font-bold tracking-tight">Edit user admin</h1><p class="mt-3 text-sm text-[#765c47]">Perbaiki data user. Kosongkan password jika tidak ingin mengubahnya.</p></div><a href="{{ route('admin.users.index') }}" class="text-sm font-bold text-[#8b5e00]">← Kembali ke user admin</a></div>
<div class="max-w-2xl rounded-2xl border border-[#eadfca] bg-[#fffdf4] p-6 sm:p-8">
    <div class="mb-6 flex items-center gap-4"><div class="flex h-12 w-12 items-center justify-center rounded-full bg-[#fff200] font-display text-xl font-bold text-[#543019]">{{ strtoupper(substr($user->name, 0, 1)) }}</div><div><p class="font-bold text-[#543019]">{{ $user->name }}</p><p class="text-xs text-[#765c47]">{{ $user->email }}</p></div></div>
    <form action="{{ route('admin.users.update', $user) }}" method="POST" class="space-y-5">
        @csrf
        @method('PUT')
        <label class="block"><span class="text-sm font-bold">Nama</span><input name="name" value="{{ old('name', $user->name) }}" required class="mt-2 w-full rounded-xl border border-[#ead9b8] bg-white px-4 py-3 text-sm outline-none focus:border-[#ffc20e]"></label>
        <label class="block"><span class="text-sm font-bold">Email</span><input type="email" name="email" value="{{ old('email', $user->email) }}" required class="mt-2 w-full rounded-xl border border-[#ead9b8] bg-white px-4 py-3 text-sm outline-none focus:border-[#ffc20e]"></label>
        <label class="block"><span class="text-sm font-bold">Role</span><select name="role" class="mt-2 w-full rounded-xl border border-[#ead9b8] bg-white px-4 py-3 text-sm outline-none focus:border-[#ffc20e]"><option value="admin" @selected(old('role', $user->role) === 'admin')>Admin biasa</option><option value="super_admin" @selected(old('role', $user->role) === 'super_admin')>Super admin</option></select></label>
        <div class="rounded-xl bg-[#fff8d6] p-4"><label class="block"><span class="text-sm font-bold text-[#543019]">Password baru <span class="font-normal text-[#765c47]">(opsional)</span></span><input type="password" name="password" class="mt-2 w-full rounded-xl border border-[#ead9b8] bg-white px-4 py-3 text-sm outline-none focus:border-[#ffc20e]"></label><label class="mt-4 block"><span class="text-sm font-bold text-[#543019]">Konfirmasi password</span><input type="password" name="password_confirmation" class="mt-2 w-full rounded-xl border border-[#ead9b8] bg-white px-4 py-3 text-sm outline-none focus:border-[#ffc20e]"></label></div>
        <div class="flex flex-wrap gap-3"><button class="rounded-xl bg-[#543019] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#6b4025]">Simpan perubahan →</button><a href="{{ route('admin.users.index') }}" class="rounded-xl border border-[#d8bd68] px-5 py-3 text-sm font-bold text-[#8b5e00]">Batal</a></div>
    </form>
</div>
@endsection
