<?php

namespace App\Laporan;

use Carbon\CarbonImmutable;

/**
 * Isi sebuah laporan dalam bentuk yang sama untuk halaman, CSV, dan PDF.
 * Semua sel sudah berupa teks siap tampil.
 */
final readonly class DokumenLaporan
{
    /**
     * @param  array<string, string>  $ringkasan
     * @param  list<BagianLaporan>  $bagian
     * @param  list<string>  $catatan  Definisi dan keterbatasan; selalu ikut diekspor.
     */
    public function __construct(
        public string $jenis,
        public string $judul,
        public ?string $periode,
        public CarbonImmutable $dibuatPada,
        public array $ringkasan,
        public array $bagian,
        public array $catatan,
    ) {}

    public static function angka(float|int $nilai, int $desimal = 2): string
    {
        return number_format($nilai, $desimal, ',', '.');
    }

    public static function persen(?float $nilai): string
    {
        return $nilai === null ? 'Tidak tersedia' : self::angka($nilai).'%';
    }

    public static function jam(int $detik): string
    {
        return self::angka($detik / 3600);
    }

    public static function waktu(?CarbonImmutable $waktu): string
    {
        return $waktu === null ? '' : $waktu->timezone((string) config('app.display_timezone'))->format('d-m-Y H:i');
    }
}
