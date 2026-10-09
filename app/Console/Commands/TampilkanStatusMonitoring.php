<?php

namespace App\Console\Commands;

use App\Models\EndpointMonitoring;
use App\Models\Insiden;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitoring:status')]
#[Description('Menampilkan status terkini setiap endpoint monitoring')]
class TampilkanStatusMonitoring extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $zona = (string) config('app.display_timezone');
        $endpoint = EndpointMonitoring::query()->with('status')->orderBy('nama')->get();

        if ($endpoint->isEmpty()) {
            $this->info('Belum ada endpoint monitoring. Aktifkan monitoring pada perangkat yang memiliki IP manajemen.');

            return self::SUCCESS;
        }

        $this->table(
            ['Endpoint', 'Alamat', 'Status', 'Sejak (WIB)', 'Gagal', 'Latency', 'Loss', 'Diperiksa (WIB)', 'SNMP', ''],
            $endpoint->map(function (EndpointMonitoring $e) use ($zona): array {
                $s = $e->status;
                $s?->setRelation('endpoint', $e);

                return [
                    $e->nama,
                    $e->alamat,
                    $s?->statusTampil()->label() ?? '—',
                    $s?->sejak->timezone($zona)->format('d-m H:i:s') ?? '—',
                    $s === null ? '—' : $s->gagal_berturut,
                    $s?->latency_ms !== null ? $s->latency_ms.' ms' : '—',
                    $s?->packet_loss_persen !== null ? $s->packet_loss_persen.' %' : '—',
                    $s?->hasil_valid_terakhir_pada?->timezone($zona)->format('d-m H:i:s') ?? 'belum pernah',
                    // SNMP dicatat terpisah dari keterjangkauan.
                    $s?->snmp_keluaran?->label() ?? 'tidak dicoba',
                    $e->simulasi || $s?->simulasi ? 'SIMULASI' : '',
                ];
            })->all(),
        );

        $this->line('Insiden terbuka: '.Insiden::query()->whereNull('selesai_pada')->count());
        $this->line('Status menggambarkan koneksi endpoint ke Diskominfo, bukan akses internet OPD.');

        return self::SUCCESS;
    }
}
