<?php

namespace Database\Seeders;

use App\Impor\PengimporCsv;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Mengisi OPD, perangkat, dan inventaris FO contoh berlabel SIMULASI.
 *
 *     php artisan db:seed --class=SimulasiSeeder
 *
 * Sumbernya `database/simulasi/*.csv`, diproses oleh pengimpor yang sama dengan
 * halaman impor. IP manajemen memakai rentang dokumentasi 192.0.2.0/24
 * (RFC 5737) sehingga tidak pernah menunjuk perangkat nyata.
 * Hapus dengan `php artisan simulasi:hapus`.
 */
class SimulasiSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Data SIMULASI tidak boleh diisi di lingkungan production.');
        }

        foreach (PengimporCsv::JENIS as $jenis) {
            $hasil = PengimporCsv::untuk($jenis)->impor(database_path("simulasi/{$jenis}.csv"));

            if ($hasil['galat_berkas'] !== [] || $hasil['jumlah']['galat'] > 0) {
                throw new RuntimeException("Berkas simulasi {$jenis}.csv mengandung kesalahan.");
            }
        }

        $this->call(SimulasiFoSeeder::class);
        $this->call(SimulasiMonitoringSeeder::class);
        $this->call(SimulasiTiketSeeder::class);
    }
}
