<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pembayaran setelah servis selesai (Tunai / QRIS).
 *
 * GET  /api/customer/orders/{code}/payment   tagihan + metode + QR (demo)
 * POST /api/customer/orders/{code}/pay       {method: cash|qris}
 *
 * QRIS  : dianggap lunas (DEMO, tidak terhubung ke payment gateway), pesanan selesai.
 * Tunai : metode dicatat; pesanan selesai setelah TEKNISI mengonfirmasi uang diterima
 *         (stored procedure sp_confirm_cash_payment).
 */
class PaymentController extends Controller
{
    private const COMMISSION = 0.10;

    public function show(Request $request, string $code): JsonResponse
    {
        $user = $this->customer($request);
        [$o, $items] = $this->load($user->user_id, $code);

        $methods = DB::table('payment_methods')->whereIn('type', ['cash', 'qris'])->where('is_active', 1)
            ->orderBy('method_id')->get(['method_id', 'type', 'name', 'admin_fee']);

        $lines = [];
        foreach ($items as $i) {
            $lines[] = [
                'label'  => $i->name . ($i->qty > 1 ? " ×{$i->qty}" : ''),
                'amount' => (float) $i->price * (int) $i->qty,
            ];
        }
        if ((float) $o->transport_fee > 0) {
            $lines[] = ['label' => 'Biaya kunjungan', 'amount' => (float) $o->transport_fee];
        }

        return response()->json([
            'order_code'      => $o->order_code,
            'status'          => $o->status,
            'customer_name'   => $user->full_name,
            'technician_name' => $o->tech_name,
            'finished_at'     => $o->service_finished_at,
            'lines'           => $lines,
            'total'           => (float) $o->total_price,
            'payment_status'  => $o->payment_status,
            'payment_type'    => $o->payment_type,
            'can_pay'         => $this->canPay($o),
            'methods'         => $methods->map(fn ($m) => [
                'method_id' => (int) $m->method_id, 'type' => $m->type, 'name' => $m->name,
            ])->values(),
            // Isi QR DEMO: tidak dapat dibayar lewat aplikasi bank/e-wallet mana pun.
            'qr_string'       => 'KOMPORCARE-DEMO|' . $o->order_code . '|' . (int) $o->total_price,
        ]);
    }

    public function pay(Request $request, string $code): JsonResponse
    {
        $user = $this->customer($request);
        $data = $request->validate(['method' => ['required', 'in:cash,qris']]);

        $result = DB::transaction(function () use ($user, $code, $data) {
            $o = DB::table('orders as o')
                ->leftJoin('technicians as t', 't.technician_id', '=', 'o.technician_id')
                ->where('o.customer_id', $user->user_id)->where('o.order_code', $code)
                ->lockForUpdate()
                ->first(['o.order_id', 'o.status', 'o.total_price', 'o.service_finished_at',
                    'o.technician_id', 't.user_id as tech_user_id']);
            abort_if(!$o, 404, 'Pesanan tidak ditemukan.');

            $p = DB::table('payments')->where('order_id', $o->order_id)->lockForUpdate()->first();
            abort_if(!$p || $p->status !== 'pending', 422, 'Tagihan sudah dibayar atau tidak tersedia.');
            abort_unless($o->status === 'in_progress' && $o->service_finished_at !== null, 422,
                'Pembayaran baru dapat dilakukan setelah servis selesai.');

            $method = DB::table('payment_methods')->where('type', $data['method'])->where('is_active', 1)->first();
            abort_if(!$method, 422, 'Metode pembayaran tidak tersedia.');

            $total = (float) $o->total_price;

            if ($data['method'] === 'cash') {
                DB::table('payments')->where('payment_id', $p->payment_id)
                    ->update(['method_id' => $method->method_id, 'amount' => $total]);

                if ($o->tech_user_id) {
                    DB::table('notifications')->insert([
                        'user_id' => $o->tech_user_id, 'title' => 'Pembayaran tunai',
                        'message' => 'Pelanggan memilih bayar tunai Rp' . number_format($total, 0, ',', '.')
                            . ' untuk pesanan ' . $code . '. Konfirmasi setelah uang diterima.',
                        'created_at' => now(),
                    ]);
                }

                return ['message' => 'Pembayaran tunai dicatat. Serahkan uang ke teknisi.', 'completed' => false];
            }

            // QRIS (DEMO): langsung lunas
            $fee = (float) $method->admin_fee;
            DB::table('payments')->where('payment_id', $p->payment_id)->update([
                'method_id' => $method->method_id, 'amount' => $total + $fee, 'status' => 'paid',
                'paid_at' => now(), 'reference_no' => 'DEMO-' . strtoupper(substr(md5($code . now()), 0, 10)),
            ]);
            DB::table('orders')->where('order_id', $o->order_id)
                ->update(['status' => 'completed', 'completed_at' => now()]);
            DB::table('order_status_history')->insert([
                'order_id' => $o->order_id, 'status' => 'completed',
                'note' => 'Dibayar via QRIS (demo)', 'changed_at' => now(),
            ]);

            if ($o->technician_id) {
                // Platform menerima uang -> teknisi mendapat penghasilan bersih setelah komisi.
                DB::table('technician_wallet_transactions')->insert([
                    'technician_id' => $o->technician_id, 'order_id' => $o->order_id, 'type' => 'earning',
                    'amount' => round($total * (1 - self::COMMISSION), 2), 'created_at' => now(),
                ]);
                DB::table('technicians')->where('technician_id', $o->technician_id)->increment('total_jobs');
                DB::table('notifications')->insert([
                    'user_id' => $o->tech_user_id, 'title' => 'Pembayaran diterima',
                    'message' => 'Pesanan ' . $code . ' sudah dibayar via QRIS.', 'created_at' => now(),
                ]);
            }
            DB::table('audit_logs')->insert([
                'user_id' => $user->user_id, 'event_type' => 'payment_confirmed',
                'entity' => 'orders', 'entity_id' => $o->order_id,
            ]);

            return ['message' => 'Pembayaran berhasil (demo).', 'completed' => true];
        });

        return response()->json($result);
    }

    /* ----------------------------- helper ----------------------------- */

    private function canPay(object $o): bool
    {
        return $o->status === 'in_progress' && $o->service_finished_at !== null && $o->payment_status === 'pending';
    }

    private function load(int $userId, string $code): array
    {
        $o = DB::table('orders as o')
            ->leftJoin('technicians as t', 't.technician_id', '=', 'o.technician_id')
            ->leftJoin('users as tu', 'tu.user_id', '=', 't.user_id')
            ->leftJoin('payments as p', 'p.order_id', '=', 'o.order_id')
            ->leftJoin('payment_methods as pm', 'pm.method_id', '=', 'p.method_id')
            ->where('o.customer_id', $userId)->where('o.order_code', $code)
            ->first(['o.order_id', 'o.order_code', 'o.status', 'o.total_price', 'o.transport_fee',
                'o.service_finished_at', 'tu.full_name as tech_name',
                'p.status as payment_status', 'pm.type as payment_type']);
        abort_if(!$o, 404, 'Pesanan tidak ditemukan.');

        $items = DB::table('order_services as os')->join('services as s', 's.service_id', '=', 'os.service_id')
            ->where('os.order_id', $o->order_id)->orderBy('os.order_service_id')
            ->get(['s.name', 'os.qty', 'os.price']);

        return [$o, $items];
    }

    private function customer(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role === 'customer', 403, 'Halaman ini khusus Pelanggan.');
        return $user;
    }
}
