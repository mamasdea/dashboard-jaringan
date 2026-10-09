<?php

namespace App\Impor;

use Illuminate\Support\Str;

/**
 * Membaca berkas CSV menjadi baris-baris berkunci nama kolom.
 *
 * Menerima pemisah koma atau titik koma (bawaan Excel berbahasa Indonesia),
 * BOM UTF-8, dan berkas ber-encoding Windows-1252.
 */
final class PembacaCsv
{
    public const int BATAS_BARIS = 2000;

    /**
     * @param  list<string>  $kolomDikenal
     * @param  list<string>  $kolomWajib
     * @return array{galat: list<string>, baris: array<int, array<string, string>|string>}
     *                                                                                     Kunci `baris` adalah nomor baris di berkas (judul = 1). Nilai berupa
     *                                                                                     string berarti baris tersebut tidak dapat dibaca.
     */
    public static function baca(string $jalur, array $kolomDikenal, array $kolomWajib): array
    {
        $isi = @file_get_contents($jalur);

        if ($isi === false || trim($isi) === '') {
            return ['galat' => ['Berkas kosong atau tidak dapat dibaca.'], 'baris' => []];
        }

        if (str_starts_with($isi, "\xEF\xBB\xBF")) {
            $isi = substr($isi, 3);
        }

        if (! mb_check_encoding($isi, 'UTF-8')) {
            $isi = mb_convert_encoding($isi, 'UTF-8', 'Windows-1252');
        }

        $isi = str_replace(["\r\n", "\r"], "\n", $isi);
        $barisJudul = strtok($isi, "\n") ?: '';
        $pemisah = substr_count($barisJudul, ';') > substr_count($barisJudul, ',') ? ';' : ',';

        $berkas = fopen('php://temp', 'r+');

        if ($berkas === false) {
            return ['galat' => ['Berkas tidak dapat diproses.'], 'baris' => []];
        }

        fwrite($berkas, $isi);
        rewind($berkas);

        $judul = array_map(
            fn (?string $sel): string => Str::of((string) $sel)->trim()->lower()->replace([' ', '-'], '_')->value(),
            fgetcsv($berkas, null, $pemisah, '"', '') ?: [],
        );

        $galat = self::periksaJudul($judul, $kolomDikenal, $kolomWajib);

        if ($galat !== []) {
            fclose($berkas);

            return ['galat' => $galat, 'baris' => []];
        }

        $baris = [];
        $nomor = 1;

        while (($sel = fgetcsv($berkas, null, $pemisah, '"', '')) !== false) {
            $nomor++;
            $sel = array_map(fn (?string $nilai): string => trim((string) $nilai), $sel);

            if (implode('', $sel) === '') {
                continue;
            }

            if (count($baris) >= self::BATAS_BARIS) {
                $galat[] = 'Berkas berisi lebih dari '.self::BATAS_BARIS.' baris data; baris selebihnya tidak diproses. Pecah menjadi beberapa berkas.';
                break;
            }

            if (count($sel) > count($judul)) {
                $baris[$nomor] = 'Jumlah kolom ('.count($sel).') melebihi judul ('.count($judul).'). Periksa tanda kutip dan pemisah.';

                continue;
            }

            $baris[$nomor] = array_combine($judul, array_pad($sel, count($judul), ''));
        }

        fclose($berkas);

        if ($baris === [] && $galat === []) {
            $galat[] = 'Berkas tidak berisi baris data.';
        }

        return ['galat' => $galat, 'baris' => $baris];
    }

    /**
     * @param  list<string>  $judul
     * @param  list<string>  $kolomDikenal
     * @param  list<string>  $kolomWajib
     * @return list<string>
     */
    private static function periksaJudul(array $judul, array $kolomDikenal, array $kolomWajib): array
    {
        $galat = [];

        if (in_array('', $judul, true)) {
            $galat[] = 'Baris judul memiliki kolom tanpa nama.';
        }

        $ganda = array_keys(array_filter(array_count_values(array_filter($judul)), fn (int $jumlah) => $jumlah > 1));

        if ($ganda !== []) {
            $galat[] = 'Kolom muncul lebih dari sekali: '.implode(', ', $ganda).'.';
        }

        $asing = array_values(array_diff(array_filter($judul), $kolomDikenal));

        if ($asing !== []) {
            $galat[] = 'Kolom tidak dikenal: '.implode(', ', $asing).'. Gunakan templat yang disediakan.';
        }

        $hilang = array_values(array_diff($kolomWajib, $judul));

        if ($hilang !== []) {
            $galat[] = 'Kolom wajib tidak ada: '.implode(', ', $hilang).'.';
        }

        return $galat;
    }
}
