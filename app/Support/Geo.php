<?php

namespace App\Support;

final class Geo
{
    private const float JARI_JARI_BUMI_M = 6_371_008.8;

    /**
     * Panjang jalur dalam meter (jarak lingkaran besar antar-titik berurutan).
     *
     * @param  list<array{0: float|int|string, 1: float|int|string}>|null  $jalur  Titik [bujur, lintang] (urutan GeoJSON).
     */
    public static function panjangJalurMeter(?array $jalur): ?float
    {
        if ($jalur === null || count($jalur) < 2) {
            return null;
        }

        $total = 0.0;

        for ($i = 1, $n = count($jalur); $i < $n; $i++) {
            $total += self::jarakMeter(
                (float) $jalur[$i - 1][1], (float) $jalur[$i - 1][0],
                (float) $jalur[$i][1], (float) $jalur[$i][0],
            );
        }

        return round($total, 2);
    }

    public static function jarakMeter(float $lintang1, float $bujur1, float $lintang2, float $bujur2): float
    {
        $dLintang = deg2rad($lintang2 - $lintang1);
        $dBujur = deg2rad($bujur2 - $bujur1);

        $a = sin($dLintang / 2) ** 2
            + cos(deg2rad($lintang1)) * cos(deg2rad($lintang2)) * sin($dBujur / 2) ** 2;

        return 2 * self::JARI_JARI_BUMI_M * asin(min(1.0, sqrt($a)));
    }
}
