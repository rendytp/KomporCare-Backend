<?php

namespace App\Console\Commands;

use App\Services\TechnicianMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Simulasi sisi TEKNISI untuk demo (aplikasi teknisi belum ada).
 *
 *   php artisan demo:advance KC-XXXX            maju satu tahap
 *   php artisan demo:advance KC-XXXX --move     geser teknisi 40% lebih dekat ke pelanggan
 *   php artisan demo:advance KC-XXXX --finish  langsung sampai "servis selesai" (siap dibayar)
 *   php artisan demo:advance KC-XXXX --pay-cash teknisi mengonfirmasi uang tunai diterima
 *
 * Tahap: accepted -> on_the_way -> in_progress -> servis selesai (pelanggan bisa membayar)
 */
class DemoAdvance extends Command
{
    protected $signature = 'demo:advance {code} {--move} {--pay-cash} {--finish}';
    protected $description = 'Simulasi aksi teknisi untuk demo pelacakan, pembayaran, dan ulasan';

    public function handle(): int
    {
        $o = DB::table('orders as o')
            ->join('addresses as a', 'a.address_id', '=', 'o.address_id')
            ->leftJoin('technicians as t', 't.technician_id', '=', 'o.technician_id')
            ->where('o.order_code', $this->argument('code'))
            ->first(['o.order_id', 'o.customer_id', 'o.status', 'o.technician_id', 'o.service_finished_at',
                'a.latitude as dlat', 'a.longitude as dlng', 't.current_lat as tlat', 't.current_lng as tlng']);

        if (!$o) {
            $this->error('Pesanan tidak ditemukan.');
            return self::FAILURE;
        }
        if (!$o->technician_id) {
            $this->error('Pesanan belum memiliki teknisi.');
            return self::FAILURE;
        }

        if ($this->option('finish')) {
            // accepted -> on_the_way -> in_progress -> servis selesai (3 langkah)
            for ($i = 0; $i < 3; $i++) {
                $this->callSilent('demo:advance', ['code' => $this->argument('code')]);
            }
            $status = DB::table('orders')->where('order_id', $o->order_id)->first(['status', 'service_finished_at']);
            $this->info('Status: ' . $status->status . ($status->service_finished_at ? ' | servis selesai, siap dibayar' : ''));
            return self::SUCCESS;
        }

        if ($this->option('pay-cash')) {
            try {
                DB::select('CALL sp_confirm_cash_payment(?, ?)', [$o->order_id, $o->technician_id]);
                $this->info('Tunai dikonfirmasi teknisi. Pesanan selesai.');
                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }
        }

        if ($this->option('move')) {
            if ($o->dlat === null || $o->tlat === null) {
                $this->error('Butuh pin lokasi pelanggan dan lokasi teknisi.');
                return self::FAILURE;
            }
            $lat = $o->tlat + ($o->dlat - $o->tlat) * 0.4;
            $lng = $o->tlng + ($o->dlng - $o->tlng) * 0.4;
            DB::table('technicians')->where('technician_id', $o->technician_id)
                ->update(['current_lat' => $lat, 'current_lng' => $lng]);
            $this->info(sprintf('Teknisi digeser ke %.5f, %.5f', $lat, $lng));
            return self::SUCCESS;
        }

        switch ($o->status) {
            case 'accepted':
                if ($o->tlat === null && $o->dlat !== null) {
                    // belum punya lokasi: tempatkan ~3 km dari pelanggan
                    DB::table('technicians')->where('technician_id', $o->technician_id)
                        ->update(['current_lat' => $o->dlat + 0.02, 'current_lng' => $o->dlng + 0.015]);
                }
                $this->setStatus($o, 'on_the_way', 'Teknisi berangkat menuju lokasi');
                $this->notify($o->customer_id, 'Teknisi berangkat', 'Teknisi sedang menuju lokasi Anda.');
                $this->info('Status: on_the_way');
                break;

            case 'on_the_way':
                if ($o->dlat !== null) {
                    DB::table('technicians')->where('technician_id', $o->technician_id)
                        ->update(['current_lat' => $o->dlat + 0.0003, 'current_lng' => $o->dlng + 0.0003]);
                }
                $this->setStatus($o, 'in_progress', 'Servis dimulai');
                $this->notify($o->customer_id, 'Servis dimulai', 'Teknisi tiba dan mulai mengerjakan kompor Anda.');
                $this->info('Status: in_progress');
                break;

            case 'in_progress':
                if ($o->service_finished_at !== null) {
                    $this->warn('Servis sudah selesai. Menunggu pelanggan membayar.');
                    break;
                }
                DB::table('orders')->where('order_id', $o->order_id)->update(['service_finished_at' => now()]);
                $this->notify($o->customer_id, 'Servis selesai', 'Servis selesai. Silakan lakukan pembayaran.');
                $this->info('Servis selesai. Pelanggan dapat membayar (Tunai/QRIS).');
                break;

            default:
                $this->warn('Tidak ada tahap berikutnya untuk status: ' . $o->status);
        }

        return self::SUCCESS;
    }

    private function setStatus(object $o, string $status, string $note): void
    {
        DB::table('orders')->where('order_id', $o->order_id)->update(['status' => $status]);
        DB::table('order_status_history')->insert([
            'order_id' => $o->order_id, 'status' => $status, 'note' => $note, 'changed_at' => now(),
        ]);
    }

    private function notify(int $userId, string $title, string $message): void
    {
        DB::table('notifications')->insert([
            'user_id' => $userId, 'title' => $title, 'message' => $message, 'created_at' => now(),
        ]);
    }
}
