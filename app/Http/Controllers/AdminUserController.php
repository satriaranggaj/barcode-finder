<?php

namespace App\Http\Controllers;

use App\Exports\UserTemplateExport;
use App\Imports\UsersImport;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminUserController extends Controller
{
    //
    public function index(): View
    {
        return view('admin.users.index', [
            'users' => User::query()->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'in:admin,super_admin'],
        ]);

        User::create($validated);

        return to_route('admin.users.index')->with('success', 'User admin berhasil ditambahkan.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'in:admin,super_admin'],
        ]);

        $attributes = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
        ];

        if ($validated['password'] ?? null) {
            $attributes['password'] = Hash::make($validated['password']);
        }

        $user->update($attributes);

        return to_route('admin.users.index')->with('success', 'Data user berhasil diperbarui.');
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        return Excel::download(new UserTemplateExport, 'template-user-admin.xlsx');
    }

    public function import(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
        ]);
        $usersImport = new UsersImport;

        Excel::import($usersImport, $validated['file']);

        $message = "{$usersImport->created} user berhasil ditambahkan.";

        if ($usersImport->skipped > 0) {
            $message .= " {$usersImport->skipped} baris dilewati karena tidak valid atau email duplikat.";
        }

        return to_route('admin.users.index')
            ->with('success', $message)
            ->with('import_errors', $usersImport->errors);
    }
}
