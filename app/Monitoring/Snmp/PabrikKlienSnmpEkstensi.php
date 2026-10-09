<?php

namespace App\Monitoring\Snmp;

use SensitiveParameter;
use SNMP;
use Throwable;

class PabrikKlienSnmpEkstensi implements PabrikKlienSnmp
{
    private const array TINGKAT = ['noAuthNoPriv', 'authNoPriv', 'authPriv'];

    private const array PROTOKOL_AUTH = ['MD5', 'SHA', 'SHA256', 'SHA512'];

    private const array PROTOKOL_PRIV = ['DES', 'AES', 'AES128'];

    public function buat(
        string $alamat,
        int $port,
        string $versi,
        #[SensitiveParameter] array $kredensial,
        int $batasWaktuMs,
        int $percobaanUlang,
    ): KlienSnmp {
        if (! extension_loaded('snmp')) {
            throw GalatSnmp::kolektor('ekstensi_snmp_tidak_ada', 'Ekstensi PHP snmp tidak dimuat. Jalankan kolektor dengan `php -d extension=snmp`.');
        }

        if (filter_var($alamat, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            throw GalatSnmp::kolektor('alamat_tidak_valid', 'Sasaran SNMP harus berupa alamat IP dan port yang valid.');
        }

        if (! in_array($versi, ['v2c', 'v3'], true)) {
            throw GalatSnmp::kolektor('versi_tidak_didukung', 'Hanya SNMPv3 dan SNMPv2c yang didukung.');
        }

        // v2c: community. v3: nama pengguna. Keduanya diteruskan lewat parameter yang sama.
        $nama = (string) ($kredensial[$versi === 'v2c' ? 'community' : 'pengguna'] ?? '');

        if ($nama === '') {
            throw GalatSnmp::kolektor('kredensial_tidak_valid', 'Kredensial SNMP kosong.');
        }

        $tujuan = (str_contains($alamat, ':') ? "udp6:[{$alamat}]" : $alamat).':'.$port;

        try {
            $sesi = new SNMP(
                $versi === 'v2c' ? SNMP::VERSION_2c : SNMP::VERSION_3,
                $tujuan,
                $nama,
                max(0, $batasWaktuMs) * 1000,
                max(0, $percobaanUlang),
            );

            if ($versi === 'v3') {
                $this->aturKeamanan($sesi, $kredensial);
            }
        } catch (GalatSnmp $e) {
            throw $e;
        } catch (Throwable $e) {
            // Pesan pustaka dibuang: bisa memuat parameter keamanan.
            throw GalatSnmp::kolektor('sesi_snmp_gagal', 'Sesi SNMP tidak dapat dibuat ('.$e::class.').');
        }

        $sesi->exceptions_enabled = SNMP::ERRNO_ANY;
        $sesi->valueretrieval = SNMP_VALUE_PLAIN;
        $sesi->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
        $sesi->quick_print = true;
        $sesi->enum_print = false;
        $sesi->oid_increasing_check = true;

        return new KlienSnmpEkstensi($sesi);
    }

    /**
     * @param  array<string, mixed>  $kredensial
     */
    private function aturKeamanan(SNMP $sesi, #[SensitiveParameter] array $kredensial): void
    {
        $tingkat = (string) ($kredensial['tingkat_keamanan'] ?? 'authPriv');
        $auth = strtoupper((string) ($kredensial['protokol_auth'] ?? 'SHA'));
        $priv = strtoupper((string) ($kredensial['protokol_priv'] ?? 'AES'));
        $sandiAuth = (string) ($kredensial['sandi_auth'] ?? '');
        $sandiPriv = (string) ($kredensial['sandi_priv'] ?? '');

        if (! in_array($tingkat, self::TINGKAT, true)) {
            throw GalatSnmp::kolektor('kredensial_tidak_valid', 'Tingkat keamanan SNMPv3 tidak dikenal.');
        }

        if ($tingkat === 'noAuthNoPriv') {
            $sesi->setSecurity('noAuthNoPriv');

            return;
        }

        if (! in_array($auth, self::PROTOKOL_AUTH, true) || $sandiAuth === '') {
            throw GalatSnmp::kolektor('kredensial_tidak_valid', 'Protokol atau sandi autentikasi SNMPv3 tidak valid.');
        }

        if ($tingkat === 'authNoPriv') {
            $sesi->setSecurity('authNoPriv', $auth, $sandiAuth);

            return;
        }

        if (! in_array($priv, self::PROTOKOL_PRIV, true) || $sandiPriv === '') {
            throw GalatSnmp::kolektor('kredensial_tidak_valid', 'Protokol atau sandi privasi SNMPv3 tidak valid (didukung: DES, AES/AES128).');
        }

        $sesi->setSecurity('authPriv', $auth, $sandiAuth, $priv, $sandiPriv);
    }
}
