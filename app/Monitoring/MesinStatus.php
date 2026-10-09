<?php

namespace App\Monitoring;

use App\Enums\AlasanStatus;
use App\Enums\KeluaranPemeriksaan;
use App\Enums\StatusMonitoring;

/**
 * Aturan transisi status. Murni: tidak menyentuh database maupun jam, sehingga
 * dapat diuji baris demi baris.
 *
 *   berhasil            -> Normal, atau Koneksi menurun bila melewati ambang
 *   gagal 1..(N-1) kali -> Koneksi menurun
 *   gagal N kali        -> Endpoint tidak terjangkau
 *   dari tidak terjangkau, butuh M kali berhasil berturut-turut untuk keluar
 *   galat kolektor      -> status dan penghitung tidak berubah
 *
 * Kedaluwarsa dan pemeliharaan bergantung pada waktu, jadi ditangani
 * LayananStatus, bukan di sini.
 */
final class MesinStatus
{
    /**
     * @return array{status: StatusMonitoring, gagal: int, berhasil: int, galatKolektor: int, alasan: AlasanStatus|null}
     */
    public static function terapkan(
        StatusMonitoring $status,
        int $gagal,
        int $berhasil,
        int $galatKolektor,
        HasilAdapter $hasil,
        float $ambangLatencyMs,
        float $ambangPacketLossPersen,
        int $batasGagal,
        int $batasPulih,
    ): array {
        if ($hasil->keluaran === KeluaranPemeriksaan::GalatKolektor) {
            return ['status' => $status, 'gagal' => $gagal, 'berhasil' => $berhasil, 'galatKolektor' => $galatKolektor + 1, 'alasan' => null];
        }

        if ($hasil->keluaran === KeluaranPemeriksaan::Berhasil) {
            $berhasil++;
            $gagal = 0;

            $kualitas = ($hasil->latencyMs ?? 0.0) > $ambangLatencyMs || ($hasil->packetLossPersen ?? 0.0) > $ambangPacketLossPersen
                ? StatusMonitoring::KoneksiMenurun
                : StatusMonitoring::Normal;

            $baru = $status === StatusMonitoring::TidakTerjangkau && $berhasil < max(1, $batasPulih)
                ? StatusMonitoring::TidakTerjangkau
                : $kualitas;

            $alasan = match (true) {
                $baru === $status => null,
                $status === StatusMonitoring::TidakTerjangkau => AlasanStatus::Pulih,
                $status === StatusMonitoring::BelumDiketahui, $status === StatusMonitoring::Pemeliharaan => AlasanStatus::HasilBaru,
                default => AlasanStatus::Kualitas,
            };
        } else {
            $gagal++;
            $berhasil = 0;

            $baru = match (true) {
                $gagal >= max(1, $batasGagal), $status === StatusMonitoring::TidakTerjangkau => StatusMonitoring::TidakTerjangkau,
                default => StatusMonitoring::KoneksiMenurun,
            };

            $alasan = match (true) {
                $baru === $status => null,
                $baru === StatusMonitoring::TidakTerjangkau => AlasanStatus::GagalBerturut,
                default => AlasanStatus::PemeriksaanGagal,
            };
        }

        return ['status' => $baru, 'gagal' => $gagal, 'berhasil' => $berhasil, 'galatKolektor' => 0, 'alasan' => $alasan];
    }
}
