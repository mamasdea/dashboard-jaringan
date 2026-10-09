<?php

namespace App\Support;

/**
 * Pemformat nilai metrik. Nilai null selalu tampil sebagai "Tidak tersedia",
 * tidak pernah sebagai nol.
 */
final class Tampilan
{
    public const string TIDAK_TERSEDIA = 'Tidak tersedia';

    public static function atauTidakTersedia(mixed $nilai, string $satuan = ''): string
    {
        return $nilai === null || $nilai === '' ? self::TIDAK_TERSEDIA : trim($nilai.' '.$satuan);
    }

    public static function bps(float|int|string|null $bps): string
    {
        if ($bps === null) {
            return self::TIDAK_TERSEDIA;
        }

        $bps = (float) $bps;

        return match (true) {
            $bps >= 1e9 => number_format($bps / 1e9, 2, ',', '.').' Gbps',
            $bps >= 1e6 => number_format($bps / 1e6, 2, ',', '.').' Mbps',
            $bps >= 1e3 => number_format($bps / 1e3, 2, ',', '.').' kbps',
            default => number_format($bps, 0, ',', '.').' bps',
        };
    }

    public static function durasi(?int $detik): string
    {
        if ($detik === null) {
            return self::TIDAK_TERSEDIA;
        }

        $hari = intdiv($detik, 86400);
        $jam = intdiv($detik % 86400, 3600);
        $menit = intdiv($detik % 3600, 60);

        return ($hari > 0 ? "{$hari} hari " : '').sprintf('%d jam %d menit', $jam, $menit);
    }

    public static function alamatFisik(?string $heks): string
    {
        return $heks === null ? self::TIDAK_TERSEDIA : implode(':', str_split($heks, 2));
    }
}
