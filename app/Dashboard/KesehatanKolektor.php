<?php

namespace App\Dashboard;

use App\Enums\KeluaranPemeriksaan;
use App\Models\DetakKolektor;
use App\Models\EndpointMonitoring;
use App\Models\HasilPemeriksaan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Menilai kesehatan kolektor dari jejak yang ditinggalkannya di database:
 * detak tiap putaran, endpoint yang terlambat diperiksa, dan galat kolektor.
 * Tidak menghubungi kolektor maupun perangkat.
 */
class KesehatanKolektor
{
    public const string BERJALAN = 'berjalan';

    public const string TERLAMBAT = 'terlambat';

    public const string BERHENTI = 'berhenti';

    public const string BELUM_PERNAH = 'belum_pernah';

    /**
     * @return array{
     *     status: string, label: string, ikon: string, warna: string, penjelasan: string,
     *     detak_terakhir: CarbonImmutable|null, proses_aktif: int, adapter: string|null, simulasi: bool,
     *     endpoint_terlambat: int, galat_kolektor: int
     * }
     */
    public function periksa(?CarbonInterface $pada = null): array
    {
        $pada = CarbonImmutable::instance($pada ?? now())->utc();
        $batasTerlambat = (int) config('dashboard.kolektor.terlambat_detik');
        $batasBerhenti = max($batasTerlambat, (int) config('dashboard.kolektor.berhenti_detik'));

        $terakhir = DetakKolektor::query()->orderByDesc('detak_pada')->first();
        $umur = $terakhir === null ? null : (float) $terakhir->detak_pada->diffInSeconds($pada, absolute: false);

        $status = match (true) {
            $umur === null => self::BELUM_PERNAH,
            $umur <= $batasTerlambat => self::BERJALAN,
            $umur <= $batasBerhenti => self::TERLAMBAT,
            default => self::BERHENTI,
        };

        // Endpoint yang semestinya sudah diperiksa lebih dari dua interval yang lalu.
        $terlambat = EndpointMonitoring::query()
            ->where('aktif', true)
            ->where('pemeliharaan', false)
            ->get(['id', 'interval_detik', 'berikutnya_pada'])
            ->filter(fn (EndpointMonitoring $e) => $e->berikutnya_pada->diffInSeconds($pada, absolute: false) > 2 * $e->interval_detik)
            ->count();

        $galat = HasilPemeriksaan::query()
            ->where('keluaran', KeluaranPemeriksaan::GalatKolektor)
            ->where('diterima_pada', '>=', $pada->subMinutes(15))
            ->count();

        // Kolektor hidup tetapi tidak mampu memeriksa tetap perlu perhatian.
        if ($status === self::BERJALAN && ($terlambat > 0 || $galat > 0)) {
            $status = self::TERLAMBAT;
        }

        [$label, $ikon, $warna, $penjelasan] = match ($status) {
            self::BERJALAN => ['Kolektor berjalan', 'check-circle', 'green', 'Pemeriksaan berlangsung sesuai jadwal.'],
            self::TERLAMBAT => ['Kolektor perlu diperiksa', 'exclamation-triangle', 'amber', match (true) {
                $galat > 0 => "{$galat} pemeriksaan gagal dijalankan kolektor dalam 15 menit terakhir.",
                $terlambat > 0 => "{$terlambat} endpoint terlambat diperiksa.",
                default => 'Kolektor terlambat memberi tanda hidup.',
            }],
            self::BERHENTI => ['Kolektor berhenti', 'x-circle', 'red', 'Tidak ada tanda hidup dari kolektor. Status di halaman ini akan menjadi "Belum diketahui".'],
            default => ['Kolektor belum pernah berjalan', 'question-mark-circle', 'zinc', 'Jalankan `php artisan monitoring:kolektor` di host kolektor.'],
        };

        return [
            'status' => $status,
            'label' => $label,
            'ikon' => $ikon,
            'warna' => $warna,
            'penjelasan' => $penjelasan,
            'detak_terakhir' => $terakhir?->detak_pada,
            'proses_aktif' => DetakKolektor::query()->where('detak_pada', '>=', $pada->subSeconds($batasTerlambat))->count(),
            'adapter' => $terakhir?->adapter,
            'simulasi' => (bool) $terakhir?->simulasi,
            'endpoint_terlambat' => $terlambat,
            'galat_kolektor' => $galat,
        ];
    }
}
