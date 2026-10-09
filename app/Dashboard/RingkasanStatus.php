<?php

namespace App\Dashboard;

use App\Enums\StatusMonitoring;
use App\Models\Insiden;
use App\Models\Opd;
use App\Models\StatusTerkini;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Pembaca data dashboard. Hanya membaca hasil monitoring yang tersimpan;
 * tidak pernah memeriksa perangkat dan tidak mengubah apa pun.
 */
class RingkasanStatus
{
    /**
     * Status tiap OPD aktif, diurutkan dari yang paling perlu perhatian.
     *
     * @return Collection<int, StatusOpd>
     */
    public function opd(?CarbonInterface $pada = null): Collection
    {
        $opd = Opd::query()
            ->where('aktif', true)
            ->with(['switchUtama.endpoint.status'])
            ->orderBy('nama')
            ->get();

        $insiden = Insiden::query()
            ->whereNull('selesai_pada')
            ->whereIn('endpoint_id', $opd->map(fn (Opd $o) => $o->switchUtama?->endpoint?->id)->filter()->all())
            ->get()
            ->keyBy('endpoint_id');

        return $opd
            ->map(fn (Opd $o) => StatusOpd::dari($o, $insiden->get($o->switchUtama->endpoint->id ?? 0), $pada))
            ->sortBy(fn (StatusOpd $s) => [self::urutan($s->tampil), $s->opd->nama])
            ->values();
    }

    public function untuk(Opd $opd, ?CarbonInterface $pada = null): StatusOpd
    {
        $opd->loadMissing('switchUtama.endpoint.status');
        $endpointId = $opd->switchUtama?->endpoint?->id;

        return StatusOpd::dari(
            $opd,
            $endpointId === null ? null : Insiden::query()->where('endpoint_id', $endpointId)->whereNull('selesai_pada')->first(),
            $pada,
        );
    }

    /**
     * Jumlah OPD per status; kelima status selalu ada, termasuk yang nol.
     *
     * @param  Collection<int, StatusOpd>  $daftar
     * @return array<string, int>
     */
    public function jumlah(Collection $daftar): array
    {
        $jumlah = array_fill_keys(array_map(fn (StatusMonitoring $s) => $s->value, StatusMonitoring::cases()), 0);

        foreach ($daftar as $butir) {
            $jumlah[$butir->tampil->value]++;
        }

        return $jumlah;
    }

    /**
     * Pemeriksaan terakhir yang benar-benar menguji sebuah endpoint.
     */
    public function pemeriksaanTerakhir(): ?CarbonImmutable
    {
        $terakhir = StatusTerkini::query()->max('hasil_valid_terakhir_pada');

        return $terakhir === null ? null : CarbonImmutable::parse($terakhir, 'UTC');
    }

    private static function urutan(StatusMonitoring $status): int
    {
        return match ($status) {
            StatusMonitoring::TidakTerjangkau => 0,
            StatusMonitoring::KoneksiMenurun => 1,
            StatusMonitoring::BelumDiketahui => 2,
            StatusMonitoring::Pemeliharaan => 3,
            StatusMonitoring::Normal => 4,
        };
    }
}
