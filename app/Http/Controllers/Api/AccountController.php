<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Halaman "Akun": profil, kata sandi, alamat servis, notifikasi.
 *
 * GET    /api/account                    ringkasan akun + alamat utama
 * PUT    /api/account/profile            ubah profil
 * POST   /api/account/password           ganti kata sandi
 * GET    /api/account/addresses          daftar alamat
 * POST   /api/account/addresses          tambah alamat
 * PUT    /api/account/addresses/{id}     ubah alamat
 * POST   /api/account/addresses/{id}/default   jadikan utama
 * DELETE /api/account/addresses/{id}     hapus alamat
 * GET    /api/account/notifications      daftar notifikasi (menandai terbaca)
 */
class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $primary = DB::table('addresses')->where('user_id', $user->user_id)
            ->orderByDesc('is_default')->orderBy('address_id')->first();

        return response()->json([
            'user'    => $this->userPayload($user),
            'address' => $primary ? $this->presentAddress($primary) : null,
            'address_count' => DB::table('addresses')->where('user_id', $user->user_id)->count(),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->merge(['phone' => User::normalizePhone((string) $request->input('phone'))]);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:3', 'max:100'],
            'phone'     => ['required', 'regex:/^0[0-9]{8,14}$/', Rule::unique('users', 'phone')->ignore($user->user_id, 'user_id')],
            'email'     => ['required', 'email:rfc', 'max:100', Rule::unique('users', 'email')->ignore($user->user_id, 'user_id')],
        ], [
            'phone.regex'  => 'Nomor telepon tidak valid.',
            'phone.unique' => 'Nomor telepon sudah dipakai akun lain.',
            'email.unique' => 'Email sudah dipakai akun lain.',
        ]);

        $user->update([
            'full_name' => trim($data['full_name']),
            'phone'     => $data['phone'],
            'email'     => trim($data['email']),
        ]);

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user'    => $this->userPayload($user->fresh()),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'max:72', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed'],
        ], [
            'password.min'       => 'Kata sandi minimal 8 karakter.',
            'password.regex'     => 'Kata sandi harus mengandung huruf dan angka.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak sama.',
        ]);

        if (!Hash::check($data['current_password'], $user->password_hash)) {
            return response()->json([
                'message' => 'Kata sandi saat ini salah.',
                'errors'  => ['current_password' => ['Kata sandi saat ini salah.']],
            ], 422);
        }

        $user->update(['password_hash' => Hash::make($data['password'])]);

        DB::table('audit_logs')->insert([
            'user_id' => $user->user_id, 'event_type' => 'password_change', 'entity' => 'users',
            'entity_id' => $user->user_id, 'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json(['message' => 'Kata sandi berhasil diubah.']);
    }

    /* ----------------------------- Alamat ----------------------------- */

    public function addresses(Request $request): JsonResponse
    {
        $rows = DB::table('addresses')->where('user_id', $request->user()->user_id)
            ->orderByDesc('is_default')->orderBy('address_id')->get();

        return response()->json(['addresses' => $rows->map(fn ($a) => $this->presentAddress($a))->values()]);
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validateAddress($request);

        $hasAny = DB::table('addresses')->where('user_id', $user->user_id)->exists();
        $makeDefault = !$hasAny || (bool) ($data['is_default'] ?? false);

        $id = DB::transaction(function () use ($user, $data, $makeDefault) {
            if ($makeDefault) {
                DB::table('addresses')->where('user_id', $user->user_id)->update(['is_default' => 0]);
            }
            return DB::table('addresses')->insertGetId($this->addressRow($data, $makeDefault) + ['user_id' => $user->user_id], 'address_id');
        });

        return response()->json([
            'message' => 'Alamat berhasil ditambahkan.',
            'address' => $this->presentAddress(DB::table('addresses')->where('address_id', $id)->first()),
        ], 201);
    }

    public function updateAddress(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->ownAddress($user->user_id, $id);
        $data = $this->validateAddress($request);

        DB::table('addresses')->where('address_id', $id)->update($this->addressRow($data, null));

        return response()->json([
            'message' => 'Alamat berhasil diperbarui.',
            'address' => $this->presentAddress(DB::table('addresses')->where('address_id', $id)->first()),
        ]);
    }

    public function makeDefault(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->ownAddress($user->user_id, $id);

        DB::transaction(function () use ($user, $id) {
            DB::table('addresses')->where('user_id', $user->user_id)->update(['is_default' => 0]);
            DB::table('addresses')->where('address_id', $id)->update(['is_default' => 1]);
        });

        return response()->json(['message' => 'Alamat utama diperbarui.']);
    }

    public function destroyAddress(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $addr = $this->ownAddress($user->user_id, $id);

        if (DB::table('orders')->where('address_id', $id)->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
            return response()->json(['message' => 'Alamat dipakai oleh pesanan yang masih berjalan.'], 422);
        }
        if (DB::table('orders')->where('address_id', $id)->exists()) {
            return response()->json(['message' => 'Alamat ini tercatat pada riwayat pesanan dan tidak dapat dihapus.'], 422);
        }

        DB::table('addresses')->where('address_id', $id)->delete();

        // jika yang dihapus alamat utama, angkat alamat lain
        if ($addr->is_default) {
            $next = DB::table('addresses')->where('user_id', $user->user_id)->orderBy('address_id')->value('address_id');
            if ($next) {
                DB::table('addresses')->where('address_id', $next)->update(['is_default' => 1]);
            }
        }

        return response()->json(['message' => 'Alamat dihapus.']);
    }

    /* --------------------------- Notifikasi --------------------------- */

    public function notifications(Request $request): JsonResponse
    {
        $uid = $request->user()->user_id;
        $rows = DB::table('notifications')->where('user_id', $uid)
            ->orderByDesc('notification_id')->limit(100)
            ->get(['notification_id', 'title', 'message', 'is_read', 'created_at']);

        DB::table('notifications')->where('user_id', $uid)->where('is_read', 0)->update(['is_read' => 1]);

        return response()->json([
            'notifications' => $rows->map(fn ($n) => [
                'notification_id' => (int) $n->notification_id,
                'title'           => $n->title,
                'message'         => $n->message,
                'is_read'         => (bool) $n->is_read,
                'created_at'      => $n->created_at,
            ])->values(),
        ]);
    }

    /* ----------------------------- helper ----------------------------- */

    private function validateAddress(Request $request): array
    {
        $request->merge([
            'recipient_phone' => $request->filled('recipient_phone')
                ? User::normalizePhone((string) $request->input('recipient_phone')) : null,
        ]);

        return $request->validate([
            'label'           => ['required', 'string', 'max:30'],
            'recipient_name'  => ['nullable', 'string', 'max:100'],
            'recipient_phone' => ['nullable', 'regex:/^0[0-9]{8,14}$/'],
            'full_address'    => ['required', 'string', 'min:8', 'max:500'],
            'city'            => ['required', 'string', 'max:60'],
            'postal_code'     => ['nullable', 'string', 'max:10'],
            'note'            => ['nullable', 'string', 'max:255'],
            'latitude'        => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'       => ['nullable', 'numeric', 'between:-180,180'],
            'is_default'      => ['nullable', 'boolean'],
        ], [
            'label.required'        => 'Nama alamat wajib diisi.',
            'full_address.required' => 'Alamat lengkap wajib diisi.',
            'full_address.min'      => 'Alamat terlalu pendek.',
            'city.required'         => 'Kota wajib diisi.',
            'recipient_phone.regex' => 'Nomor telepon penerima tidak valid.',
        ]);
    }

    private function addressRow(array $d, ?bool $isDefault): array
    {
        $row = [
            'label'           => trim($d['label']),
            'recipient_name'  => $d['recipient_name'] ?? null,
            'recipient_phone' => $d['recipient_phone'] ?? null,
            'full_address'    => trim($d['full_address']),
            'city'            => trim($d['city']),
            'postal_code'     => $d['postal_code'] ?? null,
            'note'            => $d['note'] ?? null,
            'latitude'        => $d['latitude'] ?? null,
            'longitude'       => $d['longitude'] ?? null,
        ];
        if ($isDefault !== null) {
            $row['is_default'] = $isDefault ? 1 : 0;
        }
        return $row;
    }

    private function ownAddress(int $userId, int $id): object
    {
        $a = DB::table('addresses')->where('address_id', $id)->where('user_id', $userId)->first();
        abort_if(!$a, 404, 'Alamat tidak ditemukan.');
        return $a;
    }

    private function presentAddress(object $a): array
    {
        return [
            'address_id'      => (int) $a->address_id,
            'label'           => $a->label,
            'recipient_name'  => $a->recipient_name ?? null,
            'recipient_phone' => $a->recipient_phone ?? null,
            'full_address'    => $a->full_address,
            'city'            => $a->city,
            'postal_code'     => $a->postal_code,
            'note'            => $a->note ?? null,
            'latitude'        => $a->latitude === null ? null : (float) $a->latitude,
            'longitude'       => $a->longitude === null ? null : (float) $a->longitude,
            'is_default'      => (bool) $a->is_default,
        ];
    }

    private function userPayload(User $u): array
    {
        return [
            'user_id'   => $u->user_id,
            'full_name' => $u->full_name,
            'email'     => $u->email,
            'phone'     => $u->phone,
            'role'      => $u->role,
            'photo_url' => $u->photo_url,
        ];
    }
}
