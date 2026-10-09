<?php

namespace App\Http\Controllers;

use App\Models\Tiang;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto tiang disimpan di disk privat dan hanya dilayani lewat rute ini, yang
 * dijaga middleware `can:view,tiang`.
 */
class FotoTiangController extends Controller
{
    public function __invoke(Tiang $tiang): StreamedResponse
    {
        $disk = Storage::disk(Tiang::DISK_FOTO);

        abort_if($tiang->foto_path === null || ! $disk->exists($tiang->foto_path), 404);

        return $disk->response($tiang->foto_path, headers: ['Cache-Control' => 'private, max-age=300']);
    }
}
