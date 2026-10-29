<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Halaman "Pesanan" pelanggan.
 * GET /api/customer/orders        -> kunjungan aktif + riwayat
 * GET /api/customer/orders/{code} -> detail satu pesanan
 */
class OrderController extends Controller
{
    private const ACTIVE = ['waiting_payment', 'pending', 'accepted', 'on_the_way', 'in_progress'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'customer', 403, 'Halaman ini khusus Pelanggan.');

        $orders = $this->baseQuery()
            ->where('o.customer_id', $user->user_id)
            ->orderByRaw("FIELD(o.status, 'on_the_way','in_progress','accepted','pending','waiting_payment') = 0")
            ->orderByDesc('o.scheduled_at')
            ->get();

        $items = $this->itemsFor($orders->pluck('order_id')->all());
        $rows = $orders->map(fn ($o) => $this->present($o, $items[$o->order_id] ?? []))->values();

        return response()->json([
            'active'  => $rows->filter(fn ($r) => in_array($r['status'], self::ACTIVE, true))->values(),
            'history' => $rows->filter(fn ($r) => !in_array($r['status'], self::ACTIVE, true))->values(),
        ]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'customer', 403, 'Halaman ini khusus Pelanggan.');

        $o = $this->baseQuery()
            ->where('o.customer_id', $user->user_id)
            ->where('o.order_code', $code)
            ->first();
        abort_if(!$o, 404, 'Pesanan tidak ditemukan.');

        $items = $this->itemsFor([$o->order_id]);

        return response()->json([
            'order' => $this->present($o, $items[$o->order_id] ?? []),
        ]);
    }

    private function baseQuery()
    {
        return DB::table('orders as o')
            ->join('addresses as a', 'a.address_id', '=', 'o.address_id')
            ->leftJoin('technicians as t', 't.technician_id', '=', 'o.technician_id')
            ->leftJoin('users as tu', 'tu.user_id', '=', 't.user_id')
            ->leftJoin('payments as p', 'p.order_id', '=', 'o.order_id')
            ->leftJoin('payment_methods as pm', 'pm.method_id', '=', 'p.method_id')
            ->leftJoin('reviews as rv', 'rv.order_id', '=', 'o.order_id')
            ->select([
                'o.order_id', 'o.order_code', 'o.status', 'o.scheduled_at', 'o.subtotal',
                'o.transport_fee', 'o.total_price', 'o.completed_at', 'o.cancel_reason',
                'o.service_finished_at', 'rv.review_id',
                'a.label as address_label', 'a.full_address', 'a.city',
                't.technician_id', 'tu.user_id as technician_user_id', 'tu.full_name as technician_name',
                'p.amount as payment_amount', 'p.status as payment_status',
                'pm.name as payment_method', 'pm.type as payment_type',
            ]);
    }

    /** @return array<int, array<int, array>> order_id => daftar layanan */
    private function itemsFor(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }

        $rows = DB::table('order_services as os')
            ->join('services as s', 's.service_id', '=', 'os.service_id')
            ->whereIn('os.order_id', $orderIds)
            ->orderBy('os.order_service_id')
            ->get(['os.order_id', 's.name', 's.est_duration_min', 'os.qty', 'os.price']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->order_id][] = [
                'name'             => $r->name,
                'qty'              => (int) $r->qty,
                'price'            => (float) $r->price,
                'est_duration_min' => $r->est_duration_min === null ? null : (int) $r->est_duration_min,
            ];
        }
        return $out;
    }

    private function present(object $o, array $items): array
    {
        $first = $items[0] ?? null;

        return [
            'order_code'       => $o->order_code,
            'status'           => $o->status,
            'scheduled_at'     => $o->scheduled_at,
            'completed_at'     => $o->completed_at,
            'service_name'     => $first['name'] ?? '-',
            'extra_items'      => max(0, count($items) - 1),
            'items'            => $items,
            'est_duration_min' => $first['est_duration_min'] ?? null,
            'address'          => trim($o->full_address . ', ' . $o->city, ' ,'),
            'address_label'    => $o->address_label,
            'technician_name'  => $o->technician_name,
            'has_technician'   => $o->technician_id !== null,
            'subtotal'         => (float) $o->subtotal,
            'transport_fee'    => (float) $o->transport_fee,
            'total_price'      => (float) $o->total_price,
            'payment_method'   => $o->payment_method,
            'payment_type'     => $o->payment_type,
            'payment_status'   => $o->payment_status,
            'cancel_reason'    => $o->cancel_reason,
            'service_finished_at' => $o->service_finished_at,
            'can_pay'          => $o->status === 'in_progress' && $o->service_finished_at !== null
                && $o->payment_status === 'pending',
            'can_review'       => $o->status === 'completed' && $o->review_id === null && $o->technician_id !== null,
        ];
    }
}
