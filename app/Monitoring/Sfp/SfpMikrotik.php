<?php

namespace App\Monitoring\Sfp;

use App\Monitoring\Snmp\KlienSnmp;

/**
 * Optik SFP perangkat MikroTik (MIKROTIK-MIB, mtxrOpticalTable).
 *
 * BELUM DIUJI PADA PERANGKAT NYATA. OID dan skala (seperseribu) mengikuti
 * definisi MIB; verifikasi dengan panduan uji sebelum dipercaya.
 */
class SfpMikrotik implements AdapterSfp
{
    private const string ENTERPRISE = '1.3.6.1.4.1.14988';

    private const string TABEL = '1.3.6.1.4.1.14988.1.1.19.1.1';

    public function nama(): string
    {
        return 'mikrotik';
    }

    public function mendukung(?string $sysObjectId): bool
    {
        $oid = ltrim((string) $sysObjectId, '.');

        return $oid === self::ENTERPRISE || str_starts_with($oid, self::ENTERPRISE.'.');
    }

    public function baca(KlienSnmp $klien): array
    {
        $nama = $klien->jelajah(self::TABEL.'.2', 256);

        if ($nama === []) {
            return [];
        }

        $suhu = $klien->jelajah(self::TABEL.'.6', 256);
        $tx = $klien->jelajah(self::TABEL.'.9', 256);
        $rx = $klien->jelajah(self::TABEL.'.10', 256);

        $hasil = [];

        foreach ($nama as $indeks => $namaInterface) {
            $hasil[mb_strtolower(trim($namaInterface))] = [
                'rx_dbm' => self::seperseribu($rx[$indeks] ?? null),
                'tx_dbm' => self::seperseribu($tx[$indeks] ?? null),
                'suhu_c' => isset($suhu[$indeks]) && is_numeric($suhu[$indeks]) ? (float) $suhu[$indeks] : null,
            ];
        }

        return $hasil;
    }

    private static function seperseribu(?string $nilai): ?float
    {
        return $nilai !== null && is_numeric($nilai) ? round((float) $nilai / 1000, 3) : null;
    }
}
