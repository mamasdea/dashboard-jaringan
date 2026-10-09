<?php

namespace App\Kesehatan;

use App\Dashboard\KesehatanKolektor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Memeriksa bagian-bagian yang harus hidup di server: database, penyimpanan,
 * penjadwal, worker antrean, dan kolektor. Hanya membaca tanda hidup yang
 * tersimpan; tidak menghubungi perangkat jaringan.
 */
class PemeriksaKesehatan
{
    public const string DETAK_PENJADWAL = 'kesehatan:detak-penjadwal';

    public const string DETAK_ANTREAN = 'kesehatan:detak-antrean';

    public function __construct(private readonly KesehatanKolektor $kolektor) {}

    public static function catatDetak(string $kunci): void
    {
        Cache::forever($kunci, CarbonImmutable::now()->utc()->toIso8601String());
    }

    /**
     * @return array{sehat: bool, komponen: array<string, array{baik: bool, keterangan: string}>}
     */
    public function periksa(): array
    {
        $komponen = [];

        try {
            DB::select('SELECT 1');
            $komponen['database'] = ['baik' => true, 'keterangan' => 'Terhubung.'];
        } catch (Throwable) {
            // Tanpa database tidak ada tanda hidup lain yang dapat dibaca.
            return ['sehat' => false, 'komponen' => ['database' => ['baik' => false, 'keterangan' => 'Tidak dapat terhubung ke database.']]];
        }

        $komponen['penyimpanan'] = $this->penyimpanan();
        $komponen['penjadwal'] = $this->detak(self::DETAK_PENJADWAL, (int) config('kesehatan.penjadwal_detik'), 'Penjadwal');
        $komponen['antrean'] = $this->detak(self::DETAK_ANTREAN, (int) config('kesehatan.antrean_detik'), 'Worker antrean');

        $gagal = DB::table('failed_jobs')->count();

        if ($komponen['antrean']['baik'] && $gagal > 0) {
            $komponen['antrean']['keterangan'] .= " {$gagal} pekerjaan gagal menunggu ditinjau (php artisan queue:failed).";
        }

        $kolektor = $this->kolektor->periksa();
        $komponen['kolektor'] = [
            'baik' => $kolektor['status'] === KesehatanKolektor::BERJALAN,
            'keterangan' => $kolektor['label'].'. '.$kolektor['penjelasan'],
        ];

        return [
            'sehat' => ! in_array(false, array_column($komponen, 'baik'), true),
            'komponen' => $komponen,
        ];
    }

    /**
     * @return array{baik: bool, keterangan: string}
     */
    private function detak(string $kunci, int $batasDetik, string $sebutan): array
    {
        $nilai = Cache::get($kunci);

        if (! is_string($nilai)) {
            return ['baik' => false, 'keterangan' => "{$sebutan} belum pernah memberi tanda hidup."];
        }

        $umur = (int) CarbonImmutable::parse($nilai)->diffInSeconds(now(), absolute: false);

        return $umur <= $batasDetik
            ? ['baik' => true, 'keterangan' => "Tanda hidup {$umur} detik lalu."]
            : ['baik' => false, 'keterangan' => "{$sebutan} tidak memberi tanda hidup selama {$umur} detik (batas {$batasDetik})."];
    }

    /**
     * @return array{baik: bool, keterangan: string}
     */
    private function penyimpanan(): array
    {
        $jalur = storage_path('app');

        if (! is_writable($jalur) || ! is_writable(storage_path('logs')) || ! is_writable(storage_path('framework/cache'))) {
            return ['baik' => false, 'keterangan' => 'Direktori storage tidak dapat ditulis.'];
        }

        $sisa = @disk_free_space($jalur);
        $sisaMb = $sisa === false ? null : (int) floor($sisa / 1048576);
        $minimum = (int) config('kesehatan.disk_minimum_mb');

        return $sisaMb !== null && $sisaMb < $minimum
            ? ['baik' => false, 'keterangan' => "Sisa ruang disk {$sisaMb} MB, di bawah batas {$minimum} MB."]
            : ['baik' => true, 'keterangan' => $sisaMb === null ? 'Dapat ditulis.' : "Dapat ditulis; sisa {$sisaMb} MB."];
    }
}
