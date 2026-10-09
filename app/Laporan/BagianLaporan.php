<?php

namespace App\Laporan;

/**
 * Satu tabel di dalam laporan.
 */
final readonly class BagianLaporan
{
    /**
     * @param  list<string>  $kolom
     * @param  list<list<string>>  $baris
     */
    public function __construct(
        public string $judul,
        public array $kolom,
        public array $baris,
        public string $kosong = 'Tidak ada data.',
    ) {}
}
