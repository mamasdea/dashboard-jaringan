<?php

namespace App\Monitoring;

use App\Models\EndpointMonitoring;
use Closure;

/**
 * Yang perlu diketahui adapter tentang satu pemeriksaan.
 */
final readonly class SasaranPemeriksaan
{
    /**
     * @param  (Closure(): array<string, mixed>)|null  $kredensialSnmp  Dibuka hanya bila adapter memanggilnya.
     */
    public function __construct(
        public int $endpointId,
        public string $alamat,
        public string $metode,
        public float $batasWaktuDetik,
        public ?string $skenarioSimulasi = null,
        public ?int $portSnmp = null,
        public ?string $versiSnmp = null,
        public ?Closure $kredensialSnmp = null,
    ) {}

    public static function dari(EndpointMonitoring $endpoint): self
    {
        $profil = $endpoint->metode === 'snmp' ? $endpoint->profilSnmp : null;

        return new self(
            endpointId: $endpoint->id,
            alamat: $endpoint->alamat,
            metode: $endpoint->metode,
            batasWaktuDetik: (float) config('monitoring.batas_waktu_adapter_detik'),
            skenarioSimulasi: $endpoint->skenario_simulasi,
            portSnmp: $profil?->port,
            versiSnmp: $profil?->versi,
            kredensialSnmp: $profil === null ? null : fn (): array => $profil->kredensial(),
        );
    }
}
