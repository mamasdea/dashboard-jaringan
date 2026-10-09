<?php

namespace App\Http\Controllers;

use App\Dashboard\RingkasanStatus;
use App\Dashboard\StatusOpd;
use Illuminate\Http\JsonResponse;

/**
 * Data peta status untuk dashboard. Dijaga middleware `can:lihat-dashboard`.
 *
 * Hanya membaca status tersimpan. Tidak memuat IP manajemen maupun rincian
 * perangkat, karena Viewer boleh membuka dashboard tetapi tidak inventaris.
 */
class PetaStatusController extends Controller
{
    public function __invoke(RingkasanStatus $ringkasan): JsonResponse
    {
        $zona = (string) config('app.display_timezone');

        return response()->json([
            'dibuat_pada' => now()->timezone($zona)->format('H:i:s'),
            'opd' => $ringkasan->opd()->map(fn (StatusOpd $s) => [
                'id' => $s->opd->id,
                'kode' => $s->opd->kode,
                'nama' => $s->opd->nama,
                'lat' => $s->opd->lintang === null ? null : (float) $s->opd->lintang,
                'lng' => $s->opd->bujur === null ? null : (float) $s->opd->bujur,
                'status' => $s->tampil->value,
                'label' => $s->tampil->label(),
                'kode_status' => $s->tampil->kode(),
                'keterangan' => $s->keterangan(),
                'diperiksa' => $s->status?->hasil_valid_terakhir_pada?->timezone($zona)->format('d-m-Y H:i:s'),
                'simulasi' => $s->simulasi(),
                'url' => route('dashboard.opd', $s->opd, absolute: false),
            ])->all(),
        ]);
    }
}
