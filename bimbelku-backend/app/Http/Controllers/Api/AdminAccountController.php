<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminAccountController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePrimary($request);

        return response()->json(User::query()
            ->where('role', 'admin')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'status', 'created_at'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'is_primary' => $user->isPrimaryAdmin(),
                'created_at' => $user->created_at,
            ]));
    }

    public function store(Request $request)
    {
        $this->authorizePrimary($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'max:255'],
            'super_admin_password' => ['required', 'string'],
        ], [
            'email.unique' => 'Email ini sudah dipakai akun lain. Gunakan email admin yang berbeda.',
        ]);
        $this->verifyPassword($request, $validated['super_admin_password']);

        $admin = User::create([
            'name' => trim($validated['name']),
            'email' => mb_strtolower(trim($validated['email'])),
            'password' => Hash::make($validated['password']),
            'has_password' => true,
            'role' => 'admin',
            'admin_type' => 'admin',
            'status' => 'active',
        ]);
        $request->attributes->set('admin_audit_target_override', $admin);

        return response()->json([
            'message' => 'Akun admin berhasil dibuat.',
            'data' => $admin->only(['id', 'name', 'email', 'status']),
        ], 201);
    }

    public function update(Request $request, User $admin)
    {
        $this->authorizePrimary($request);
        abort_unless($admin->role === 'admin', 404);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($admin->id)],
            'password' => ['nullable', 'string', 'min:12', 'max:255'],
            'super_admin_password' => ['required', 'string'],
        ], [
            'email.unique' => 'Email ini sudah dipakai akun lain. Gunakan email admin yang berbeda.',
        ]);
        $this->verifyPassword($request, $validated['super_admin_password']);

        $changes = [
            'name' => trim($validated['name']),
            'email' => mb_strtolower(trim($validated['email'])),
        ];
        if (! empty($validated['password'])) {
            $changes['password'] = Hash::make($validated['password']);
            $changes['has_password'] = true;
            $changes['password_updated_at'] = now();
        }
        $admin->forceFill($changes)->save();
        if (! empty($validated['password'])) {
            $admin->tokens()->delete();
        }

        return response()->json([
            'message' => 'Akun admin berhasil diperbarui.',
            'data' => $admin->fresh()->only(['id', 'name', 'email', 'status']),
        ]);
    }

    public function destroy(Request $request, User $admin)
    {
        $this->authorizePrimary($request);
        abort_unless($admin->role === 'admin', 404);
        abort_if($admin->isPrimaryAdmin(), 422, 'Admin utama tidak dapat dihapus.');
        abort_if((int) $request->user()->id === (int) $admin->id, 422, 'Akun yang sedang digunakan tidak dapat dihapus.');
        $validated = $request->validate(['super_admin_password' => ['required', 'string']]);
        $this->verifyPassword($request, $validated['super_admin_password']);

        $adminId = $admin->id;
        $admin->tokens()->delete();
        $admin->forceFill([
            'name' => 'Admin dihapus',
            'email' => 'deleted-admin-'.$adminId.'-'.now()->format('YmdHis').'@invalid.local',
            'password' => Hash::make(Str::random(64)),
            'status' => 'banned',
        ])->save();
        $admin->delete();

        return response()->json(['message' => 'Akun admin berhasil dihapus.']);
    }

    public function updateStatus(Request $request, User $admin)
    {
        $this->authorizePrimary($request);
        abort_unless($admin->role === 'admin', 404);
        abort_if($admin->isPrimaryAdmin(), 422, 'Admin utama tidak dapat dinonaktifkan.');
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'banned'])],
            'super_admin_password' => ['required', 'string'],
        ]);
        $this->verifyPassword($request, $validated['super_admin_password']);

        $admin->update(['status' => $validated['status']]);
        if ($validated['status'] === 'banned') {
            $admin->tokens()->delete();
        }

        return response()->json(['message' => 'Status admin berhasil diperbarui.']);
    }

    private function authorizePrimary(Request $request): void
    {
        abort_unless($request->user()?->isPrimaryAdmin(), 403, 'Hanya admin utama yang dapat mengelola akun admin.');
    }

    private function verifyPassword(Request $request, string $password): void
    {
        abort_unless(Hash::check($password, $request->user()->password), 422, 'Kata sandi admin utama tidak sesuai.');
    }
}
