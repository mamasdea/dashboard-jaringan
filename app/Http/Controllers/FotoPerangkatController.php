<?php

namespace App\Http\Controllers;

use App\Models\Perangkat;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto perangkat disimpan di disk privat dan hanya dilayani lewat rute ini,
 * yang dijaga middleware `can:view,perangkat`.
 */
class FotoPerangkatController extends Controller
{
    public function __invoke(Perangkat $perangkat): StreamedResponse
    {
        $disk = Storage::disk(Perangkat::DISK_FOTO);

        abort_if($perangkat->foto_path === null || ! $disk->exists($perangkat->foto_path), 404);

        return $disk->response($perangkat->foto_path, headers: ['Cache-Control' => 'private, max-age=300']);
    }
}
