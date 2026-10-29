<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Memperbaiki pesanan yang statusnya diubah manual lewat SQL menjadi 'completed'
 * padahal belum dibayar, sehingga bisa dibayar lewat aplikasi.
 *
 *   php artisan demo:reset ORD-XXXXXXXX-XXXXXX
 *
 * Pesanan yang pembayarannya sudah 'paid' TIDAK disentuh.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset {code}';
    protected $description = 'Kembalikan pesanan belum dibayar ke tahap "servis selesai, menunggu pembayaran"';

    public function handle(): int
    {
        $o = DB::table('orders as o')
            ->join('payments as p', 'p.order_id', '=', 'o.order_id')
            ->where('o.order_code', $this->argument('code'))
            ->first(['o.order_id', 'o.technician_id', 'p.payment_id', 'p.status as pay_status']);

        if (!$o) {
            $this->error('Pesanan tidak ditemukan.');
            return self::FAILURE;
        }
        if ($o->pay_status === 'paid') {
            $this->error('Pesanan sudah dibayar. Tidak diubah.');
            return self::FAILURE;
        }
        if (!$o->technician_id) {
            $this->warn('Pesanan belum punya teknisi. Pembayaran bisa dibuka, tetapi ulasan butuh teknisi.');
        }

        DB::transaction(function () use ($o) {
            DB::table('orders')->where('order_id', $o->order_id)->update([
                'status' => 'in_progress', 'completed_at' => null, 'service_finished_at' => now(), 'cancel_reason' => null,
            ]);
            DB::table('payments')->where('payment_id', $o->payment_id)
                ->update(['status' => 'pending', 'paid_at' => null]);
        });

        $this->info('Pesanan siap dibayar. Buka tab Pesanan lalu tekan "Bayar sekarang".');
        return self::SUCCESS;
    }
}
