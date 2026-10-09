<?php

namespace App\Monitoring\Snmp;

use RuntimeException;

/**
 * Kegagalan SNMP. Dibedakan apakah perangkat yang tidak melayani (tidak
 * menjawab, menolak kredensial) atau kolektor yang tidak dapat bekerja
 * (ekstensi tidak ada, kredensial tidak terbaca).
 */
class GalatSnmp extends RuntimeException
{
    private function __construct(string $pesan, public readonly string $kode, public readonly bool $salahKolektor)
    {
        parent::__construct($pesan);
    }

    public static function perangkat(string $kode, string $pesan): self
    {
        return new self($pesan, $kode, false);
    }

    public static function kolektor(string $kode, string $pesan): self
    {
        return new self($pesan, $kode, true);
    }
}
