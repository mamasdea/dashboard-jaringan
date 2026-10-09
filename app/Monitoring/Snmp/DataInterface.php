<?php

namespace App\Monitoring\Snmp;

/**
 * Satu interface seperti yang dilaporkan perangkat pada satu pemeriksaan.
 * Properti null berarti perangkat tidak menyediakan metriknya.
 */
final readonly class DataInterface
{
    public function __construct(
        public int $ifIndex,
        /** ifName, atau ifDescr bila perangkat tidak punya ifName. Identitas utama interface. */
        public string $nama,
        public ?string $alamatFisik = null,
        public ?string $deskripsi = null,
        public ?string $alias = null,
        public ?int $tipe = null,
        public ?int $kecepatanBps = null,
        public ?int $statusAdmin = null,
        public ?int $statusOperasional = null,
        public ?int $oktetMasuk = null,
        public ?int $oktetKeluar = null,
        public ?bool $penghitung64Bit = null,
        public ?int $galatMasuk = null,
        public ?int $galatKeluar = null,
        public ?int $buangMasuk = null,
        public ?int $buangKeluar = null,
        public ?int $diskontinuitasTiktik = null,
        public ?float $sfpRxDbm = null,
        public ?float $sfpTxDbm = null,
        public ?float $sfpSuhuC = null,
    ) {}
}
