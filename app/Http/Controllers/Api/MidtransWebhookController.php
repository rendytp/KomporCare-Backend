<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Services\QrisGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/payments/midtrans/notification  (publik, dipanggil server Midtrans)
 * Diatur di Dashboard Midtrans > Settings > Configuration > Payment Notification URL.
 * Keaslian dijamin oleh signature_key; aplikasi tetap bisa mendapat status lewat polling
 * meski webhook tidak terjangkau (mis. server lokal).
 */
class MidtransWebhookController extends Controller
{
    public function __invoke(Request $request, QrisGateway $gateway, PaymentService $payments): JsonResponse
    {
        $p = $request->all();

        if (!$gateway->verifySignature($p)) {
            Log::warning('Notifikasi Midtrans dengan signature tidak valid', ['order_id' => $p['order_id'] ?? null]);
            return response()->json(['message' => 'Signature tidak valid.'], 403);
        }

        $payment = DB::table('payments')->where('reference_no', $p['order_id'] ?? '')->first();
        if (!$payment) {
            // mis. notifikasi uji dari dashboard Midtrans
            return response()->json(['message' => 'ignored']);
        }

        // Nominal harus sama dengan tagihan kita.
        if ((int) round((float) ($p['gross_amount'] ?? 0)) !== (int) round((float) $payment->amount)) {
            Log::warning('Nominal notifikasi Midtrans tidak cocok', ['order_id' => $p['order_id']]);
            return response()->json(['message' => 'ignored']);
        }

        $status = QrisGateway::mapStatus((string) ($p['transaction_status'] ?? ''), $p['fraud_status'] ?? null);

        if ($status === 'paid') {
            $payments->markQrisPaid((int) $payment->order_id, $p['order_id']);
        } elseif (in_array($status, ['expired', 'failed'], true) && $payment->status === 'pending') {
            DB::table('payments')->where('payment_id', $payment->payment_id)
                ->update(['expired_at' => now()->subSecond()]);
        }

        return response()->json(['message' => 'ok']);
    }
}
