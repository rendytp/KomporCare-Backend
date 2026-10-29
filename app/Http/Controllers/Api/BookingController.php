<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TechnicianMatcher;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pemesanan + pencocokan teknisi otomatis + pelacakan.
 *
 * POST /api/customer/orders                    buat pesanan lalu cari teknisi terdekat
 * POST /api/customer/orders/{code}/match       cari ulang teknisi (bila sebelumnya belum ada)
 * POST /api/customer/orders/{code}/cancel      batalkan pesanan
 * GET  /api/customer/orders/{code}/tracking    status, posisi teknisi, ETA, linimasa
 */
class BookingController extends Controller
{
    public function store(Request $request, TechnicianMatcher $matcher): JsonResponse
    {
        $user = $this->customer($request);

        $data = $request->validate([
            'service_id'   => ['required', 'integer'],
            'address_id'   => ['required', 'integer'],
            'scheduled_at' => ['nullable', 'date'],
            'note'         => ['nullable', 'string', 'max:500'],
        ], [
            'service_id.required' => 'Pilih layanan.',
            'address_id.required' => 'Pilih alamat servis.',
        ]);

        $when = !empty($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : now();
        if ($when->lt(now()->subMinutes(10))) {
            return response()->json(['message' => 'Jadwal tidak boleh di masa lalu.'], 422);
        }
        if ($when->gt(now()->addDays(30))) {
            return response()->json(['message' => 'Jadwal maksimal 30 hari ke depan.'], 422);
        }

        $service = DB::table('services')->where('service_id', $data['service_id'])->where('is_active', 1)->first();
        if (!$service) {
            return response()->json(['message' => 'Layanan tidak ditemukan.'], 422);
        }

        // Pembayaran dilakukan setelah servis selesai (Tunai/QRIS dipilih di halaman Pembayaran),
        // jadi pesanan dibuat dengan metode tunai sebagai bawaan.
        $cashId = DB::table('payment_methods')->where('type', 'cash')->where('is_active', 1)->value('method_id');
        if (!$cashId) {
            return response()->json(['message' => 'Metode pembayaran belum tersedia.'], 500);
        }

        try {
            $row = DB::select('CALL sp_create_order(?, ?, ?, ?, ?, ?, ?)', [
                $user->user_id, $data['address_id'], $when->toDateTimeString(),
                $service->service_id, 1, $cashId, $data['note'] ?? null,
            ]);
        } catch (QueryException $e) {
            $msg = $e->errorInfo[2] ?? '';
            if (str_contains($msg, 'Address does not belong')) {
                return response()->json(['message' => 'Alamat tidak valid.'], 422);
            }
            return response()->json(['message' => 'Pesanan gagal dibuat. Coba lagi.'], 500);
        }

        $orderId = (int) $row[0]->order_id;
        $code = $row[0]->order_code;

        // Biaya kunjungan masuk ke total tagihan.
        $subtotal = (float) DB::table('orders')->where('order_id', $orderId)->value('subtotal');
        $total = $subtotal + (float) $service->visit_fee;
        DB::table('orders')->where('order_id', $orderId)
            ->update(['transport_fee' => $service->visit_fee, 'total_price' => $total]);
        DB::table('payments')->where('order_id', $orderId)->update(['amount' => $total]);

        $best = $matcher->assign($orderId);

        return response()->json($this->matchPayload($code, $best), 201);
    }

    public function match(Request $request, string $code, TechnicianMatcher $matcher): JsonResponse
    {
        $user = $this->customer($request);
        $o = $this->ownOrder($user->user_id, $code);

        if ($o->technician_id) {
            return response()->json($this->matchPayload($code, $this->technicianSummary((int) $o->technician_id, $o)));
        }
        abort_unless(in_array($o->status, ['pending', 'waiting_payment'], true), 422, 'Pesanan tidak dapat dicocokkan.');

        return response()->json($this->matchPayload($code, $matcher->assign((int) $o->order_id)));
    }

    public function cancel(Request $request, string $code): JsonResponse
    {
        $user = $this->customer($request);
        $o = $this->ownOrder($user->user_id, $code);

        if (!in_array($o->status, ['waiting_payment', 'pending', 'accepted', 'on_the_way'], true)) {
            return response()->json(['message' => 'Pesanan tidak dapat dibatalkan pada tahap ini.'], 422);
        }

        $reason = mb_substr(trim((string) $request->input('reason', 'Dibatalkan pelanggan')), 0, 255);

        DB::transaction(function () use ($o, $reason, $user) {
            DB::table('orders')->where('order_id', $o->order_id)
                ->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
            DB::table('payments')->where('order_id', $o->order_id)->where('status', 'pending')
                ->update(['status' => 'failed']);
            DB::table('order_status_history')->insert([
                'order_id' => $o->order_id, 'status' => 'cancelled', 'note' => $reason, 'changed_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'user_id' => $user->user_id, 'event_type' => 'order_cancelled',
                'entity' => 'orders', 'entity_id' => $o->order_id,
            ]);
        });

        return response()->json(['message' => 'Pesanan dibatalkan.']);
    }

    public function tracking(Request $request, string $code): JsonResponse
    {
        $user = $this->customer($request);

        $o = DB::table('orders as o')
            ->join('addresses as a', 'a.address_id', '=', 'o.address_id')
            ->join('order_services as os', 'os.order_id', '=', 'o.order_id')
            ->join('services as s', 's.service_id', '=', 'os.service_id')
            ->leftJoin('technicians as t', 't.technician_id', '=', 'o.technician_id')
            ->leftJoin('users as tu', 'tu.user_id', '=', 't.user_id')
            ->leftJoin('payments as p', 'p.order_id', '=', 'o.order_id')
            ->where('o.customer_id', $user->user_id)
            ->where('o.order_code', $code)
            ->orderBy('os.order_service_id')
            ->first([
                'o.order_id', 'o.order_code', 'o.status', 'o.scheduled_at', 'o.total_price',
                'o.service_finished_at', 'o.completed_at', 'o.created_at', 'o.cancel_reason', 'o.technician_id as order_tech_id',
                'a.full_address', 'a.city', 'a.latitude as dest_lat', 'a.longitude as dest_lng',
                's.name as service_name', 's.est_duration_min',
                't.technician_id', 't.current_lat', 't.current_lng', 't.rating_avg',
                't.experience_year', 't.total_jobs',
                'tu.full_name as tech_name', 'tu.phone as tech_phone',
                'p.status as payment_status',
            ]);
        abort_if(!$o, 404, 'Pesanan tidak ditemukan.');

        $destLat = $o->dest_lat === null ? null : (float) $o->dest_lat;
        $destLng = $o->dest_lng === null ? null : (float) $o->dest_lng;
        $techLat = $o->current_lat === null ? null : (float) $o->current_lat;
        $techLng = $o->current_lng === null ? null : (float) $o->current_lng;

        $km = null;
        $eta = null;
        $etaAt = null;
        if ($destLat !== null && $techLat !== null) {
            $km = TechnicianMatcher::roadKm(TechnicianMatcher::haversine($techLat, $techLng, $destLat, $destLng));
            $eta = TechnicianMatcher::etaMinutes($km);
            if ($o->status === 'on_the_way') {
                $etaAt = now()->addMinutes($eta)->format('H:i');
            }
        }

        $steps = $this->timeline($o, $etaAt);

        return response()->json([
            'order_code'    => $o->order_code,
            'status'        => $o->status,
            'scheduled_at'  => $o->scheduled_at,
            'service_name'  => $o->service_name,
            'est_duration_min' => $o->est_duration_min === null ? null : (int) $o->est_duration_min,
            'total_price'   => (float) $o->total_price,
            'address'       => trim($o->full_address . ', ' . $o->city, ' ,'),
            'destination'   => $destLat === null ? null : ['lat' => $destLat, 'lng' => $destLng],
            'technician'    => $o->technician_id ? [
                'name'            => $o->tech_name,
                'phone'           => $o->tech_phone,
                'rating_avg'      => (float) $o->rating_avg,
                'experience_year' => (int) $o->experience_year,
                'total_jobs'      => (int) $o->total_jobs,
                'lat'             => $techLat,
                'lng'             => $techLng,
            ] : null,
            'distance_km'   => $km,
            'eta_minutes'   => $eta,
            'eta_at'        => $etaAt,
            'steps'         => $steps,
            'can_pay'       => $o->status === 'in_progress' && $o->service_finished_at !== null
                && $o->payment_status === 'pending',
            'finished'      => $o->service_finished_at !== null,
        ]);
    }

    /* ----------------------------- helper ----------------------------- */

    /**
     * Linimasa pesanan, dihitung dari STATUS pesanan yang sebenarnya.
     * Waktu diambil dari order_status_history bila ada, lalu dari kolom
     * waktu pada tabel orders. Dengan begitu linimasa tetap benar walau status
     * diubah langsung di database (yang tidak menulis riwayat).
     */
    private function timeline(object $o, ?string $etaAt): array
    {
        $hist = [];
        foreach (DB::table('order_status_history')->where('order_id', $o->order_id)->orderBy('history_id')->get() as $h) {
            $hist[$h->status] ??= $h->changed_at;
        }

        $rankOf = ['waiting_payment' => 0, 'pending' => 0, 'accepted' => 1, 'on_the_way' => 2, 'in_progress' => 3];
        $cancelled = $o->status === 'cancelled';

        if ($cancelled) {
            // Sampai tahap mana pesanan sebelum dibatalkan.
            $rank = 0;
            foreach ($hist as $status => $_) {
                $rank = max($rank, $rankOf[$status] ?? 0);
            }
            if ($o->order_tech_id !== null) {
                $rank = max($rank, 1);
            }
        } elseif ($o->status === 'completed') {
            $rank = 5;
        } elseif ($o->status === 'in_progress') {
            $rank = $o->service_finished_at !== null ? 4 : 3;
        } else {
            $rank = $rankOf[$o->status] ?? 0;
        }

        $fmt = fn ($t) => $t ? Carbon::parse($t)->format('H:i') : null;
        $tech = $o->tech_name;

        $defs = [
            ['key' => 'created', 'title' => 'Pesanan dibuat',
                'note' => 'Pesanan Anda diterima dan sedang diproses.',
                'time' => $hist['pending'] ?? $hist['waiting_payment'] ?? $o->created_at],
            ['key' => 'accepted', 'title' => 'Pesanan dikonfirmasi',
                'note' => $tech ? "{$tech} menerima kunjungan." : 'Mencari teknisi terdekat.',
                'time' => $hist['accepted'] ?? null],
            ['key' => 'on_the_way', 'title' => 'Teknisi dalam perjalanan',
                'note' => ($etaAt && $o->status === 'on_the_way')
                    ? "Perkiraan tiba pukul {$etaAt} WIB."
                    : 'Teknisi berangkat menuju lokasi Anda.',
                'time' => $hist['on_the_way'] ?? null],
            ['key' => 'in_progress', 'title' => 'Servis dimulai',
                'note' => 'Teknisi mengecek dan memperbaiki kompor.',
                'time' => $hist['in_progress'] ?? null],
            ['key' => 'finished', 'title' => 'Servis selesai',
                'note' => 'Lakukan pembayaran untuk menyelesaikan pesanan.',
                'time' => $o->service_finished_at],
            ['key' => 'completed', 'title' => 'Pesanan selesai',
                'note' => 'Pembayaran diterima. Jangan lupa beri ulasan.',
                'time' => $hist['completed'] ?? $o->completed_at],
        ];

        $steps = [];
        foreach ($defs as $i => $d) {
            if ($cancelled && $i > $rank) {
                break; // langkah yang tidak pernah tercapai tidak ditampilkan
            }
            $steps[] = [
                'key'     => $d['key'],
                'title'   => $d['title'],
                'note'    => $d['note'],
                'time'    => $fmt($d['time']),
                'done'    => $i <= $rank,
                'current' => !$cancelled && $i === $rank + 1,
                'failed'  => false,
            ];
        }

        if ($cancelled) {
            $steps[] = [
                'key'     => 'cancelled',
                'title'   => 'Pesanan dibatalkan',
                'note'    => $o->cancel_reason ?: 'Pesanan dibatalkan.',
                'time'    => $fmt($hist['cancelled'] ?? null),
                'done'    => true,
                'current' => false,
                'failed'  => true,
            ];
        }

        return $steps;
    }

    private function matchPayload(string $code, ?array $best): array
    {
        return [
            'order_code' => $code,
            'matched'    => $best !== null,
            'technician' => $best ? [
                'name'            => $best['name'],
                'phone'           => $best['phone'],
                'experience_year' => $best['experience_year'],
                'rating_avg'      => $best['rating_avg'],
                'total_jobs'      => $best['total_jobs'],
                'distance_km'     => $best['distance_km'],
                'eta_minutes'     => $best['eta_minutes'],
                'score'           => $best['score'],
            ] : null,
        ];
    }

    private function technicianSummary(int $technicianId, object $order): array
    {
        $t = DB::table('technicians as t')->join('users as u', 'u.user_id', '=', 't.user_id')
            ->where('t.technician_id', $technicianId)
            ->first(['u.full_name', 'u.phone', 't.experience_year', 't.rating_avg', 't.total_jobs', 't.current_lat', 't.current_lng']);

        $addr = DB::table('addresses')->where('address_id', $order->address_id)->first(['latitude', 'longitude']);
        $km = null;
        if ($addr && $addr->latitude !== null && $t->current_lat !== null) {
            $km = TechnicianMatcher::roadKm(TechnicianMatcher::haversine(
                (float) $addr->latitude, (float) $addr->longitude, (float) $t->current_lat, (float) $t->current_lng));
        }

        return [
            'name' => $t->full_name, 'phone' => $t->phone,
            'experience_year' => (int) $t->experience_year, 'rating_avg' => (float) $t->rating_avg,
            'total_jobs' => (int) $t->total_jobs, 'distance_km' => $km,
            'eta_minutes' => $km === null ? null : TechnicianMatcher::etaMinutes($km), 'score' => 0,
        ];
    }

    private function customer(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role === 'customer', 403, 'Halaman ini khusus Pelanggan.');
        return $user;
    }

    private function ownOrder(int $userId, string $code): object
    {
        $o = DB::table('orders')->where('customer_id', $userId)->where('order_code', $code)->first();
        abort_if(!$o, 404, 'Pesanan tidak ditemukan.');
        return $o;
    }
}
