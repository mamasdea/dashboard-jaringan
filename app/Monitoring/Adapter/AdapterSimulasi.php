<?php

namespace App\Monitoring\Adapter;

use App\Monitoring\HasilAdapter;
use App\Monitoring\Kontrak\AdapterPemeriksa;
use App\Monitoring\SasaranPemeriksaan;
use RuntimeException;

/**
 * Pemeriksa SIMULASI: tidak mengirim satu paket pun ke jaringan. Hasilnya
 * ditentukan kolom `endpoint_monitoring.skenario_simulasi` dan jam, sehingga
 * dapat diulang.
 */
class AdapterSimulasi implements AdapterPemeriksa
{
    public const string NORMAL = 'normal';

    public const string MENURUN = 'menurun';

    public const string TIDAK_TERJANGKAU = 'tidak_terjangkau';

    public const string GALAT_KOLEKTOR = 'galat_kolektor';

    /** Tiga menit menjawab, dua menit tidak, berulang. */
    public const string BERKEDIP = 'berkedip';

    public const array SKENARIO = [self::NORMAL, self::MENURUN, self::TIDAK_TERJANGKAU, self::GALAT_KOLEKTOR, self::BERKEDIP];

    public function nama(): string
    {
        return 'simulasi';
    }

    public function simulasi(): bool
    {
        return true;
    }

    public function periksa(SasaranPemeriksaan $sasaran): HasilAdapter
    {
        // Variasi kecil yang tetap sama untuk endpoint dan menit yang sama.
        $variasi = crc32($sasaran->endpointId.'|'.now()->format('YmdHi')) % 1000 / 1000;

        return match ($sasaran->skenarioSimulasi ?? self::NORMAL) {
            self::NORMAL => HasilAdapter::berhasil(2 + 18 * $variasi, 0),
            self::MENURUN => HasilAdapter::berhasil(220 + 120 * $variasi, 10 + 10 * $variasi),
            self::TIDAK_TERJANGKAU => HasilAdapter::gagalPerangkat('tanpa_jawaban', 'SIMULASI: endpoint tidak menjawab.'),
            self::GALAT_KOLEKTOR => throw new RuntimeException('SIMULASI: kolektor gagal menjalankan pemeriksaan.'),
            self::BERKEDIP => now()->minute % 5 < 3
                ? HasilAdapter::berhasil(2 + 18 * $variasi, 0)
                : HasilAdapter::gagalPerangkat('tanpa_jawaban', 'SIMULASI: endpoint tidak menjawab.'),
            default => HasilAdapter::galatKolektor('skenario_tidak_dikenal', "SIMULASI: skenario '{$sasaran->skenarioSimulasi}' tidak dikenal."),
        };
    }
}
