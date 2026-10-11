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
        $actor = request()->user();

        return view('admin.users.index', [
            'users' => User::query()->latest()->get(),
            'assignableRoles' => $actor->assignableRoles(),
            'canDeleteUsers' => $actor->isDeveloper(),
            'developerCount' => User::query()->where('role', 'developer')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in($request->user()->assignableRoles())],
        ]);

        User::create($validated);

        return to_route('admin.users.index')->with('success', 'User admin berhasil ditambahkan.');
    }

    public function edit(User $user): View
    {
        $actor = request()->user();
        abort_if($actor->isSuperAdmin() && $user->isDeveloper(), 403);

        return view('admin.users.edit', [
            'user' => $user,
            'assignableRoles' => $actor->assignableRoles(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor->isSuperAdmin() && $user->isDeveloper(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in($actor->assignableRoles())],
        ]);

        // The last remaining developer can neither be demoted nor left
        // without a successor: the guard runs on the persisted state.
        if ($user->isDeveloper() && $validated['role'] !== 'developer'
            && ! User::anotherDeveloperExists($user->id)) {
            abort(403, 'Developer terakhir tidak dapat diturunkan role-nya.');
        }

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

    public function destroy(User $user): RedirectResponse
    {
        $actor = request()->user();

        // Super admins never reach this route (developer-only middleware),
        // but the guards below make the rule explicit and future-proof.
        abort_if($actor->isSuperAdmin(), 403);
        abort_if($user->is($actor), 403, 'Akun sendiri tidak dapat dihapus.');
        abort_if($user->isDeveloper() && ! User::anotherDeveloperExists($user->id),
            403, 'Developer terakhir tidak dapat dihapus.');

        $user->delete();

        return to_route('admin.users.index')->with('success', 'Akun user berhasil dihapus.');
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
        $usersImport = new UsersImport($request->user()->assignableRoles());

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
