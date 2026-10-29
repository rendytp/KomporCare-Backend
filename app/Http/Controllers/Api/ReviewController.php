<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ulasan setelah servis selesai.
 * GET  /api/customer/orders/{code}/review
 * POST /api/customer/orders/{code}/review   {rating 1-5, tags[], comment}
 */
class ReviewController extends Controller
{
    public const TAGS = ['Tepat waktu', 'Biaya transparan', 'Hasil rapi', 'Komunikatif'];

    public function show(Request $request, string $code): JsonResponse
    {
        $user = $this->customer($request);
        $o = $this->order($user->user_id, $code);

        $review = DB::table('reviews')->where('order_id', $o->order_id)->first();

        return response()->json([
            'order_code'      => $o->order_code,
            'status'          => $o->status,
            'service_name'    => $o->service_name,
            'completed_at'    => $o->completed_at,
            'total_price'     => (float) $o->total_price,
            'technician_name' => $o->tech_name,
            'available_tags'  => self::TAGS,
            'can_review'      => $o->status === 'completed' && !$review,
            'review'          => $review ? [
                'rating'  => (int) $review->rating,
                'comment' => $review->comment,
                'tags'    => $review->tags ? explode(',', $review->tags) : [],
            ] : null,
        ]);
    }

    public function store(Request $request, string $code): JsonResponse
    {
        $user = $this->customer($request);
        $o = $this->order($user->user_id, $code);

        abort_unless($o->status === 'completed', 422, 'Ulasan dapat diberikan setelah pesanan selesai.');
        abort_if(!$o->technician_id, 422, 'Pesanan ini tidak memiliki teknisi.');
        abort_if(DB::table('reviews')->where('order_id', $o->order_id)->exists(), 422, 'Pesanan ini sudah diulas.');

        $data = $request->validate([
            'rating'  => ['required', 'integer', 'between:1,5'],
            'tags'    => ['nullable', 'array', 'max:4'],
            'tags.*'  => ['string', 'in:' . implode(',', self::TAGS)],
            'comment' => ['nullable', 'string', 'max:500'],
        ], [
            'rating.required' => 'Pilih nilai bintang.',
            'rating.between'  => 'Nilai harus 1 sampai 5.',
        ]);

        DB::transaction(function () use ($o, $user, $data) {
            DB::table('reviews')->insert([
                'order_id'      => $o->order_id,
                'customer_id'   => $user->user_id,
                'technician_id' => $o->technician_id,
                'rating'        => $data['rating'],
                'comment'       => isset($data['comment']) ? trim($data['comment']) ?: null : null,
                'tags'          => empty($data['tags']) ? null : implode(',', $data['tags']),
                'created_at'    => now(),
            ]);

            // Rating teknisi = rata-rata seluruh ulasannya (dipakai oleh pencocokan teknisi).
            $avg = DB::table('reviews')->where('technician_id', $o->technician_id)->avg('rating');
            DB::table('technicians')->where('technician_id', $o->technician_id)
                ->update(['rating_avg' => round((float) $avg, 2)]);

            DB::table('notifications')->insert([
                'user_id' => $o->tech_user_id, 'title' => 'Ulasan baru',
                'message' => 'Anda mendapat bintang ' . $data['rating'] . ' untuk pesanan ' . $o->order_code . '.',
                'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Terima kasih atas ulasan Anda.'], 201);
    }

    private function order(int $userId, string $code): object
    {
        $o = DB::table('orders as o')
            ->join('order_services as os', 'os.order_id', '=', 'o.order_id')
            ->join('services as s', 's.service_id', '=', 'os.service_id')
            ->leftJoin('technicians as t', 't.technician_id', '=', 'o.technician_id')
            ->leftJoin('users as tu', 'tu.user_id', '=', 't.user_id')
            ->where('o.customer_id', $userId)->where('o.order_code', $code)
            ->orderBy('os.order_service_id')
            ->first(['o.order_id', 'o.order_code', 'o.status', 'o.total_price', 'o.completed_at',
                'o.technician_id', 's.name as service_name', 'tu.full_name as tech_name', 'tu.user_id as tech_user_id']);
        abort_if(!$o, 404, 'Pesanan tidak ditemukan.');
        return $o;
    }

    private function customer(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role === 'customer', 403, 'Halaman ini khusus Pelanggan.');
        return $user;
    }
}
