<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retensi data monitoring. Hanya menghapus data mentah yang tumbuh tiap
 * menit; riwayat status dan insiden (dasar laporan) tidak pernah dipangkas.
 */
#[Signature('monitoring:pangkas {--uji-coba : Hanya menghitung, tidak menghapus}')]
#[Description('Menghapus hasil pemeriksaan dan sampel trafik yang melewati masa retensi')]
class PangkasDataMonitoring extends Command
{
    private const int UKURAN_TUMPUKAN = 5000;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $ujiCoba = (bool) $this->option('uji-coba');

        foreach ([
            ['hasil_pemeriksaan', 'diperiksa_pada', (int) config('monitoring.retensi.hasil_hari'), 'Hasil pemeriksaan'],
            ['sampel_trafik', 'diambil_pada', (int) config('monitoring.retensi.trafik_hari'), 'Sampel trafik'],
            ['detak_kolektor', 'detak_pada', (int) config('monitoring.retensi.detak_hari'), 'Detak kolektor'],
        ] as [$tabel, $kolom, $hari, $sebutan]) {
            if ($hari <= 0) {
                $this->line("{$sebutan}: retensi dimatikan, tidak ada yang dihapus.");

                continue;
            }

            $batas = now()->subDays($hari);
            $jumlah = $ujiCoba
                ? DB::table($tabel)->where($kolom, '<', $batas)->count()
                : $this->hapus($tabel, $kolom, $batas);

            $this->line(sprintf('%s: %d baris lebih tua dari %d hari %s.', $sebutan, $jumlah, $hari, $ujiCoba ? 'akan dihapus' : 'dihapus'));
        }

        return self::SUCCESS;
    }

    /**
     * Dihapus bertumpuk supaya tabel tidak terkunci lama saat kolektor menulis.
     */
    private function hapus(string $tabel, string $kolom, \DateTimeInterface $batas): int
    {
        $jumlah = 0;

        do {
            $terhapus = DB::table($tabel)
                ->whereIn('id', DB::table($tabel)->select('id')->where($kolom, '<', $batas)->limit(self::UKURAN_TUMPUKAN))
                ->delete();
            $jumlah += $terhapus;
        } while ($terhapus === self::UKURAN_TUMPUKAN);

        return $jumlah;
    }
}
