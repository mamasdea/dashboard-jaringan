<?php

namespace App\Laporan;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Mengubah DokumenLaporan menjadi CSV atau PDF. Isi keduanya sama dengan
 * halaman laporan, termasuk definisi dan keterbatasannya.
 */
class Ekspor
{
    public const array FORMAT = ['csv' => 'text/csv; charset=UTF-8', 'pdf' => 'application/pdf'];

    /** Pemisah titik koma: angka memakai koma desimal, dan Excel berbahasa Indonesia membacanya langsung. */
    private const string PEMISAH = ';';

    public function csv(DokumenLaporan $dokumen): string
    {
        $baris = [
            [$dokumen->judul],
            ['Periode', $dokumen->periode ?? 'Keadaan saat laporan dibuat'],
            ['Dibuat pada (WIB)', DokumenLaporan::waktu($dokumen->dibuatPada)],
            [],
        ];

        foreach ($dokumen->ringkasan as $nama => $nilai) {
            $baris[] = [$nama, $nilai];
        }

        foreach ($dokumen->bagian as $bagian) {
            $baris[] = [];
            $baris[] = [$bagian->judul];
            $baris[] = $bagian->kolom;
            array_push($baris, ...($bagian->baris === [] ? [[$bagian->kosong]] : $bagian->baris));
        }

        $baris[] = [];
        $baris[] = ['Catatan'];

        foreach ($dokumen->catatan as $catatan) {
            $baris[] = [$catatan];
        }

        $keluaran = fopen('php://temp', 'r+');

        if ($keluaran === false) {
            throw new RuntimeException('Tidak dapat membuka penampung sementara untuk CSV.');
        }

        // BOM supaya Excel membaca UTF-8.
        fwrite($keluaran, "\u{FEFF}");

        foreach ($baris as $sel) {
            fputcsv($keluaran, array_map(self::amankan(...), $sel), self::PEMISAH, '"', '');
        }

        rewind($keluaran);

        return (string) stream_get_contents($keluaran);
    }

    public function pdf(DokumenLaporan $dokumen): string
    {
        $opsi = new Options;
        // Tidak ada sumber luar dan tidak ada PHP di dalam dokumen.
        $opsi->setIsRemoteEnabled(false);
        $opsi->setIsPhpEnabled(false);
        $opsi->setDefaultFont('DejaVu Sans');
        $opsi->setChroot(storage_path('framework'));
        // vendor/ tidak dapat ditulis di produksi.
        $opsi->setTempDir(storage_path('framework/cache'));
        $opsi->setFontCache(storage_path('framework/cache'));

        $pdf = new Dompdf($opsi);
        $pdf->loadHtml(view('laporan.pdf', ['dokumen' => $dokumen])->render(), 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return (string) $pdf->output();
    }

    public function namaBerkas(DokumenLaporan $dokumen, Periode $periode, string $format): string
    {
        $rentang = $dokumen->periode === null
            ? $dokumen->dibuatPada->timezone((string) config('app.display_timezone'))->format('Y-m-d')
            : "{$periode->dari}_{$periode->sampai}";

        return "laporan-{$dokumen->jenis}-{$rentang}.{$format}";
    }

    /**
     * Sel yang berawalan tanda rumus diberi apostrof, supaya teks dari
     * database (judul tiket, catatan) tidak dijalankan aplikasi lembar kerja.
     */
    private static function amankan(string $sel): string
    {
        return $sel !== '' && in_array($sel[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$sel : $sel;
    }
}
