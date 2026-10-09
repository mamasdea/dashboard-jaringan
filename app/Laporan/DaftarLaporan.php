<?php

namespace App\Laporan;

/**
 * Laporan yang tersedia. Semua hanya membaca data tersimpan.
 */
class DaftarLaporan
{
    /** @var array<string, array{label: string, berperiode: bool}> */
    public const array JENIS = [
        'ketersediaan' => ['label' => 'Ketersediaan koneksi per OPD', 'berperiode' => true],
        'gangguan' => ['label' => 'Durasi dan jumlah gangguan', 'berperiode' => true],
        'tiket' => ['label' => 'Riwayat tiket', 'berperiode' => true],
        'inventaris' => ['label' => 'Inventaris aset dan kelengkapan pendataan', 'berperiode' => false],
    ];

    public function __construct(
        private readonly LaporanKetersediaan $ketersediaan,
        private readonly LaporanGangguan $gangguan,
        private readonly LaporanTiket $tiket,
        private readonly LaporanInventaris $inventaris,
    ) {}

    public function buat(string $jenis, Periode $periode): DokumenLaporan
    {
        return match ($jenis) {
            'ketersediaan' => $this->ketersediaan->buat($periode),
            'gangguan' => $this->gangguan->buat($periode),
            'tiket' => $this->tiket->buat($periode),
            'inventaris' => $this->inventaris->buat(),
            default => abort(404),
        };
    }
}
