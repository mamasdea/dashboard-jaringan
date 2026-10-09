<?php

namespace App\Laporan;

use App\Enums\StatusMonitoring;
use Carbon\CarbonImmutable;

/**
 * Membagi satu rentang waktu ke lima golongan menurut riwayat status sebuah
 * endpoint. Murni: tanpa database dan tanpa jam. Definisi tertulisnya ada di
 * docs/LAPORAN.md; ubah keduanya bersama-sama.
 */
final class PenghitungKetersediaan
{
    public const string NORMAL = 'normal';

    public const string MENURUN = 'menurun';

    public const string GANGGUAN = 'gangguan';

    public const string PEMELIHARAAN = 'pemeliharaan';

    public const string TANPA_DATA = 'tanpa_data';

    /**
     * @param  StatusMonitoring|null  $awal  Status yang berlaku tepat pada $mulai; null bila belum ada riwayat.
     * @param  list<array{pada: CarbonImmutable, menjadi: StatusMonitoring}>  $transisi  Perubahan status di dalam rentang, berurutan.
     * @param  CarbonImmutable|null  $dataSampai  Batas berlakunya hasil terakhir. Status hasil pengamatan sesudah titik ini tidak punya data pendukung.
     * @return array{normal: int, menurun: int, gangguan: int, pemeliharaan: int, tanpa_data: int} Detik per golongan; jumlahnya sama dengan lama rentang.
     */
    public static function hitung(?StatusMonitoring $awal, array $transisi, CarbonImmutable $mulai, CarbonImmutable $akhir, ?CarbonImmutable $dataSampai = null): array
    {
        /** @var array<string, int> $detik */
        $detik = [self::NORMAL => 0, self::MENURUN => 0, self::GANGGUAN => 0, self::PEMELIHARAAN => 0, self::TANPA_DATA => 0];

        if ($akhir->lessThanOrEqualTo($mulai)) {
            return self::bentuk($detik);
        }

        $status = $awal;
        $sejak = $mulai;

        foreach ([...$transisi, ['pada' => $akhir, 'menjadi' => null]] as $t) {
            $sampai = $t['pada']->max($mulai)->min($akhir);

            if ($sampai->greaterThan($sejak)) {
                $golongan = self::golongan($status);

                // Bagian setelah hasil terakhir kedaluwarsa tidak boleh dihitung
                // sebagai normal atau gangguan: tidak ada pemeriksaan yang mendukungnya.
                if ($golongan !== self::PEMELIHARAAN && $golongan !== self::TANPA_DATA && $dataSampai !== null && $dataSampai->lessThan($sampai)) {
                    $batas = $dataSampai->max($sejak);
                    $detik[$golongan] += (int) $sejak->diffInSeconds($batas);
                    $detik[self::TANPA_DATA] += (int) $batas->diffInSeconds($sampai);
                } else {
                    $detik[$golongan] += (int) $sejak->diffInSeconds($sampai);
                }

                $sejak = $sampai;
            }

            $status = $t['menjadi'];
        }

        return self::bentuk($detik);
    }

    /**
     * @param  array<string, int>  $detik
     * @return array{normal: int, menurun: int, gangguan: int, pemeliharaan: int, tanpa_data: int}
     */
    private static function bentuk(array $detik): array
    {
        return [
            self::NORMAL => $detik[self::NORMAL],
            self::MENURUN => $detik[self::MENURUN],
            self::GANGGUAN => $detik[self::GANGGUAN],
            self::PEMELIHARAAN => $detik[self::PEMELIHARAAN],
            self::TANPA_DATA => $detik[self::TANPA_DATA],
        ];
    }

    /**
     * Ketersediaan = waktu terjangkau / waktu yang teramati di luar
     * pemeliharaan. Null bila tidak ada waktu yang teramati.
     *
     * @param  array{normal: int, menurun: int, gangguan: int, pemeliharaan: int, tanpa_data: int}  $detik
     */
    public static function persen(array $detik): ?float
    {
        $terjangkau = $detik[self::NORMAL] + $detik[self::MENURUN];
        $teramati = $terjangkau + $detik[self::GANGGUAN];

        return $teramati === 0 ? null : $terjangkau / $teramati * 100;
    }

    /**
     * Bagian rentang yang punya data (termasuk pemeliharaan).
     *
     * @param  array{normal: int, menurun: int, gangguan: int, pemeliharaan: int, tanpa_data: int}  $detik
     */
    public static function cakupan(array $detik): ?float
    {
        $semua = array_sum($detik);

        return $semua === 0 ? null : ($semua - $detik[self::TANPA_DATA]) / $semua * 100;
    }

    private static function golongan(?StatusMonitoring $status): string
    {
        return match ($status) {
            StatusMonitoring::Normal => self::NORMAL,
            StatusMonitoring::KoneksiMenurun => self::MENURUN,
            StatusMonitoring::TidakTerjangkau => self::GANGGUAN,
            StatusMonitoring::Pemeliharaan => self::PEMELIHARAAN,
            StatusMonitoring::BelumDiketahui, null => self::TANPA_DATA,
        };
    }
}
