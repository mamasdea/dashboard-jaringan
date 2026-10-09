<?php

namespace App\Monitoring\Snmp;

use App\Monitoring\BatasPemeriksaan;
use App\Monitoring\Penyamar;
use App\Monitoring\SasaranPemeriksaan;
use App\Monitoring\Sfp\AdapterSfp;
use Throwable;

/**
 * Membaca identitas, uptime, dan tabel interface satu perangkat lewat SNMP.
 *
 * Hanya GET dan walk terhadap satu alamat yang sudah terdaftar; tidak ada
 * pemindaian. Semua OID standar (SNMPv2-MIB, IF-MIB); optik SFP lewat adapter
 * vendor bila ada yang mengenali perangkatnya.
 */
class PemeriksaSnmp
{
    private const string SYS_DESCR = '1.3.6.1.2.1.1.1.0';

    private const string SYS_OBJECT_ID = '1.3.6.1.2.1.1.2.0';

    private const string SYS_UPTIME = '1.3.6.1.2.1.1.3.0';

    private const string SYS_NAME = '1.3.6.1.2.1.1.5.0';

    private const string IF_TABLE = '1.3.6.1.2.1.2.2.1';

    private const string IFX_TABLE = '1.3.6.1.2.1.31.1.1.1';

    /**
     * @param  iterable<AdapterSfp>  $adapterSfp
     */
    public function __construct(
        private readonly PabrikKlienSnmp $pabrik,
        private readonly iterable $adapterSfp = [],
    ) {}

    public function periksa(SasaranPemeriksaan $sasaran): HasilSnmp
    {
        if ($sasaran->kredensialSnmp === null || $sasaran->versiSnmp === null) {
            return HasilSnmp::galatKolektor('profil_snmp_tidak_ada', 'Endpoint tidak memiliki profil SNMP.');
        }

        $kredensial = [];

        try {
            $kredensial = ($sasaran->kredensialSnmp)();

            $klien = $this->pabrik->buat(
                $sasaran->alamat,
                $sasaran->portSnmp ?? 161,
                $sasaran->versiSnmp,
                $kredensial,
                BatasPemeriksaan::batasWaktuSnmpMs(),
                BatasPemeriksaan::percobaanUlangSnmp(),
            );

            return $this->baca($klien);
        } catch (GalatSnmp $e) {
            $hasil = HasilSnmp::gagal($e);
        } catch (Throwable $e) {
            // Termasuk kredensial yang tidak dapat dibuka (kunci privat tidak ada).
            $hasil = HasilSnmp::galatKolektor('galat_snmp', $e::class.': '.$e->getMessage());
        }

        return $hasil->denganPesan(Penyamar::bersihkan($hasil->pesan, $kredensial));
    }

    private function baca(KlienSnmp $klien): HasilSnmp
    {
        $sistem = $klien->ambil([self::SYS_DESCR, self::SYS_OBJECT_ID, self::SYS_UPTIME, self::SYS_NAME]);
        $batas = BatasPemeriksaan::maksInterface();

        $kolom = fn (string $tabel, int $nomor): array => $klien->jelajah($tabel.'.'.$nomor, $batas);

        $deskripsi = $kolom(self::IF_TABLE, 2);
        $nama = $kolom(self::IFX_TABLE, 1);
        $tipe = $kolom(self::IF_TABLE, 3);
        $kecepatan = $kolom(self::IF_TABLE, 5);
        $kecepatanTinggi = $kolom(self::IFX_TABLE, 15);
        $alamatFisik = $kolom(self::IF_TABLE, 6);
        $statusAdmin = $kolom(self::IF_TABLE, 7);
        $statusOperasional = $kolom(self::IF_TABLE, 8);
        $oktetMasuk32 = $kolom(self::IF_TABLE, 10);
        $oktetKeluar32 = $kolom(self::IF_TABLE, 16);
        $oktetMasuk64 = $kolom(self::IFX_TABLE, 6);
        $oktetKeluar64 = $kolom(self::IFX_TABLE, 10);
        $buangMasuk = $kolom(self::IF_TABLE, 13);
        $galatMasuk = $kolom(self::IF_TABLE, 14);
        $buangKeluar = $kolom(self::IF_TABLE, 19);
        $galatKeluar = $kolom(self::IF_TABLE, 20);
        $alias = $kolom(self::IFX_TABLE, 18);
        $diskontinuitas = $kolom(self::IFX_TABLE, 19);

        $sysObjectId = self::teks($sistem[self::SYS_OBJECT_ID] ?? null);
        [$sfp, $sumberSfp] = $this->bacaSfp($klien, $sysObjectId);

        $interface = [];
        $tanpaIdentitas = 0;
        $terpakai = [];

        foreach (array_unique([...array_keys($deskripsi), ...array_keys($nama)]) as $indeks) {
            $indeks = (string) $indeks;
            $namaInterface = self::teks($nama[$indeks] ?? null) ?? self::teks($deskripsi[$indeks] ?? null);

            // Tanpa nama, interface tidak dapat dikenali lagi pada pemeriksaan
            // berikutnya; nama ganda juga tidak dapat dibedakan. Keduanya dilewati.
            if ($namaInterface === null || ! ctype_digit($indeks) || isset($terpakai[mb_strtolower($namaInterface)])) {
                $tanpaIdentitas++;

                continue;
            }

            $terpakai[mb_strtolower($namaInterface)] = true;
            $punya64 = isset($oktetMasuk64[$indeks]) || isset($oktetKeluar64[$indeks]);
            $mbps = self::bilangan($kecepatanTinggi[$indeks] ?? null);
            $optik = $sfp[mb_strtolower($namaInterface)] ?? null;

            $interface[] = new DataInterface(
                ifIndex: (int) $indeks,
                nama: $namaInterface,
                alamatFisik: self::alamatFisik($alamatFisik[$indeks] ?? null),
                deskripsi: self::teks($deskripsi[$indeks] ?? null),
                alias: self::teks($alias[$indeks] ?? null),
                tipe: self::bilangan($tipe[$indeks] ?? null),
                // ifHighSpeed dalam Mbps; ifSpeed (bps) mentok di 4,29 Gbps.
                kecepatanBps: $mbps !== null && $mbps > 0 ? $mbps * 1_000_000 : self::bilangan($kecepatan[$indeks] ?? null),
                statusAdmin: self::bilangan($statusAdmin[$indeks] ?? null),
                statusOperasional: self::bilangan($statusOperasional[$indeks] ?? null),
                oktetMasuk: self::bilangan($punya64 ? ($oktetMasuk64[$indeks] ?? null) : ($oktetMasuk32[$indeks] ?? null)),
                oktetKeluar: self::bilangan($punya64 ? ($oktetKeluar64[$indeks] ?? null) : ($oktetKeluar32[$indeks] ?? null)),
                penghitung64Bit: isset($oktetMasuk64[$indeks]) || isset($oktetMasuk32[$indeks]) || isset($oktetKeluar32[$indeks]) ? $punya64 : null,
                galatMasuk: self::bilangan($galatMasuk[$indeks] ?? null),
                galatKeluar: self::bilangan($galatKeluar[$indeks] ?? null),
                buangMasuk: self::bilangan($buangMasuk[$indeks] ?? null),
                buangKeluar: self::bilangan($buangKeluar[$indeks] ?? null),
                diskontinuitasTiktik: self::tiktik($diskontinuitas[$indeks] ?? null),
                sfpRxDbm: $optik['rx_dbm'] ?? null,
                sfpTxDbm: $optik['tx_dbm'] ?? null,
                sfpSuhuC: $optik['suhu_c'] ?? null,
            );
        }

        return HasilSnmp::berhasil(
            self::teks($sistem[self::SYS_DESCR] ?? null),
            $sysObjectId === null ? null : ltrim($sysObjectId, '.'),
            self::teks($sistem[self::SYS_NAME] ?? null),
            self::tiktik($sistem[self::SYS_UPTIME] ?? null),
            $interface,
            $tanpaIdentitas,
            $sumberSfp,
        );
    }

    /**
     * @return array{0: array<string, array{rx_dbm: float|null, tx_dbm: float|null, suhu_c: float|null}>, 1: string|null}
     */
    private function bacaSfp(KlienSnmp $klien, ?string $sysObjectId): array
    {
        foreach ($this->adapterSfp as $adapter) {
            if ($adapter->mendukung($sysObjectId)) {
                try {
                    return [$adapter->baca($klien), $adapter->nama()];
                } catch (GalatSnmp) {
                    // Optik hanyalah pelengkap; kegagalannya tidak menggagalkan pemeriksaan.
                    return [[], null];
                }
            }
        }

        return [[], null];
    }

    private static function teks(?string $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $teks = trim(trim($nilai), '"');

        return $teks === '' ? null : mb_substr(mb_scrub($teks), 0, 250);
    }

    /**
     * Bilangan bulat tak negatif, atau null bila perangkat tidak memberi angka.
     */
    private static function bilangan(?string $nilai): ?int
    {
        $teks = $nilai === null ? '' : trim($nilai);

        // Counter64 di atas batas integer PHP diperlakukan sebagai tidak tersedia.
        return ctype_digit($teks) && strlen($teks) <= 18 ? (int) $teks : null;
    }

    /**
     * TimeTicks: bilangan polos, atau bentuk "(12345) 0:02:03.45".
     */
    private static function tiktik(?string $nilai): ?int
    {
        if ($nilai === null) {
            return null;
        }

        if (preg_match('/\((\d+)\)/', $nilai, $cocok) === 1) {
            return (int) $cocok[1];
        }

        return self::bilangan($nilai);
    }

    /**
     * Alamat fisik sebagai dua belas digit heksadesimal huruf kecil, apa pun
     * bentuk yang dikembalikan pustaka (biner, "0:1a:2b:..", "00 1A 2B ..").
     */
    private static function alamatFisik(?string $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (strlen($nilai) === 6 && preg_match('/^[0-9a-f:. -]+$/i', $nilai) !== 1) {
            $heks = bin2hex($nilai);
        } elseif (preg_match('/^[0-9a-f]{1,2}([:. -][0-9a-f]{1,2}){5}$/i', trim($nilai)) === 1) {
            $heks = implode('', array_map(fn (string $b) => str_pad($b, 2, '0', STR_PAD_LEFT), preg_split('/[:. -]/', trim($nilai)) ?: []));
        } else {
            $heks = preg_replace('/[^0-9a-f]/i', '', $nilai) ?? '';
        }

        $heks = strtolower($heks);

        return strlen($heks) === 12 && $heks !== '000000000000' ? $heks : null;
    }
}
