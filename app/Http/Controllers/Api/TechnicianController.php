<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TechnicianMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TechnicianController extends Controller
{
    private const ACTIVE_STATUSES = ['accepted', 'on_the_way', 'in_progress'];

    private const HISTORY_STATUSES = ['completed', 'cancelled'];

    public function dashboard(Request $request): JsonResponse
    {
        $technician = $this->technician($request);
        $today = now()->toDateString();
        $orders = $this->ordersQuery($technician->technician_id)
            ->whereIn('o.status', self::ACTIVE_STATUSES)
            ->whereDate('o.scheduled_at', $today)
            ->orderBy('o.scheduled_at')
            ->get();

        return response()->json([
            'available' => (bool) $technician->is_available,
            'today_count' => $orders->count(),
            'completed_today' => DB::table('orders')
                ->where('technician_id', $technician->technician_id)
                ->where('status', 'completed')
                ->whereDate('completed_at', $today)
                ->count(),
            'next_order' => $orders->first()
                ? $this->presentOrders(collect([$orders->first()]))[0]
                : null,
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $technician = $this->technician($request);
        $data = $request->validate([
            'view' => ['nullable', 'in:active,history'],
        ]);
        $history = ($data['view'] ?? 'active') === 'history';
        $statuses = $history ? self::HISTORY_STATUSES : self::ACTIVE_STATUSES;
        $orders = $this->ordersQuery($technician->technician_id)
            ->whereIn('o.status', $statuses)
            ->orderBy($history ? 'o.completed_at' : 'o.scheduled_at', $history ? 'desc' : 'asc')
            ->get();

        return response()->json([
            'orders' => $this->presentOrders($orders),
        ]);
    }

    public function availability(Request $request): JsonResponse
    {
        $technician = $this->technician($request);
        $data = $request->validate([
            'available' => ['required', 'boolean'],
        ]);

        DB::table('technicians')
            ->where('technician_id', $technician->technician_id)
            ->update(['is_available' => (bool) $data['available']]);

        return response()->json(['available' => (bool) $data['available']]);
    }

    public function reject(Request $request, string $code, TechnicianMatcher $matcher): JsonResponse
    {
        $technician = $this->technician($request);
        $data = $request->validate([
            'reason_code' => ['required', 'string', 'in:unavailable,schedule_conflict,system_error,duplicate_offer,customer_unavailable'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $penalty = ! in_array(
            $data['reason_code'],
            config('technician.rejection_penalty_exempt_reasons', []),
            true,
        );

        $order = DB::transaction(function () use ($technician, $code, $data, $penalty) {
            $order = DB::table('orders')
                ->where('order_code', $code)
                ->where('technician_id', $technician->technician_id)
                ->lockForUpdate()
                ->first();
            abort_if(! $order, 404, 'Pesanan tidak ditemukan.');
            abort_unless($order->status === 'accepted', 409, 'Pesanan tidak dapat ditolak pada tahap ini.');

            $alreadyRejected = DB::table('technician_order_rejections')
                ->where('order_id', $order->order_id)
                ->where('technician_id', $technician->technician_id)
                ->exists();
            abort_if($alreadyRejected, 409, 'Penolakan untuk pesanan ini sudah tercatat.');

            DB::table('technician_order_rejections')->insert([
                'order_id' => $order->order_id,
                'technician_id' => $technician->technician_id,
                'reason_code' => $data['reason_code'],
                'penalty_applied' => $penalty,
                'created_at' => now(),
            ]);
            DB::table('orders')->where('order_id', $order->order_id)->update([
                'technician_id' => null,
                'status' => 'pending',
            ]);
            DB::table('order_status_history')->insert([
                'order_id' => $order->order_id,
                'status' => 'pending',
                'note' => 'Penawaran teknisi ditolak: '.($data['note'] ?? $data['reason_code']),
                'changed_at' => now(),
            ]);

            return $order;
        });

        $replacement = $matcher->assign((int) $order->order_id);

        return response()->json([
            'order_code' => $code,
            'matched' => $replacement !== null,
            'technician' => $replacement ? $this->matchedTechnician($replacement) : null,
            'rejection_penalty_applied' => $penalty,
        ]);
    }

    public function updateStatus(Request $request, string $code): JsonResponse
    {
        $technician = $this->technician($request);
        $data = $request->validate([
            'status' => ['required', 'in:on_the_way,in_progress,service_finished'],
        ]);

        $result = DB::transaction(function () use ($technician, $code, $data) {
            $order = DB::table('orders')
                ->where('order_code', $code)
                ->where('technician_id', $technician->technician_id)
                ->lockForUpdate()
                ->first();
            abort_if(! $order, 404, 'Pesanan tidak ditemukan.');

            $next = $data['status'];
            $allowed = [
                'accepted' => 'on_the_way',
                'on_the_way' => 'in_progress',
                'in_progress' => 'service_finished',
            ];
            abort_unless(($allowed[$order->status] ?? null) === $next, 409, 'Perubahan status pesanan tidak valid.');

            if ($next === 'service_finished') {
                DB::table('orders')->where('order_id', $order->order_id)
                    ->update(['service_finished_at' => now()]);
                DB::table('order_status_history')->insert([
                    'order_id' => $order->order_id,
                    'status' => 'service_finished',
                    'note' => 'Teknisi menandai servis selesai.',
                    'changed_at' => now(),
                ]);

                return ['status' => 'in_progress', 'service_finished_at' => now()->toISOString()];
            }

            DB::table('orders')->where('order_id', $order->order_id)->update(['status' => $next]);
            DB::table('order_status_history')->insert([
                'order_id' => $order->order_id,
                'status' => $next,
                'note' => 'Status diperbarui teknisi.',
                'changed_at' => now(),
            ]);

            return ['status' => $next, 'service_finished_at' => null];
        });

        return response()->json(['order_code' => $code] + $result);
    }

    private function technician(Request $request): object
    {
        $user = $request->user();
        abort_unless($user->role === 'technician', 403, 'Halaman ini khusus Teknisi.');

        $technician = DB::table('technicians')->where('user_id', $user->user_id)->first();
        abort_unless($technician && $technician->verification_status === 'verified', 403, 'Akun teknisi belum diverifikasi.');

        return $technician;
    }

    private function ordersQuery(int $technicianId)
    {
        return DB::table('orders as o')
            ->join('addresses as a', 'a.address_id', '=', 'o.address_id')
            ->join('users as c', 'c.user_id', '=', 'o.customer_id')
            ->where('o.technician_id', $technicianId)
            ->select([
                'o.order_id', 'o.order_code', 'o.status', 'o.scheduled_at', 'o.total_price',
                'o.completed_at', 'o.service_finished_at', 'a.label as address_label',
                'a.full_address', 'a.city', 'c.full_name as customer_name', 'c.phone as customer_phone',
            ]);
    }

    private function presentOrders($orders): array
    {
        $serviceRows = DB::table('order_services as os')
            ->join('services as s', 's.service_id', '=', 'os.service_id')
            ->whereIn('os.order_id', $orders->pluck('order_id'))
            ->orderBy('os.order_service_id')
            ->get(['os.order_id', 's.name', 's.est_duration_min']);
        $services = $serviceRows->groupBy('order_id');

        return $orders->map(function ($order) use ($services) {
            $items = $services->get($order->order_id, collect());
            $first = $items->first();

            return [
                'order_code' => $order->order_code,
                'status' => $order->status,
                'scheduled_at' => $order->scheduled_at,
                'completed_at' => $order->completed_at,
                'service_finished_at' => $order->service_finished_at,
                'service_name' => $first?->name ?? '-',
                'est_duration_min' => $first?->est_duration_min === null ? null : (int) $first->est_duration_min,
                'address' => trim($order->full_address.', '.$order->city, ' ,'),
                'address_label' => $order->address_label,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'total_price' => (float) $order->total_price,
                'next_status' => match ($order->status) {
                    'accepted' => 'on_the_way',
                    'on_the_way' => 'in_progress',
                    'in_progress' => $order->service_finished_at === null ? 'service_finished' : null,
                    default => null,
                },
            ];
        })->values()->all();
    }

    private function matchedTechnician(array $technician): array
    {
        return [
            'name' => $technician['name'],
            'phone' => $technician['phone'],
            'experience_year' => $technician['experience_year'],
            'rating_avg' => $technician['rating_avg'],
            'total_jobs' => $technician['total_jobs'],
            'distance_km' => $technician['distance_km'],
            'eta_minutes' => $technician['eta_minutes'],
            'score' => $technician['score'],
        ];
    }
}
