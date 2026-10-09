<?php

namespace App\Monitoring\Snmp;

use App\Enums\KeluaranPemeriksaan;

/**
 * Hasil bagian SNMP dari satu pemeriksaan, terpisah dari hasil ICMP.
 *
 * Setiap metrik yang tidak didukung perangkat bernilai null ("Tidak
 * tersedia"); nol hanya dipakai bila perangkat memang melaporkan nol.
 */
final readonly class HasilSnmp
{
    /**
     * @param  list<DataInterface>  $interface
     */
    private function __construct(
        public KeluaranPemeriksaan $keluaran,
        public ?string $kodeGalat = null,
        public ?string $pesan = null,
        public ?string $sysDescr = null,
        public ?string $sysObjectId = null,
        public ?string $sysName = null,
        public ?int $uptimeTiktik = null,
        public array $interface = [],
        public int $interfaceTanpaIdentitas = 0,
        public ?string $sumberSfp = null,
    ) {}

    /**
     * @param  list<DataInterface>  $interface
     */
    public static function berhasil(
        ?string $sysDescr,
        ?string $sysObjectId,
        ?string $sysName,
        ?int $uptimeTiktik,
        array $interface,
        int $interfaceTanpaIdentitas = 0,
        ?string $sumberSfp = null,
    ): self {
        return new self(KeluaranPemeriksaan::Berhasil, null, null, $sysDescr, $sysObjectId, $sysName, $uptimeTiktik, $interface, $interfaceTanpaIdentitas, $sumberSfp);
    }

    public static function gagal(GalatSnmp $galat): self
    {
        return new self(
            $galat->salahKolektor ? KeluaranPemeriksaan::GalatKolektor : KeluaranPemeriksaan::PerangkatGagal,
            $galat->kode,
            $galat->getMessage(),
        );
    }

    public static function galatKolektor(string $kode, string $pesan): self
    {
        return new self(KeluaranPemeriksaan::GalatKolektor, $kode, $pesan);
    }

    public function denganPesan(?string $pesan): self
    {
        return new self($this->keluaran, $this->kodeGalat, $pesan, $this->sysDescr, $this->sysObjectId, $this->sysName, $this->uptimeTiktik, $this->interface, $this->interfaceTanpaIdentitas, $this->sumberSfp);
    }
}
