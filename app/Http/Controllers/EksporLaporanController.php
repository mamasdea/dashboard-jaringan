<?php

namespace App\Http\Controllers;

use App\Enums\AksiAktivitas;
use App\Laporan\DaftarLaporan;
use App\Laporan\Ekspor;
use App\Laporan\Periode;
use App\Support\PencatatAktivitas;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Unduhan laporan. Dijaga middleware `can:lihat-laporan`; hanya membaca data
 * tersimpan dan mencatat siapa mengekspor apa.
 */
class EksporLaporanController extends Controller
{
    public function __invoke(Request $request, string $jenis, string $format, DaftarLaporan $daftar, Ekspor $ekspor, PencatatAktivitas $pencatat): Response
    {
        $periode = Periode::dariIsian($request->query('dari'), $request->query('sampai'));
        $dokumen = $daftar->buat($jenis, $periode);
        $nama = $ekspor->namaBerkas($dokumen, $periode, $format);

        $pencatat->catat(AksiAktivitas::LaporanDiekspor, rincian: ['laporan' => $jenis, 'format' => $format, 'periode' => $dokumen->periode]);

        return response($format === 'pdf' ? $ekspor->pdf($dokumen) : $ekspor->csv($dokumen), 200, [
            'Content-Type' => Ekspor::FORMAT[$format],
            'Content-Disposition' => 'attachment; filename="'.$nama.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
