<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data beranda pelanggan.
 * GET /api/customer/home
 */
class CustomerController extends Controller
{
    /** Status pesanan yang dianggap masih berjalan. */
    private const ACTIVE_STATUSES = ['waiting_payment', 'pending', 'accepted', 'on_the_way', 'in_progress'];

    public function home(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'customer', 403, 'Halaman ini khusus Pelanggan.');

        // Alamat utama (is_default) untuk label lokasi di header.
        $address = DB::table('addresses')
            ->where('user_id', $user->user_id)
            ->orderByDesc('is_default')
            ->orderBy('address_id')
            ->first(['label', 'full_address', 'city']);

        // 4 layanan aktif pertama untuk grid "Pilih layanan".
        $services = DB::table('services as s')
            ->join('service_categories as c', 'c.category_id', '=', 's.category_id')
            ->where('s.is_active', 1)
            ->orderBy('s.service_id')
            ->limit(4)
            ->get(ServiceController::columns());

        // Kunjungan terdekat yang belum selesai.
        $order = DB::table('orders as o')
            ->join('order_services as os', 'os.order_id', '=', 'o.order_id')
            ->join('services as s', 's.service_id', '=', 'os.service_id')
            ->leftJoin('technicians as t', 't.technician_id', '=', 'o.technician_id')
            ->leftJoin('users as tu', 'tu.user_id', '=', 't.user_id')
            ->leftJoin('payments as p', 'p.order_id', '=', 'o.order_id')
            ->leftJoin('payment_methods as pm', 'pm.method_id', '=', 'p.method_id')
            ->where('o.customer_id', $user->user_id)
            ->whereIn('o.status', self::ACTIVE_STATUSES)
            ->orderBy('o.scheduled_at')
            ->orderBy('os.order_service_id')
            ->first([
                'o.order_code', 'o.status', 'o.scheduled_at', 'o.total_price',
                's.name as service_name', 's.est_duration_min',
                'tu.full_name as technician_name',
                'pm.name as payment_method', 'pm.type as payment_type',
                'p.status as payment_status',
            ]);

        $unread = DB::table('notifications')
            ->where('user_id', $user->user_id)
            ->where('is_read', 0)
            ->count();

        return response()->json([
            'address' => $address ? [
                'label'        => $address->label,
                'full_address' => $address->full_address,
                'city'         => $address->city,
            ] : null,
            'services'     => $services->map(fn ($s) => ServiceController::present($s))->values(),
            'active_order' => $order ? [
                'order_code'      => $order->order_code,
                'status'          => $order->status,
                'scheduled_at'    => $order->scheduled_at,
                'total_price'     => (float) $order->total_price,
                'service_name'    => $order->service_name,
                'est_duration_min' => $order->est_duration_min === null ? null : (int) $order->est_duration_min,
                'technician_name' => $order->technician_name,
                'payment_method'  => $order->payment_method,
                'payment_type'    => $order->payment_type,
                'payment_status'  => $order->payment_status,
            ] : null,
            'unread_notifications' => $unread,
        ]);
    }
}
