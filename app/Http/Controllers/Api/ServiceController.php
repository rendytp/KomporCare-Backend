<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Katalog layanan dari tabel `services` + `service_categories`.
 * GET /api/services?q=&category_id=
 */
class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'           => ['nullable', 'string', 'max:60'],
            'category_id' => ['nullable', 'integer'],
        ]);

        $categories = DB::table('service_categories')
            ->orderBy('category_id')
            ->get(['category_id', 'name', 'description']);

        $query = DB::table('services as s')
            ->join('service_categories as c', 'c.category_id', '=', 's.category_id')
            ->where('s.is_active', 1);

        if (!empty($data['category_id'])) {
            $query->where('s.category_id', $data['category_id']);
        }

        if (!empty($data['q'])) {
            $like = '%' . addcslashes($data['q'], '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('s.name', 'like', $like)
                  ->orWhere('s.description', 'like', $like)
                  ->orWhere('c.name', 'like', $like);
            });
        }

        $services = $query->orderBy('s.service_id')->get(self::columns());

        return response()->json([
            'categories' => $categories->map(fn ($c) => [
                'category_id' => (int) $c->category_id,
                'name'        => $c->name,
                'description' => $c->description,
            ])->values(),
            'services' => $services->map(fn ($s) => self::present($s))->values(),
        ]);
    }

    /** Kolom yang dipilih untuk query layanan (alias s = services, c = service_categories). */
    public static function columns(): array
    {
        return [
            's.service_id', 's.category_id', 'c.name as category_name', 's.name',
            's.description', 's.base_price', 's.visit_fee', 's.est_duration_min',
        ];
    }

    public static function present(object $s): array
    {
        return [
            'service_id'       => (int) $s->service_id,
            'category_id'      => (int) $s->category_id,
            'category_name'    => $s->category_name,
            'name'             => $s->name,
            'description'      => $s->description,
            'base_price'       => (float) $s->base_price,
            'visit_fee'        => (float) $s->visit_fee,
            'est_duration_min' => $s->est_duration_min === null ? null : (int) $s->est_duration_min,
        ];
    }
}
