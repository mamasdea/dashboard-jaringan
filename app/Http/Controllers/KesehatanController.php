<?php

namespace App\Http\Controllers;

use App\Kesehatan\PemeriksaKesehatan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Health check untuk alat pemantau. Hanya aktif bila KESEHATAN_TOKEN diisi
 * dan hanya menjawab pembawa token itu. Jawabannya hanya baik/bermasalah per
 * komponen; rinciannya ada di `php artisan kesehatan:periksa`.
 */
class KesehatanController extends Controller
{
    public function __invoke(Request $request, PemeriksaKesehatan $pemeriksa): JsonResponse
    {
        $token = (string) config('kesehatan.token');

        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 404);

        $hasil = $pemeriksa->periksa();

        return response()->json([
            'status' => $hasil['sehat'] ? 'baik' : 'bermasalah',
            'komponen' => array_map(fn (array $k) => $k['baik'] ? 'baik' : 'bermasalah', $hasil['komponen']),
        ], $hasil['sehat'] ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
