<?php

namespace App\Monitoring\Snmp;

/**
 * Sesi SNMP baca-saja ke satu perangkat. Hanya GET dan penjelajahan (walk);
 * tidak ada operasi SET di seluruh aplikasi.
 */
interface KlienSnmp
{
    /**
     * Mengambil beberapa OID sekaligus.
     *
     * @param  list<string>  $oid  OID numerik tanpa titik di depan.
     * @return array<string, string|null> OID => nilai; null bila perangkat tidak memiliki objeknya.
     *
     * @throws GalatSnmp
     */
    public function ambil(array $oid): array;

    /**
     * Menjelajahi satu kolom tabel.
     *
     * @return array<string, string> Indeks baris (bagian OID setelah akar) => nilai. Kosong bila kolom tidak didukung.
     *
     * @throws GalatSnmp
     */
    public function jelajah(string $oidAkar, int $batas): array;
}
