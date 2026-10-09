<?php

namespace App\Validasi;

/**
 * Merapikan masukan dari formulir Livewire dan sel CSV sebelum divalidasi.
 */
final class Normalisasi
{
    /**
     * Teks dipangkas; teks kosong menjadi null.
     */
    public static function teks(mixed $nilai): ?string
    {
        if ($nilai === null || is_array($nilai) || is_object($nilai)) {
            return null;
        }

        $teks = trim((string) $nilai);

        return $teks === '' ? null : $teks;
    }

    public static function kode(mixed $nilai): ?string
    {
        $teks = self::teks($nilai);

        return $teks === null ? null : mb_strtoupper($teks);
    }

    /**
     * Angka desimal; koma desimal (gaya Indonesia) diterima.
     */
    public static function desimal(mixed $nilai): ?string
    {
        $teks = self::teks($nilai);

        return $teks === null ? null : str_replace(',', '.', $teks);
    }

    /**
     * Menerima ya/tidak, true/false, 1/0. Nilai yang tidak dikenal dikembalikan
     * apa adanya supaya ditolak oleh aturan `boolean`.
     */
    public static function boolean(mixed $nilai, bool $bawaan): mixed
    {
        if (is_bool($nilai)) {
            return $nilai;
        }

        $teks = self::teks($nilai);

        if ($teks === null) {
            return $bawaan;
        }

        return match (mb_strtolower($teks)) {
            'ya', 'true', '1', 'aktif' => true,
            'tidak', 'false', '0', 'nonaktif' => false,
            default => $teks,
        };
    }
}
