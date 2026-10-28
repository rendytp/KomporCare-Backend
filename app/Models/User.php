<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Memetakan tabel `users` pada database kompor_care.
 * - Primary key: user_id
 * - Kolom password: password_hash
 * - Tidak ada updated_at / remember_token
 */
class User extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';
    protected $primaryKey = 'user_id';
    public $timestamps = false;

    protected $fillable = [
        'full_name', 'email', 'phone', 'password_hash', 'role', 'photo_url',
        'is_active', 'failed_login_count', 'locked_until', 'last_login_at',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'is_active'    => 'boolean',
        'locked_until' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /** Normalisasi nomor WhatsApp ke format 08xxxxxxxx (sama seperti data di DB). */
    public static function normalizePhone(string $phone): string
    {
        $p = preg_replace('/[^0-9+]/', '', $phone);
        if (str_starts_with($p, '+62')) {
            $p = '0' . substr($p, 3);
        } elseif (str_starts_with($p, '62')) {
            $p = '0' . substr($p, 2);
        }
        return $p;
    }
}
