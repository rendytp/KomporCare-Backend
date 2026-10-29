<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Services\QrisGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pembayaran setelah servis selesai (Tunai / QRIS).
 *
 * GET  /api/customer/orders/{code}/payment       tagihan + metode
 * POST /api/customer/orders/{code}/pay           {method: cash}  (QRIS demo: {method: qris})
 * POST /api/customer/orders/{code}/qris          buat / ambil kode QRIS (Midtrans)
 * GET  /api/customer/orders/{code}/qris/status   cek status pembayaran QRIS
 *
 * QRIS : kode dibuat lewat Midtrans Core API. Lunas bila gateway melapor "settlement"
 *        (lewat webhook atau pengecekan status). Tanpa MIDTRANS_SERVER_KEY berjalan mode DEMO.
 * Tunai: metode dicatat; pesanan selesai setelah TEKNISI mengonfirmasi uang diterima
 *        (stored procedure sp_confirm_cash_payment).
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly QrisGateway $gateway,
        private readonly PaymentService $payments,
    ) {
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $user = $this->customer($request);
        [$o, $items] = $this->load($user->user_id, $code);

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
            'qris_demo'       => $this->gateway->isDemo(),
            'qr_string'       => '',
        ]);
    }

    /** Tunai, atau QRIS pada mode demo. */
    public function pay(Request $request, string $code): JsonResponse
    {
        $user = $this->customer($request);
        $data = $request->validate(['method' => ['required', 'in:cash,qris']]);

        [$o] = $this->load($user->user_id, $code);
        abort_unless($this->canPay($o), 422, 'Pembayaran baru dapat dilakukan setelah servis selesai.');

        if ($data['method'] === 'qris') {
            abort_unless($this->gateway->isDemo(), 422, 'Gunakan halaman QRIS untuk membayar.');
            $this->payments->markQrisPaid((int) $o->order_id, 'DEMO-' . strtoupper(Str::random(8)), $user->user_id);

            return response()->json(['message' => 'Pembayaran berhasil (demo).', 'completed' => true]);
        }

        $result = DB::transaction(function () use ($o) {
            $p = DB::table('payments')->where('order_id', $o->order_id)->lockForUpdate()->first();
            abort_if(!$p || $p->status !== 'pending', 422, 'Tagihan sudah dibayar atau tidak tersedia.');

            $cashId = DB::table('payment_methods')->where('type', 'cash')->where('is_active', 1)->value('method_id');
            abort_if(!$cashId, 422, 'Metode pembayaran tidak tersedia.');

            DB::table('payments')->where('payment_id', $p->payment_id)->update([
                'method_id' => $cashId, 'amount' => $o->total_price, 'qr_string' => null, 'expired_at' => null,
            ]);

            if ($o->tech_user_id) {
                DB::table('notifications')->insert([
                    'user_id' => $o->tech_user_id, 'title' => 'Pembayaran tunai',
                    'message' => 'Pelanggan memilih bayar tunai Rp' . number_format((float) $o->total_price, 0, ',', '.')
                        . ' untuk pesanan ' . $o->order_code . '. Konfirmasi setelah uang diterima.',
                    'created_at' => now(),
                ]);
            }

            return true;
        });

        return response()->json([
            'message'   => 'Pembayaran tunai dicatat. Serahkan uang ke teknisi.',
            'completed' => false,
        ]);
    }

    /** Buat kode QRIS (atau pakai ulang yang masih berlaku). */
    public function qris(Request $request, string $code): JsonResponse
    {
        $user = $this->customer($request);
        [$o, $items] = $this->load($user->user_id, $code);
        abort_unless($this->canPay($o), 422, 'Pembayaran baru dapat dilakukan setelah servis selesai.');

        $method = DB::table('payment_methods')->where('type', 'qris')->where('is_active', 1)->first();
        abort_if(!$method, 422, 'QRIS belum tersedia.');

        $p = DB::table('payments')->where('order_id', $o->order_id)->first();
        $amount = (float) $o->total_price + (float) $method->admin_fee;

        // Mode demo: tanpa gateway.
        if ($this->gateway->isDemo()) {
            return response()->json($this->payload('demo', $amount, 'KOMPORCARE-DEMO|' . $o->order_code . '|' . (int) $amount,
                $this->gateway->expiryMinutes() * 60));
        }

        // Pakai ulang kode yang masih berlaku supaya pelanggan tidak menerima banyak kode.
        if ($p->qr_string && $p->reference_no && $p->expired_at && now()->lt($p->expired_at)
            && (int) $p->method_id === (int) $method->method_id) {
            return response()->json($this->payload('midtrans', (float) $p->amount, $p->qr_string,
                (int) now()->diffInSeconds($p->expired_at, false)));
        }

        $gatewayOrderId = $o->order_code . '-' . strtoupper(Str::random(5)); // unik per percobaan (maks 50 karakter)
        $intAmount = (int) round($amount);

        $lines = [];
        $sum = 0;
        foreach ($items as $i) {
            $price = (int) round((float) $i->price);
            $lines[] = ['id' => 'svc', 'name' => mb_substr($i->name, 0, 50), 'price' => $price, 'quantity' => (int) $i->qty];
            $sum += $price * (int) $i->qty;
        }
        if ((float) $o->transport_fee > 0) {
            $fee = (int) round((float) $o->transport_fee);
            $lines[] = ['id' => 'visit', 'name' => 'Biaya kunjungan', 'price' => $fee, 'quantity' => 1];
            $sum += $fee;
        }
        if ((float) $method->admin_fee > 0) {
            $fee = (int) round((float) $method->admin_fee);
            $lines[] = ['id' => 'admin', 'name' => 'Biaya layanan QRIS', 'price' => $fee, 'quantity' => 1];
            $sum += $fee;
        }
        if ($sum !== $intAmount) { // item_details wajib sama dengan gross_amount
            $lines = [['id' => 'order', 'name' => 'Servis KomporCare ' . $o->order_code, 'price' => $intAmount, 'quantity' => 1]];
        }

        try {
            $res = $this->gateway->charge($gatewayOrderId, $intAmount, [
                'name' => $user->full_name, 'email' => $user->email, 'phone' => $user->phone,
            ], $lines);
        } catch (RuntimeException $e) {
            report($e);
            return response()->json(['message' => 'Gagal membuat kode QRIS: ' . $e->getMessage()], 502);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Gateway pembayaran tidak dapat dihubungi. Coba lagi.'], 502);
        }

        DB::table('payments')->where('payment_id', $p->payment_id)->update([
            'method_id'    => $method->method_id,
            'amount'       => $amount,
            'reference_no' => $gatewayOrderId,
            'qr_string'    => $res['qr_string'],
            'expired_at'   => $res['expires_at'],
        ]);

        return response()->json($this->payload('midtrans', $amount, $res['qr_string'], $this->gateway->expiryMinutes() * 60));
    }

    /** Dipanggil aplikasi tiap beberapa detik selama kode QRIS ditampilkan. */
    public function qrisStatus(Request $request, string $code): JsonResponse
    {
        $user = $this->customer($request);
        [$o] = $this->load($user->user_id, $code);

        $p = DB::table('payments')->where('order_id', $o->order_id)->first();
        $respond = fn (string $status, int $left = 0) => response()->json([
            'status' => $status, 'order_status' => $o->status, 'seconds_left' => max(0, $left),
        ]);

        if ($p->status === 'paid') {
            return response()->json(['status' => 'paid', 'order_status' => 'completed', 'seconds_left' => 0]);
        }
        if ($this->gateway->isDemo() || !$p->reference_no || !$p->qr_string) {
            return $respond('pending');
        }

        $left = $p->expired_at ? (int) now()->diffInSeconds($p->expired_at, false) : 0;

        try {
            $status = $this->gateway->status($p->reference_no);
        } catch (\Throwable $e) {
            report($e);
            return $respond($left > 0 ? 'pending' : 'expired', $left); // gateway sementara tak terjangkau
        }

        if ($status === 'paid') {
            $this->payments->markQrisPaid((int) $o->order_id, $p->reference_no, $user->user_id);
            return response()->json(['status' => 'paid', 'order_status' => 'completed', 'seconds_left' => 0]);
        }
        if (in_array($status, ['expired', 'failed'], true) || $left <= 0) {
            // Matikan kode lama supaya percobaan berikutnya membuat kode baru.
            DB::table('payments')->where('payment_id', $p->payment_id)->update(['expired_at' => now()->subSecond()]);
            return $respond($status === 'failed' ? 'failed' : 'expired');
        }

        return $respond('pending', $left);
    }

    /* ----------------------------- helper ----------------------------- */

    private function payload(string $provider, float $amount, string $qr, int $secondsLeft): array
    {
        return [
            'provider'     => $provider,            // midtrans | demo
            'sandbox'      => $provider === 'midtrans' && $this->gateway->isSandbox(),
            'qr_string'    => $qr,
            'amount'       => $amount,
            'seconds_left' => max(0, $secondsLeft),
            'status'       => 'pending',
        ];
    }

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
                'o.service_finished_at', 'tu.full_name as tech_name', 'tu.user_id as tech_user_id',
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
