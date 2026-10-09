<?php

namespace Database\Seeders;

use App\Enums\JenisCatatanTiket;
use App\Enums\PrioritasTiket;
use App\Models\Opd;
use App\Models\SegmenKabel;
use App\Models\Tiket;
use App\Tiket\LayananTiket;
use Illuminate\Database\Seeder;

/**
 * Satu tiket contoh berlabel SIMULASI yang dikaitkan dengan kabel contoh,
 * untuk memperlihatkan "Potensi terdampak berdasarkan inventaris". Tiket
 * otomatis contoh muncul sendiri saat kolektor simulasi mencatat insiden.
 */
class SimulasiTiketSeeder extends Seeder
{
    public function run(LayananTiket $layanan): void
    {
        $kabel = SegmenKabel::query()->where('kode', 'SIM-DS-01')->first();

        if ($kabel === null || Tiket::query()->where('simulasi', true)->where('segmen_kabel_id', $kabel->id)->exists()) {
            return;
        }

        $tiket = $layanan->buat([
            'judul' => 'Laporan kabel kendur dekat tiang SIM-TG-005',
            'opd_id' => Opd::query()->where('kode', 'SIM-SETDA')->value('id'),
            'segmen_kabel_id' => $kabel->id,
            'waktu_mulai' => now()->subHours(2),
            'prioritas' => PrioritasTiket::Sedang,
            'dugaan_penyebab' => 'Data contoh: diduga tersangkut dahan. Belum diperiksa.',
            'simulasi' => true,
        ], null);

        $layanan->catat($tiket, JenisCatatanTiket::Catatan, 'Data contoh: laporan diterima dari petugas OPD lewat telepon.');
    }
}
