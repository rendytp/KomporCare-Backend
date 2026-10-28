<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const MAX_FAILED   = 5;   // gagal login berturut-turut sebelum dikunci
    private const LOCK_MINUTES = 15;
    private const RESET_MINUTES = 30;

    /* ------------------------------------------------------------------ */
    /*  POST /api/auth/login                                               */
    /* ------------------------------------------------------------------ */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:100'],
            'password'   => ['required', 'string', 'max:255'],
        ]);

        $identifier = trim($data['identifier']);
        $user = str_contains($identifier, '@')
            ? User::where('email', $identifier)->first()
            : User::where('phone', User::normalizePhone($identifier))->first();

        $invalid = response()->json(['message' => 'Email/nomor telepon atau kata sandi salah.'], 401);

        if (!$user) {
            $this->audit(null, 'login_failed', $request, 'identifier tidak dikenal');
            return $invalid;
        }

        if ($user->locked_until && $user->locked_until->isFuture()) {
            $mins = (int) ceil(now()->diffInSeconds($user->locked_until, false) / 60);
            return response()->json([
                'message' => "Akun dikunci sementara. Coba lagi dalam {$mins} menit.",
            ], 423);
        }

        if (!Hash::check($data['password'], $user->password_hash)) {
            $count = $user->failed_login_count + 1;
            $update = ['failed_login_count' => $count];
            if ($count >= self::MAX_FAILED) {
                $update['failed_login_count'] = 0;
                $update['locked_until'] = now()->addMinutes(self::LOCK_MINUTES);
                $this->audit($user->user_id, 'account_locked', $request);
            }
            $user->update($update);
            $this->audit($user->user_id, 'login_failed', $request);
            return $invalid;
        }

        // Peran ditentukan oleh database (users.role). Admin tidak memakai aplikasi mobile.
        if (!in_array($user->role, ['customer', 'technician'], true)) {
            $this->audit($user->user_id, 'login_failed', $request, 'peran tidak diizinkan di aplikasi mobile');
            return $invalid;
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Akun Anda dinonaktifkan. Hubungi admin KomporCare.'], 403);
        }

        if ($user->role === 'technician') {
            $status = DB::table('technicians')->where('user_id', $user->user_id)->value('verification_status');
            if ($status !== 'verified') {
                return response()->json([
                    'message' => $status === 'rejected'
                        ? 'Pendaftaran Teknisi Anda ditolak. Hubungi admin KomporCare.'
                        : 'Akun Teknisi Anda belum diverifikasi oleh admin.',
                ], 403);
            }
        }

        $user->update([
            'failed_login_count' => 0,
            'locked_until'       => null,
            'last_login_at'      => now(),
        ]);
        $this->audit($user->user_id, 'login_success', $request);

        $token = $user->createToken('mobile', [$user->role])->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',
            'token'   => $token,
            'user'    => $this->userPayload($user),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  POST /api/auth/register  (hanya Pelanggan, sesuai desain)          */
    /* ------------------------------------------------------------------ */
    public function register(Request $request): JsonResponse
    {
        $request->merge(['phone' => User::normalizePhone((string) $request->input('phone'))]);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:3', 'max:100'],
            'phone'     => ['required', 'regex:/^0[0-9]{8,14}$/', 'unique:users,phone'],
            'email'     => ['required', 'email:rfc', 'max:100', 'unique:users,email'],
            'password'  => ['required', 'string', 'min:8', 'max:72', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed'],
            'accept_terms' => ['accepted'],
        ], [
            'phone.regex'      => 'Nomor telepon tidak valid.',
            'phone.unique'     => 'Nomor telepon sudah terdaftar.',
            'email.unique'     => 'Email sudah terdaftar.',
            'password.min'     => 'Kata sandi minimal 8 karakter.',
            'password.regex'   => 'Kata sandi harus mengandung huruf dan angka.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak sama.',
            'accept_terms.accepted' => 'Anda harus menyetujui Syarat & Ketentuan.',
        ]);

        try {
            // Memakai stored procedure sp_register_user (sudah mencatat audit_logs 'register').
            $row = DB::select('CALL sp_register_user(?, ?, ?, ?, ?)', [
                $data['full_name'],
                $data['email'],
                $data['phone'],
                Hash::make($data['password']),
                'customer',
            ]);
        } catch (QueryException $e) {
            Log::error('register failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Pendaftaran gagal. Coba lagi.'], 500);
        }

        $user = User::find($row[0]->user_id);
        $token = $user->createToken('mobile', [$user->role])->plainTextToken;

        return response()->json([
            'message' => 'Akun berhasil dibuat.',
            'token'   => $token,
            'user'    => $this->userPayload($user),
        ], 201);
    }

    /* ------------------------------------------------------------------ */
    /*  POST /api/auth/forgot-password                                     */
    /* ------------------------------------------------------------------ */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:100'],
        ]);

        $identifier = trim($data['identifier']);
        $user = str_contains($identifier, '@')
            ? User::where('email', $identifier)->first()
            : User::where('phone', User::normalizePhone($identifier))->first();

        if ($user && $user->is_active && $user->role !== 'admin') {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('password_reset_codes')->where('user_id', $user->user_id)->delete();
            DB::table('password_reset_codes')->insert([
                'user_id'    => $user->user_id,
                'code_hash'  => Hash::make($code),
                'expires_at' => now()->addMinutes(self::RESET_MINUTES),
                'created_at' => now(),
            ]);

            // TODO: kirim $code lewat WhatsApp gateway (Fonnte/Twilio/dll) atau email (Mail::to()).
            // Untuk pengembangan, kode hanya dicatat di log Laravel.
            if (config('app.debug')) {
                Log::info("[KomporCare] Kode reset untuk user {$user->user_id}: {$code}");
            }
        }

        // Respons selalu sama agar keberadaan akun tidak bisa ditebak.
        return response()->json([
            'message' => 'Jika akun terdaftar, instruksi pemulihan telah dikirim ke email atau WhatsApp Anda.',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  POST /api/auth/reset-password                                      */
    /* ------------------------------------------------------------------ */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:100'],
            'code'       => ['required', 'digits:6'],
            'password'   => ['required', 'string', 'min:8', 'max:72', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed'],
        ]);

        $identifier = trim($data['identifier']);
        $user = str_contains($identifier, '@')
            ? User::where('email', $identifier)->first()
            : User::where('phone', User::normalizePhone($identifier))->first();

        $fail = response()->json(['message' => 'Kode tidak valid atau sudah kedaluwarsa.'], 422);
        if (!$user) {
            return $fail;
        }

        $row = DB::table('password_reset_codes')->where('user_id', $user->user_id)->first();
        if (!$row || now()->greaterThan($row->expires_at) || $row->attempts >= 5) {
            return $fail;
        }
        if (!Hash::check($data['code'], $row->code_hash)) {
            DB::table('password_reset_codes')->where('id', $row->id)->increment('attempts');
            return $fail;
        }

        $user->update([
            'password_hash'      => Hash::make($data['password']),
            'failed_login_count' => 0,
            'locked_until'       => null,
        ]);
        DB::table('password_reset_codes')->where('user_id', $user->user_id)->delete();
        $user->tokens()->delete(); // keluarkan semua sesi lama
        $this->audit($user->user_id, 'password_change', $request);

        return response()->json(['message' => 'Kata sandi berhasil diubah. Silakan masuk.']);
    }

    /* ------------------------------------------------------------------ */
    /*  GET /api/auth/me   |   POST /api/auth/logout                       */
    /* ------------------------------------------------------------------ */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        $this->audit($request->user()->user_id, 'logout', $request);
        return response()->json(['message' => 'Berhasil keluar.']);
    }

    /* ------------------------------------------------------------------ */
    private function userPayload(User $user): array
    {
        return [
            'user_id'   => $user->user_id,
            'full_name' => $user->full_name,
            'email'     => $user->email,
            'phone'     => $user->phone,
            'role'      => $user->role,
            'photo_url' => $user->photo_url,
        ];
    }

    private function audit(?int $userId, string $event, Request $request, ?string $detail = null): void
    {
        DB::table('audit_logs')->insert([
            'user_id'    => $userId,
            'event_type' => $event,
            'entity'     => 'users',
            'entity_id'  => $userId,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'detail'     => $detail,
            'created_at' => now(),
        ]);
    }
}
