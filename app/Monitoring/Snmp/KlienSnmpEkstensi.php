<?php

namespace App\Monitoring\Snmp;

use SNMP;
use SNMPException;

/**
 * Klien SNMP di atas ekstensi PHP `snmp`.
 *
 * Ekstensi dimuat hanya untuk proses kolektor (`php -d extension=snmp`), dan
 * kredensial diteruskan di dalam proses, tidak lewat baris perintah, sehingga
 * tidak terlihat di daftar proses. Semua OID numerik; berkas MIB tidak dipakai.
 */
class KlienSnmpEkstensi implements KlienSnmp
{
    public function __construct(private readonly SNMP $sesi) {}

    public function ambil(array $oid): array
    {
        $hasil = array_fill_keys($oid, null);

        try {
            $jawaban = @$this->sesi->get(array_map(fn (string $o) => '.'.$o, $oid));
        } catch (SNMPException $e) {
            throw $this->terjemahkan($e);
        }

        foreach (is_array($jawaban) ? $jawaban : [] as $kunci => $nilai) {
            $kunci = ltrim((string) $kunci, '.');

            if (array_key_exists($kunci, $hasil)) {
                $hasil[$kunci] = self::nilai($nilai);
            }
        }

        return $hasil;
    }

    public function jelajah(string $oidAkar, int $batas): array
    {
        try {
            // Parameter ketiga dan keempat: ukuran GETBULK. Diabaikan untuk SNMPv1.
            $jawaban = @$this->sesi->walk('.'.$oidAkar, false, 20, 0);
        } catch (SNMPException $e) {
            // Kolom yang tidak ada di perangkat bukan kegagalan sesi.
            if (in_array($e->getCode(), [SNMP::ERRNO_ERROR_IN_REPLY, SNMP::ERRNO_OID_NOT_INCREASING], true)) {
                return [];
            }

            throw $this->terjemahkan($e);
        }

        $hasil = [];
        $awalan = $oidAkar.'.';

        foreach (is_array($jawaban) ? $jawaban : [] as $kunci => $nilai) {
            $kunci = ltrim((string) $kunci, '.');
            $nilai = self::nilai($nilai);

            if (str_starts_with($kunci, $awalan) && $nilai !== null) {
                $hasil[substr($kunci, strlen($awalan))] = $nilai;
            }

            if (count($hasil) >= $batas) {
                break;
            }
        }

        return $hasil;
    }

    /**
     * "No Such Object/Instance" berarti perangkat tidak memiliki objeknya.
     */
    private static function nilai(mixed $nilai): ?string
    {
        if ($nilai === false || $nilai === null) {
            return null;
        }

        $teks = (string) $nilai;

        return preg_match('/^No Such (Object|Instance)|^No more variables/i', $teks) === 1 ? null : $teks;
    }

    private function terjemahkan(SNMPException $e): GalatSnmp
    {
        // Pesan dari pustaka tidak memuat kredensial, hanya alamat dan sebab.
        return match (true) {
            $e->getCode() === SNMP::ERRNO_TIMEOUT => GalatSnmp::perangkat('snmp_tanpa_jawaban', 'Perangkat tidak menjawab permintaan SNMP.'),
            (bool) preg_match('/authentication|decryption|unknown user|usm|security/i', $e->getMessage()) => GalatSnmp::perangkat('snmp_ditolak', 'Perangkat menolak kredensial SNMP.'),
            default => GalatSnmp::perangkat('snmp_galat', 'Permintaan SNMP gagal (kode '.$e->getCode().').'),
        };
    }
}
