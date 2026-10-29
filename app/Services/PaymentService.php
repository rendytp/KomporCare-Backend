<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public const COMMISSION = 0.10;

    /**
     * Tandai pembayaran QRIS lunas dan selesaikan pesanan. Aman dipanggil berulang
     * (webhook + polling bisa datang bersamaan): hanya diproses sekali.
     *
     * @return string 'paid' (baru diproses) | 'already' (sudah lunas) | 'ignored'
     */
    public function markQrisPaid(int $orderId, ?string $reference = null, ?int $actorUserId = null): string
    {
        return DB::transaction(function () use ($orderId, $reference, $actorUserId) {
            $o = DB::table('orders as o')
                ->leftJoin('technicians as t', 't.technician_id', '=', 'o.technician_id')
                ->where('o.order_id', $orderId)->lockForUpdate()
                ->first(['o.order_id', 'o.order_code', 'o.customer_id', 'o.status', 'o.total_price',
                    'o.technician_id', 't.user_id as tech_user_id']);
            if (!$o) {
                return 'ignored';
            }

            $p = DB::table('payments')->where('order_id', $orderId)->lockForUpdate()->first();
            if (!$p) {
                return 'ignored';
            }
            if ($p->status === 'paid') {
                return 'already';
            }
            if ($p->status !== 'pending') {
                // Uang masuk tetapi tagihan sudah gagal/dibatalkan: perlu ditangani manual (refund).
                Log::warning('QRIS dibayar untuk tagihan non-pending', ['order' => $o->order_code, 'status' => $p->status]);
                return 'ignored';
            }

            $methodId = DB::table('payment_methods')->where('type', 'qris')->value('method_id');

            DB::table('payments')->where('payment_id', $p->payment_id)->update([
                'method_id'    => $methodId ?? $p->method_id,
                'status'       => 'paid',
                'paid_at'      => now(),
                'reference_no' => $reference ?? $p->reference_no,
            ]);

            if ($o->status === 'in_progress') {
                DB::table('orders')->where('order_id', $orderId)
                    ->update(['status' => 'completed', 'completed_at' => now()]);
                DB::table('order_status_history')->insert([
                    'order_id' => $orderId, 'status' => 'completed',
                    'note' => 'Dibayar via QRIS', 'changed_at' => now(),
                ]);
            }

            if ($o->technician_id) {
                // Platform menerima uang -> teknisi mendapat penghasilan bersih setelah komisi.
                DB::table('technician_wallet_transactions')->insert([
                    'technician_id' => $o->technician_id, 'order_id' => $orderId, 'type' => 'earning',
                    'amount' => round((float) $o->total_price * (1 - self::COMMISSION), 2), 'created_at' => now(),
                ]);
                DB::table('technicians')->where('technician_id', $o->technician_id)->increment('total_jobs');
                DB::table('notifications')->insert([
                    'user_id' => $o->tech_user_id, 'title' => 'Pembayaran diterima',
                    'message' => 'Pesanan ' . $o->order_code . ' sudah dibayar via QRIS.', 'created_at' => now(),
                ]);
            }

            DB::table('notifications')->insert([
                'user_id' => $o->customer_id, 'title' => 'Pembayaran berhasil',
                'message' => 'Pembayaran QRIS untuk pesanan ' . $o->order_code . ' berhasil.', 'created_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'user_id' => $actorUserId ?? $o->customer_id, 'event_type' => 'payment_confirmed',
                'entity' => 'orders', 'entity_id' => $orderId,
            ]);

            return 'paid';
        });
    }
}
