<?php

namespace App\Http\Controllers;

use App\Models\CatatanTiket;
use App\Models\Tiket;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto catatan tiket disimpan di disk privat dan hanya dilayani lewat rute
 * ini, yang dijaga middleware `can:view,tiket`.
 */
class FotoTiketController extends Controller
{
    public function __invoke(Tiket $tiket, CatatanTiket $catatan): StreamedResponse
    {
        $disk = Storage::disk(Tiket::DISK_FOTO);

        abort_if($catatan->tiket_id !== $tiket->id || $catatan->foto_path === null || ! $disk->exists($catatan->foto_path), 404);

        return $disk->response($catatan->foto_path, headers: ['Cache-Control' => 'private, max-age=300']);
    }
}
