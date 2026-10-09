<?php

namespace App\Console\Commands;

use App\Kesehatan\PemeriksaKesehatan;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kesehatan:periksa')]
#[Description('Memeriksa database, penyimpanan, penjadwal, worker antrean, dan kolektor; kode keluar 1 bila ada yang bermasalah')]
class PeriksaKesehatan extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PemeriksaKesehatan $pemeriksa): int
    {
        $hasil = $pemeriksa->periksa();

        $this->table(['Komponen', 'Keadaan', 'Keterangan'], array_map(
            fn (string $nama, array $k) => [$nama, $k['baik'] ? 'BAIK' : 'BERMASALAH', $k['keterangan']],
            array_keys($hasil['komponen']),
            array_values($hasil['komponen']),
        ));

        return $hasil['sehat'] ? self::SUCCESS : self::FAILURE;
    }
}
