<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Halaman "Pesan" pelanggan: chat dengan teknisi (per pesanan) dan chat dengan CS KomporCare.
 *
 * GET  /api/customer/conversations                 daftar percakapan (?q=)
 * POST /api/customer/conversations/support         buka / ambil percakapan CS
 * POST /api/customer/orders/{code}/chat            buka / ambil percakapan dengan teknisi pesanan
 * GET  /api/customer/conversations/{id}            detail + pesan (menandai pesan masuk terbaca)
 * POST /api/customer/conversations/{id}/messages   kirim pesan
 */
class ChatController extends Controller
{
    private const SUPPORT_WELCOME =
        'Halo! Saya tim Bantuan KomporCare. Ada yang bisa kami bantu terkait pesanan, pembayaran, atau akun Anda?';

    public function index(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $q = trim((string) $request->query('q', ''));

        $convs = DB::table('conversations as c')
            ->leftJoin('users as tu', 'tu.user_id', '=', 'c.technician_user_id')
            ->leftJoin('orders as o', 'o.order_id', '=', 'c.order_id')
            ->where('c.customer_id', $user->user_id)
            ->orderByDesc(DB::raw('COALESCE(c.last_message_at, c.created_at)'))
            ->get(['c.conversation_id', 'c.type', 'tu.full_name as technician_name', 'o.order_code', 'o.status as order_status']);

        $rows = [];
        foreach ($convs as $c) {
            $last = DB::table('chat_messages')
                ->where('conversation_id', $c->conversation_id)
                ->orderByDesc('message_id')
                ->first(['body', 'created_at', 'sender_role']);
            $unread = DB::table('chat_messages')
                ->where('conversation_id', $c->conversation_id)
                ->where('is_read', 0)
                ->where('sender_role', '!=', 'customer')
                ->count();

            $title = $c->type === 'support' ? 'Bantuan KomporCare' : ($c->technician_name ?? 'Teknisi');
            $subtitle = $c->type === 'support'
                ? 'Tim dukungan resmi'
                : trim(($c->order_code ?? '') . ' • Teknisi', ' •');

            if ($q !== '') {
                $hay = mb_strtolower($title . ' ' . ($c->order_code ?? ''));
                if (!str_contains($hay, mb_strtolower($q))) {
                    continue;
                }
            }

            $rows[] = [
                'conversation_id' => (int) $c->conversation_id,
                'type'            => $c->type,
                'title'           => $title,
                'subtitle'        => $subtitle,
                'order_code'      => $c->order_code,
                'last_message'    => $last->body ?? null,
                'last_at'         => $last->created_at ?? null,
                'unread'          => $unread,
            ];
        }

        return response()->json([
            'conversations' => $rows,
            'unread_total'  => array_sum(array_column($rows, 'unread')),
        ]);
    }

    public function openSupport(Request $request): JsonResponse
    {
        $user = $this->customer($request);

        $conv = DB::table('conversations')
            ->where('customer_id', $user->user_id)->where('type', 'support')->first();

        if (!$conv) {
            $id = DB::table('conversations')->insertGetId([
                'type' => 'support', 'customer_id' => $user->user_id,
                'last_message_at' => now(),
            ], 'conversation_id');
            DB::table('chat_messages')->insert([
                'conversation_id' => $id, 'sender_id' => null, 'sender_role' => 'support',
                'body' => self::SUPPORT_WELCOME, 'is_read' => 0,
            ]);
            $conv = (object) ['conversation_id' => $id];
        }

        return response()->json(['conversation_id' => (int) $conv->conversation_id]);
    }

    public function openForOrder(Request $request, string $code): JsonResponse
    {
        $user = $this->customer($request);

        $order = DB::table('orders as o')
            ->leftJoin('technicians as t', 't.technician_id', '=', 'o.technician_id')
            ->where('o.customer_id', $user->user_id)
            ->where('o.order_code', $code)
            ->first(['o.order_id', 't.user_id as technician_user_id']);
        abort_if(!$order, 404, 'Pesanan tidak ditemukan.');
        abort_if(!$order->technician_user_id, 422, 'Pesanan ini belum memiliki teknisi.');

        $conv = DB::table('conversations')
            ->where('customer_id', $user->user_id)
            ->where('type', 'technician')
            ->where('order_id', $order->order_id)
            ->first();

        $id = $conv ? $conv->conversation_id : DB::table('conversations')->insertGetId([
            'type' => 'technician', 'customer_id' => $user->user_id,
            'technician_user_id' => $order->technician_user_id,
            'order_id' => $order->order_id, 'last_message_at' => now(),
        ], 'conversation_id');

        return response()->json(['conversation_id' => (int) $id]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->customer($request);
        $conv = $this->ownConversation($user->user_id, $id);

        // pesan dari pihak lain dianggap sudah dibaca saat percakapan dibuka
        DB::table('chat_messages')
            ->where('conversation_id', $id)
            ->where('sender_role', '!=', 'customer')
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        $messages = DB::table('chat_messages')
            ->where('conversation_id', $id)
            ->orderBy('message_id')
            ->limit(500)
            ->get(['message_id', 'sender_role', 'body', 'created_at'])
            ->map(fn ($m) => $this->presentMessage($m))
            ->values();

        $header = [
            'conversation_id' => (int) $conv->conversation_id,
            'type'            => $conv->type,
            'title'           => 'Bantuan KomporCare',
            'subtitle'        => 'Tim dukungan resmi',
            'phone'           => null,
            'order'           => null,
        ];

        if ($conv->type === 'technician') {
            $tech = DB::table('users')->where('user_id', $conv->technician_user_id)->first(['full_name', 'phone']);
            $header['title'] = $tech->full_name ?? 'Teknisi';
            $header['subtitle'] = 'Teknisi KomporCare';
            $header['phone'] = $tech->phone ?? null;

            $o = DB::table('orders as o')
                ->join('order_services as os', 'os.order_id', '=', 'o.order_id')
                ->join('services as s', 's.service_id', '=', 'os.service_id')
                ->where('o.order_id', $conv->order_id)
                ->orderBy('os.order_service_id')
                ->first(['o.order_code', 'o.status', 'o.scheduled_at', 'o.total_price', 's.name as service_name', 's.est_duration_min']);
            if ($o) {
                $header['order'] = [
                    'order_code'       => $o->order_code,
                    'status'           => $o->status,
                    'scheduled_at'     => $o->scheduled_at,
                    'service_name'     => $o->service_name,
                    'est_duration_min' => $o->est_duration_min === null ? null : (int) $o->est_duration_min,
                    'total_price'      => (float) $o->total_price,
                ];
            }
        }

        return response()->json(['conversation' => $header, 'messages' => $messages]);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $user = $this->customer($request);
        $this->ownConversation($user->user_id, $id);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ], ['body.required' => 'Pesan tidak boleh kosong.']);

        $body = trim($data['body']);
        abort_if($body === '', 422, 'Pesan tidak boleh kosong.');

        $msgId = DB::table('chat_messages')->insertGetId([
            'conversation_id' => $id, 'sender_id' => $user->user_id,
            'sender_role' => 'customer', 'body' => $body, 'is_read' => 0,
        ], 'message_id');
        DB::table('conversations')->where('conversation_id', $id)->update(['last_message_at' => now()]);

        $m = DB::table('chat_messages')->where('message_id', $msgId)
            ->first(['message_id', 'sender_role', 'body', 'created_at']);

        return response()->json(['message' => $this->presentMessage($m)], 201);
    }

    private function presentMessage(object $m): array
    {
        return [
            'message_id'  => (int) $m->message_id,
            'sender_role' => $m->sender_role,
            'body'        => $m->body,
            'created_at'  => $m->created_at,
        ];
    }

    private function customer(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role === 'customer', 403, 'Halaman ini khusus Pelanggan.');
        return $user;
    }

    private function ownConversation(int $userId, int $id): object
    {
        $conv = DB::table('conversations')
            ->where('conversation_id', $id)->where('customer_id', $userId)->first();
        abort_if(!$conv, 404, 'Percakapan tidak ditemukan.');
        return $conv;
    }
}
