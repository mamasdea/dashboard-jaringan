<?php

namespace App\Monitoring\Snmp;

/**
 * Menghitung laju (bit per detik) dari dua sampel penghitung oktet.
 *
 * Murni. Mengembalikan null beserta alasannya bila laju tidak dapat
 * dipercaya; tidak pernah mengembalikan nol sebagai pengganti "tidak tahu".
 */
final class PenghitungLaju
{
    public const string SAMPEL_PERTAMA = 'sampel_pertama';

    public const string TIDAK_DIDUKUNG = 'tidak_didukung';

    public const string SELANG_TIDAK_VALID = 'selang_tidak_valid';

    public const string PERANGKAT_RESTART = 'perangkat_restart';

    public const string PENGHITUNG_RESET = 'penghitung_reset';

    public const string IDENTITAS_BERUBAH = 'identitas_berubah';

    public const string TIDAK_MASUK_AKAL = 'tidak_masuk_akal';

    private const int BATAS_32_BIT = 4_294_967_296;

    /**
     * @param  int|null  $sebelum  Penghitung pada sampel sebelumnya.
     * @param  int|null  $sesudah  Penghitung pada sampel sekarang.
     * @param  float  $selangDetik  Waktu antara kedua sampel menurut jam kolektor.
     * @param  float  $selangMaksDetik  Selang yang lebih panjang dari ini dianggap terputus.
     * @param  int|null  $uptimeSebelum  sysUpTime (seperseratus detik) pada kedua sampel.
     * @param  int|null  $diskontinuitasSebelum  ifCounterDiscontinuityTime pada kedua sampel.
     * @return array{bps: float|null, alasan: string|null}
     */
    public static function hitung(
        ?int $sebelum,
        ?int $sesudah,
        float $selangDetik,
        float $selangMaksDetik,
        bool $penghitung64Bit,
        ?int $uptimeSebelum,
        ?int $uptimeSesudah,
        ?int $diskontinuitasSebelum,
        ?int $diskontinuitasSesudah,
        ?int $kecepatanBps,
    ): array {
        if ($sesudah === null) {
            return self::kosong(self::TIDAK_DIDUKUNG);
        }

        if ($sebelum === null) {
            return self::kosong(self::SAMPEL_PERTAMA);
        }

        // Terlalu rapat memperbesar galat; terlalu jarang bisa menyembunyikan lebih dari satu putaran penghitung.
        if ($selangDetik < 1 || $selangDetik > $selangMaksDetik) {
            return self::kosong(self::SELANG_TIDAK_VALID);
        }

        // Uptime mundur: perangkat restart di antara dua sampel, penghitung mulai dari nol lagi.
        // (sysUpTime sendiri berputar tiap ~497 hari; itu pun diperlakukan sama, satu sampel dilewati.)
        if ($uptimeSebelum !== null && $uptimeSesudah !== null && $uptimeSesudah < $uptimeSebelum) {
            return self::kosong(self::PERANGKAT_RESTART);
        }

        // Perangkat sendiri menyatakan penghitung interface ini terputus (mis. modul dipasang ulang).
        if ($diskontinuitasSebelum !== null && $diskontinuitasSesudah !== null && $diskontinuitasSesudah !== $diskontinuitasSebelum) {
            return self::kosong(self::PENGHITUNG_RESET);
        }

        $selisih = $sesudah - $sebelum;

        if ($selisih < 0) {
            // Penghitung 64-bit tidak berputar dalam umur perangkat: nilai turun berarti di-reset.
            if ($penghitung64Bit) {
                return self::kosong(self::PENGHITUNG_RESET);
            }

            // Penghitung 32-bit berputar di 2^32; satu putaran masih dapat dihitung.
            $selisih += self::BATAS_32_BIT;
        }

        $bps = $selisih * 8 / $selangDetik;

        // Lebih cepat dari kapasitas port: hampir pasti reset yang tampak seperti putaran,
        // atau lebih dari satu putaran 32-bit. Sedikit kelonggaran untuk ketidaktepatan waktu.
        if ($kecepatanBps !== null && $kecepatanBps > 0 && $bps > $kecepatanBps * 1.2) {
            return self::kosong(self::TIDAK_MASUK_AKAL);
        }

        return ['bps' => round($bps, 2), 'alasan' => null];
    }

    /**
     * Selisih penghitung galat/buang antara dua sampel, atau null bila tidak dapat dipercaya.
     */
    public static function selisih(?int $sebelum, ?int $sesudah, bool $bolehDihitung): ?int
    {
        if (! $bolehDihitung || $sebelum === null || $sesudah === null) {
            return null;
        }

        $selisih = $sesudah - $sebelum;

        if ($selisih < 0) {
            $selisih += self::BATAS_32_BIT;
        }

        // Penghitung galat 32-bit yang "berputar" sejauh ini hampir pasti reset.
        return $selisih > self::BATAS_32_BIT / 2 ? null : $selisih;
    }

    /**
     * @return array{bps: null, alasan: string}
     */
    private static function kosong(string $alasan): array
    {
        return ['bps' => null, 'alasan' => $alasan];
    }
}
