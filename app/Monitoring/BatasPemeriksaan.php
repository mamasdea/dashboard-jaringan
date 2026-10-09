<?php

namespace App\Monitoring;

/**
 * Batas atas dan bawah untuk setelan pemeriksaan. Nilai dari environment
 * dipangkas ke rentang ini, supaya salah ketik tidak menghasilkan ratusan
 * paket atau percobaan ulang tanpa henti ke satu perangkat.
 */
final class BatasPemeriksaan
{
    public static function jumlahPaketIcmp(): int
    {
        return self::pangkas((int) config('monitoring.icmp.jumlah_paket'), 1, 10);
    }

    public static function batasWaktuIcmpMs(): int
    {
        return self::pangkas((int) config('monitoring.icmp.batas_waktu_ms'), 100, 5000);
    }

    public static function batasWaktuSnmpMs(): int
    {
        return self::pangkas((int) config('monitoring.snmp.batas_waktu_ms'), 200, 5000);
    }

    public static function percobaanUlangSnmp(): int
    {
        return self::pangkas((int) config('monitoring.snmp.percobaan_ulang'), 0, 3);
    }

    public static function maksInterface(): int
    {
        return self::pangkas((int) config('monitoring.snmp.maks_interface'), 1, 2048);
    }

    private static function pangkas(int $nilai, int $bawah, int $atas): int
    {
        return max($bawah, min($atas, $nilai));
    }
}
