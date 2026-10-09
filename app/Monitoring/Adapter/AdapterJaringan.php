<?php

namespace App\Monitoring\Adapter;

use App\Enums\KeluaranPemeriksaan;
use App\Monitoring\DaftarIzinSasaran;
use App\Monitoring\HasilAdapter;
use App\Monitoring\Icmp\PemeriksaIcmp;
use App\Monitoring\Kontrak\AdapterPemeriksa;
use App\Monitoring\SasaranPemeriksaan;
use App\Monitoring\Snmp\PemeriksaSnmp;

/**
 * Pemeriksa nyata: ICMP untuk keterjangkauan, lalu SNMP bila endpoint
 * memakai metode "snmp" dan ICMP-nya berhasil. Keduanya dicatat terpisah.
 *
 * Hanya menghubungi sasaran yang ada di daftar izin
 * (MONITORING_SASARAN_DIIZINKAN). Tidak ada pemindaian dan tidak ada
 * discovery: sasarannya selalu satu alamat dari endpoint yang terdaftar.
 */
class AdapterJaringan implements AdapterPemeriksa
{
    public function __construct(
        private readonly DaftarIzinSasaran $daftarIzin,
        private readonly PemeriksaIcmp $icmp,
        private readonly PemeriksaSnmp $snmp,
    ) {}

    public function nama(): string
    {
        return 'jaringan';
    }

    public function simulasi(): bool
    {
        return false;
    }

    public function periksa(SasaranPemeriksaan $sasaran): HasilAdapter
    {
        // Diperiksa sebelum paket apa pun dikirim.
        if (! $this->daftarIzin->mengizinkan($sasaran->alamat)) {
            return HasilAdapter::galatKolektor(
                'sasaran_tidak_diizinkan',
                "Alamat {$sasaran->alamat} tidak ada di MONITORING_SASARAN_DIIZINKAN; tidak ada paket yang dikirim.",
            );
        }

        $hasil = $this->icmp->periksa($sasaran->alamat);

        // SNMP ke perangkat yang tidak menjawab ping hanya menambah waktu tunggu;
        // bila ICMP berhasil dan SNMP gagal, keduanya tercatat apa adanya.
        if ($sasaran->metode !== 'snmp' || $hasil->keluaran !== KeluaranPemeriksaan::Berhasil) {
            return $hasil;
        }

        return $hasil->denganSnmp($this->snmp->periksa($sasaran));
    }
}
