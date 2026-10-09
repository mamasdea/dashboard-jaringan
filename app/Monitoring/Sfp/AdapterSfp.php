<?php

namespace App\Monitoring\Sfp;

use App\Monitoring\Snmp\GalatSnmp;
use App\Monitoring\Snmp\KlienSnmp;

/**
 * Pembaca informasi optik SFP untuk satu vendor.
 *
 * Tidak ada MIB standar yang dipakai semua vendor untuk daya optik, jadi tiap
 * vendor punya adapternya sendiri. Perangkat tanpa adapter yang cocok
 * menampilkan "Tidak tersedia"; nilainya tidak pernah ditebak.
 */
interface AdapterSfp
{
    public function nama(): string;

    /**
     * Apakah adapter ini mengenali perangkat dengan sysObjectID tersebut.
     */
    public function mendukung(?string $sysObjectId): bool;

    /**
     * @return array<string, array{rx_dbm: float|null, tx_dbm: float|null, suhu_c: float|null}> Nama interface (huruf kecil) => nilai optik.
     *
     * @throws GalatSnmp
     */
    public function baca(KlienSnmp $klien): array;
}
