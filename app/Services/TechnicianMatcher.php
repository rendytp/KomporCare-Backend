<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pencocokan teknisi otomatis (mirip alur ojek online).
 *
 * Kandidat HARUS: terverifikasi, tersedia, akun aktif, menguasai layanan yang dipesan,
 * dan tidak bentrok jadwal (+/- 90 menit) dengan pesanan lain miliknya.
 * Bila lokasi pelanggan & teknisi diketahui, kandidat di luar MAX_KM dibuang.
 *
 * Skor (0-100):
 *   50%  kedekatan   (1 - jarak / MAX_KM)
 *   25%  pengalaman  (experience_year, maksimal 10 tahun)
 *   20%  rating      (rating_avg / 5; teknisi tanpa riwayat diberi nilai awal 0,7)
 *    5%  jam terbang (total_jobs, maksimal 100)
 */
class TechnicianMatcher
{
    public const MAX_KM = 15.0;
    public const SPEED_KMH = 25.0;   // kecepatan rata-rata motor dalam kota
    public const ROAD_FACTOR = 1.3;  // garis lurus -> perkiraan jarak jalan

    private const W_DISTANCE = 0.50;
    private const W_EXPERIENCE = 0.25;
    private const W_RATING = 0.20;
    private const W_JOBS = 0.05;

    /** Jarak garis lurus (km). */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Perkiraan jarak tempuh jalan (km) dari jarak garis lurus. */
    public static function roadKm(float $straightKm): float
    {
        return round($straightKm * self::ROAD_FACTOR, 1);
    }

    public static function etaMinutes(float $roadKm): int
    {
        return max(3, (int) ceil($roadKm / self::SPEED_KMH * 60));
    }

    /**
     * Daftar kandidat terurut dari skor tertinggi.
     *
     * @param  int[]  $exclude  technician_id yang dilewati (mis. sudah menolak)
     */
    public function rank(int $serviceId, ?float $lat, ?float $lng, Carbon $when, array $exclude = []): array
    {
        $rows = DB::table('technicians as t')
            ->join('users as u', 'u.user_id', '=', 't.user_id')
            ->join('technician_services as ts', function ($j) use ($serviceId) {
                $j->on('ts.technician_id', '=', 't.technician_id')->where('ts.service_id', '=', $serviceId);
            })
            ->where('t.verification_status', 'verified')
            ->where('t.is_available', 1)
            ->where('u.is_active', 1)
            ->when($exclude, fn ($q) => $q->whereNotIn('t.technician_id', $exclude))
            ->whereNotExists(function ($q) use ($when) {
                $q->select(DB::raw(1))->from('orders as o')
                    ->whereColumn('o.technician_id', 't.technician_id')
                    ->whereIn('o.status', ['accepted', 'on_the_way', 'in_progress'])
                    ->whereBetween('o.scheduled_at', [
                        $when->copy()->subMinutes(90)->toDateTimeString(),
                        $when->copy()->addMinutes(90)->toDateTimeString(),
                    ]);
            })
            ->get([
                't.technician_id', 't.user_id', 'u.full_name', 'u.phone',
                't.experience_year', 't.rating_avg', 't.total_jobs',
                't.current_lat', 't.current_lng',
            ]);

        $out = [];
        foreach ($rows as $r) {
            $straight = null;
            if ($lat !== null && $lng !== null && $r->current_lat !== null && $r->current_lng !== null) {
                $straight = self::haversine($lat, $lng, (float) $r->current_lat, (float) $r->current_lng);
                if ($straight > self::MAX_KM) {
                    continue;
                }
            }

            $distScore = $straight === null ? 0.3 : max(0.0, 1 - $straight / self::MAX_KM);
            $expScore = min((int) $r->experience_year, 10) / 10;
            $ratingScore = ((int) $r->total_jobs) > 0 ? min(5.0, (float) $r->rating_avg) / 5 : 0.7;
            $jobsScore = min((int) $r->total_jobs, 100) / 100;

            $score = 100 * (
                self::W_DISTANCE * $distScore
                + self::W_EXPERIENCE * $expScore
                + self::W_RATING * $ratingScore
                + self::W_JOBS * $jobsScore
            );

            $roadKm = $straight === null ? null : self::roadKm($straight);

            $out[] = [
                'technician_id'   => (int) $r->technician_id,
                'user_id'         => (int) $r->user_id,
                'name'            => $r->full_name,
                'phone'           => $r->phone,
                'experience_year' => (int) $r->experience_year,
                'rating_avg'      => (float) $r->rating_avg,
                'total_jobs'      => (int) $r->total_jobs,
                'distance_km'     => $roadKm,
                'eta_minutes'     => $roadKm === null ? null : self::etaMinutes($roadKm),
                'score'           => round($score, 1),
            ];
        }

        // Skor tertinggi dulu; bila sama, yang lebih dekat, lalu yang lebih berpengalaman.
        usort($out, function ($a, $b) {
            return [$b['score'], $a['distance_km'] ?? 999, $b['experience_year']]
                <=> [$a['score'], $b['distance_km'] ?? 999, $a['experience_year']];
        });

        return $out;
    }

    /**
     * Pilih teknisi terbaik dan tetapkan ke pesanan (status pending -> accepted).
     * Mengembalikan data teknisi terpilih, atau null bila tidak ada kandidat.
     */
    public function assign(int $orderId): ?array
    {
        $o = DB::table('orders as o')
            ->join('order_services as os', 'os.order_id', '=', 'o.order_id')
            ->join('addresses as a', 'a.address_id', '=', 'o.address_id')
            ->where('o.order_id', $orderId)
            ->orderBy('os.order_service_id')
            ->first(['o.order_id', 'o.order_code', 'o.customer_id', 'o.scheduled_at', 'o.status',
                'os.service_id', 'a.latitude', 'a.longitude']);

        if (!$o || !in_array($o->status, ['pending', 'waiting_payment'], true)) {
            return null;
        }

        $lat = $o->latitude === null ? null : (float) $o->latitude;
        $lng = $o->longitude === null ? null : (float) $o->longitude;

        $candidates = $this->rank((int) $o->service_id, $lat, $lng, Carbon::parse($o->scheduled_at));

        foreach ($candidates as $best) {
            $assigned = DB::transaction(function () use ($o, $best) {
                // whereNull mencegah dua proses menetapkan teknisi pada pesanan yang sama
                $n = DB::table('orders')
                    ->where('order_id', $o->order_id)
                    ->whereNull('technician_id')
                    ->whereIn('status', ['pending', 'waiting_payment'])
                    ->update(['technician_id' => $best['technician_id'], 'status' => 'accepted']);
                if (!$n) {
                    return false;
                }

                DB::table('order_status_history')->insert([
                    'order_id'   => $o->order_id,
                    'status'     => 'accepted',
                    'note'       => 'Teknisi ' . $best['name'] . ' ditugaskan otomatis (skor ' . $best['score'] . ')',
                    'changed_at' => now(),
                ]);
                DB::table('notifications')->insert([
                    'user_id'    => $best['user_id'],
                    'title'      => 'Pesanan baru',
                    'message'    => 'Anda mendapat pesanan ' . $o->order_code . '. Cek jadwal dan alamat pelanggan.',
                    'created_at' => now(),
                ]);

                return true;
            });

            if ($assigned) {
                return $best;
            }
        }

        return null;
    }
}
