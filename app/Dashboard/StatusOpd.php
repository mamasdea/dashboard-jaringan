<?php

namespace App\Dashboard;

use App\Enums\StatusMonitoring;
use App\Models\EndpointMonitoring;
use App\Models\Insiden;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\StatusTerkini;
use Carbon\CarbonInterface;

/**
 * Status koneksi satu OPD ke Diskominfo, seperti yang ditampilkan dashboard.
 *
 * Status OPD adalah status endpoint switch utamanya. OPD tanpa switch utama
 * yang dipantau berstatus "Belum diketahui".
 */
final readonly class StatusOpd
{
    public function __construct(
        public Opd $opd,
        public ?Perangkat $perangkatUtama,
        public ?EndpointMonitoring $endpoint,
        public ?StatusTerkini $status,
        public StatusMonitoring $tampil,
        public ?Insiden $insidenTerbuka,
    ) {}

    public static function dari(Opd $opd, ?Insiden $insidenTerbuka, ?CarbonInterface $pada = null): self
    {
        $utama = $opd->switchUtama;
        $endpoint = $utama?->endpoint;
        $status = $endpoint?->status;

        $status?->setRelation('endpoint', $endpoint);

        return new self(
            $opd,
            $utama,
            $endpoint,
            $status,
            $status === null ? StatusMonitoring::BelumDiketahui : $status->statusTampil($pada),
            $insidenTerbuka,
        );
    }

    public function dipantau(): bool
    {
        return $this->endpoint !== null && $this->endpoint->aktif;
    }

    /**
     * Mengapa statusnya "Belum diketahui", supaya tidak dikira gangguan.
     */
    public function keterangan(?CarbonInterface $pada = null): ?string
    {
        if ($this->tampil !== StatusMonitoring::BelumDiketahui) {
            return null;
        }

        return match (true) {
            $this->perangkatUtama === null => 'OPD belum memiliki switch utama.',
            ! $this->dipantau() => 'Monitoring switch utama tidak diaktifkan.',
            $this->status?->hasil_valid_terakhir_pada === null => 'Belum ada hasil pemeriksaan.',
            $this->status->kedaluwarsa($pada) => 'Hasil pemeriksaan terakhir sudah kedaluwarsa.',
            default => 'Menunggu hasil pemeriksaan.',
        };
    }

    /**
     * Data contoh harus tetap berlabel sampai ke tampilan.
     */
    public function simulasi(): bool
    {
        return $this->opd->simulasi || (bool) $this->endpoint?->simulasi || (bool) $this->status?->simulasi;
    }
}
