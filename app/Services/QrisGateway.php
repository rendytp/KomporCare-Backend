<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien QRIS ke Midtrans Core API (sandbox / produksi).
 * Kunci server HANYA dipakai di backend dan tidak pernah dikirim ke aplikasi.
 * Bila MIDTRANS_SERVER_KEY kosong, gateway dianggap mode DEMO (tidak ada panggilan API).
 */
class QrisGateway
{
    public function isDemo(): bool
    {
        return blank(config('services.midtrans.server_key'));
    }

    public function isSandbox(): bool
    {
        return !config('services.midtrans.is_production');
    }

    public function expiryMinutes(): int
    {
        return max(5, (int) config('services.midtrans.expiry_minutes', 15));
    }

    private function base(): string
    {
        return $this->isSandbox() ? 'https://api.sandbox.midtrans.com' : 'https://api.midtrans.com';
    }

    private function http()
    {
        // Basic auth: username = server key, password kosong.
        return Http::withBasicAuth((string) config('services.midtrans.server_key'), '')
            ->acceptJson()->asJson()->timeout(20);
    }

    /**
     * Buat tagihan QRIS dinamis.
     *
     * @param  array<int, array{name:string, price:int, quantity:int}>  $items  jumlah harus sama dengan $amount
     * @return array{qr_string:string, transaction_id:?string, expires_at:Carbon}
     */
    public function charge(string $gatewayOrderId, int $amount, array $customer, array $items): array
    {
        $payload = [
            'payment_type'        => 'qris',
            'transaction_details' => ['order_id' => $gatewayOrderId, 'gross_amount' => $amount],
            'qris'                => ['acquirer' => config('services.midtrans.acquirer', 'gopay')],
            'item_details'        => $items,
            'customer_details'    => array_filter([
                'first_name' => $customer['name'] ?? null,
                'email'      => $customer['email'] ?? null,
                'phone'      => $customer['phone'] ?? null,
            ]),
            'custom_expiry'       => ['expiry_duration' => $this->expiryMinutes(), 'unit' => 'minute'],
        ];

        $res = $this->http()->post($this->base() . '/v2/charge', $payload);
        $body = $res->json() ?? [];

        $code = (string) ($body['status_code'] ?? $res->status());
        if (!in_array($code, ['200', '201'], true) || empty($body['qr_string'])) {
            throw new RuntimeException($body['status_message'] ?? 'Gateway pembayaran tidak merespons dengan benar.');
        }

        return [
            'qr_string'      => $body['qr_string'],
            'transaction_id' => $body['transaction_id'] ?? null,
            'expires_at'     => now()->addMinutes($this->expiryMinutes()),
        ];
    }

    /** Status transaksi dari gateway: pending | paid | expired | failed | unknown */
    public function status(string $gatewayOrderId): string
    {
        $res = $this->http()->get($this->base() . '/v2/' . rawurlencode($gatewayOrderId) . '/status');
        $body = $res->json() ?? [];

        // 404 = transaksi belum dikenal gateway
        if ((string) ($body['status_code'] ?? '') === '404') {
            return 'unknown';
        }

        return self::mapStatus((string) ($body['transaction_status'] ?? ''), $body['fraud_status'] ?? null);
    }

    public static function mapStatus(string $transactionStatus, ?string $fraudStatus = null): string
    {
        return match ($transactionStatus) {
            'settlement' => 'paid',
            'capture'    => ($fraudStatus === null || $fraudStatus === 'accept') ? 'paid' : 'failed',
            'pending'    => 'pending',
            'expire'     => 'expired',
            'cancel', 'deny', 'failure', 'refund', 'partial_refund', 'chargeback' => 'failed',
            default      => 'unknown',
        };
    }

    /** Verifikasi tanda tangan notifikasi: SHA512(order_id + status_code + gross_amount + server_key). */
    public function verifySignature(array $p): bool
    {
        if ($this->isDemo()) {
            return false;
        }
        $expected = hash('sha512',
            ($p['order_id'] ?? '') . ($p['status_code'] ?? '') . ($p['gross_amount'] ?? '')
            . config('services.midtrans.server_key'));

        return hash_equals($expected, (string) ($p['signature_key'] ?? ''));
    }
}
