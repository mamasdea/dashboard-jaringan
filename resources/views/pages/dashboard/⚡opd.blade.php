<?php

use App\Dashboard\RingkasanStatus;
use App\Dashboard\StatusOpd;
use App\Enums\Izin;
use App\Models\Insiden;
use App\Models\InterfaceSnmp;
use App\Models\Opd;
use App\Models\RiwayatStatus;
use App\Models\SampelTrafik;
use App\Models\SnmpPerangkat;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Detail status satu OPD. Seperti dashboard, hanya membaca hasil monitoring
 * yang tersimpan; tidak pernah memicu pemeriksaan.
 */
new #[Title('Status koneksi OPD')] class extends Component {
    public Opd $opd;

    public function boot(): void
    {
        $this->authorize(Izin::LihatDashboard->value);
    }

    public function mount(Opd $opd): void
    {
        $this->opd = $opd;
    }

    #[Computed]
    public function ringkasan(): StatusOpd
    {
        return app(RingkasanStatus::class)->untuk($this->opd->fresh() ?? $this->opd);
    }

    #[Computed]
    public function snmp(): ?SnmpPerangkat
    {
        return $this->ringkasan->endpoint?->snmp()->first();
    }

    #[Computed]
    public function uplink(): ?InterfaceSnmp
    {
        return $this->ringkasan->endpoint?->interface()->where('uplink', true)->first();
    }

    /**
     * @return Collection<int, SampelTrafik>
     */
    #[Computed]
    public function sampel(): Collection
    {
        return $this->uplink === null
            ? new Collection
            : $this->uplink->sampel()->orderByDesc('diambil_pada')->limit(10)->get();
    }

    /**
     * @return Collection<int, Insiden>
     */
    #[Computed]
    public function insiden(): Collection
    {
        return $this->ringkasan->endpoint === null
            ? new Collection
            : $this->ringkasan->endpoint->insiden()->orderByDesc('mulai_pada')->limit(20)->get();
    }

    /**
     * @return Collection<int, RiwayatStatus>
     */
    #[Computed]
    public function riwayat(): Collection
    {
        return $this->ringkasan->endpoint === null
            ? new Collection
            : $this->ringkasan->endpoint->riwayatStatus()->orderByDesc('id')->limit(15)->get();
    }
}; ?>

<section class="w-full space-y-8" wire:poll.{{ config('dashboard.segarkan_detik') }}s data-test="detail-opd">
    @php
        $zona = config('app.display_timezone');
        $r = $this->ringkasan;
        $status = $r->status;
        $utama = $r->perangkatUtama;
        $uplink = $this->uplink;
        $waktu = fn (?\Carbon\CarbonInterface $w): string => $w === null ? \App\Support\Tampilan::TIDAK_TERSEDIA : $w->timezone($zona)->format('d-m-Y H:i:s');
        $lama = fn (\Carbon\CarbonInterface $a, ?\Carbon\CarbonInterface $b): string => \App\Support\Tampilan::durasi((int) max(0, $a->diffInSeconds($b ?? now(), absolute: false)));
        $alasanLaju = [
            'sampel_pertama' => 'menunggu sampel kedua',
            'tidak_didukung' => 'perangkat tidak menyediakan penghitung',
            'selang_tidak_valid' => 'ada pemeriksaan yang terlewat',
            'perangkat_restart' => 'perangkat baru saja restart',
            'penghitung_reset' => 'penghitung di-reset',
            'identitas_berubah' => 'interface diganti',
            'tidak_masuk_akal' => 'nilai tidak masuk akal',
        ];
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:text size="sm" class="uppercase tracking-wide">Status Koneksi OPD ke Diskominfo</flux:text>
            <flux:heading size="xl" level="1">
                {{ $opd->nama }}
                <x-lencana-simulasi :tampil="$r->simulasi()" class="ms-1" />
            </flux:heading>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <x-status-koneksi :status="$r->tampil" ukuran="lg" />
                @if ($r->keterangan())
                    <flux:text>{{ $r->keterangan() }}</flux:text>
                @elseif ($status)
                    <flux:text>sejak {{ $waktu($status->sejak) }} WIB</flux:text>
                @endif
            </div>
        </div>

        <flux:button :href="route('dashboard')" icon="arrow-left" wire:navigate>Kembali ke dashboard</flux:button>
    </div>

    <flux:text size="sm">
        Status ini menggambarkan keterjangkauan switch utama OPD dari jaringan Diskominfo, bukan layanan internet di OPD.
        Diperiksa terakhir: <span class="font-medium tabular-nums">{{ $status?->hasil_valid_terakhir_pada === null ? 'belum pernah' : $waktu($status->hasil_valid_terakhir_pada).' WIB' }}</span>.
    </flux:text>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-test="ringkasan-opd">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text size="sm">Perangkat utama</flux:text>
            @if ($utama)
                <div class="font-semibold">
                    @can(\App\Enums\Izin::LihatAset->value)
                        <flux:link :href="\App\Support\TautanAset::jalur($utama)" wire:navigate>{{ $utama->kode_aset }}</flux:link>
                    @else
                        {{ $utama->kode_aset }}
                    @endcan
                </div>
                <flux:text size="sm">{{ $utama->nama }}</flux:text>
                <flux:text size="sm">{{ collect([$utama->merek, $utama->model])->filter()->implode(' ') ?: \App\Support\Tampilan::TIDAK_TERSEDIA }}</flux:text>
                {{-- IP manajemen hanya untuk yang berhak melihat inventaris. --}}
                @can(\App\Enums\Izin::LihatAset->value)
                    <flux:text size="sm" class="tabular-nums">{{ \App\Support\Tampilan::atauTidakTersedia($utama->ip_manajemen) }}</flux:text>
                @endcan
            @else
                <div class="font-semibold">{{ \App\Support\Tampilan::TIDAK_TERSEDIA }}</div>
                <flux:text size="sm">OPD belum memiliki switch utama.</flux:text>
            @endif
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text size="sm">Latency</flux:text>
            <div class="text-2xl font-semibold tabular-nums">{{ \App\Support\Tampilan::atauTidakTersedia($r->dipantau() ? $status?->latency_ms : null, 'ms') }}</div>
            <flux:text size="sm">Packet loss {{ \App\Support\Tampilan::atauTidakTersedia($r->dipantau() ? $status?->packet_loss_persen : null, '%') }}</flux:text>
            <flux:text size="sm">Gagal berturut-turut: {{ $status?->gagal_berturut ?? 0 }}</flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text size="sm">Uptime perangkat</flux:text>
            <div class="text-2xl font-semibold">{{ \App\Support\Tampilan::durasi($this->snmp?->uptimeDetik()) }}</div>
            <flux:text size="sm">
                SNMP: {{ $status?->snmp_keluaran?->label() ?? 'tidak dicoba' }}
                @if ($status?->snmp_berhasil_terakhir_pada)
                    · berhasil terakhir {{ $waktu($status->snmp_berhasil_terakhir_pada) }}
                @endif
            </flux:text>
            @if ($this->snmp?->restart_terdeteksi_pada)
                <flux:text size="sm">Restart terdeteksi {{ $waktu($this->snmp->restart_terdeteksi_pada) }}</flux:text>
            @endif
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="port-uplink">
            <flux:text size="sm">Port uplink</flux:text>
            <div class="font-semibold">{{ \App\Support\Tampilan::atauTidakTersedia($utama?->port_uplink) }}</div>
            @if ($uplink)
                <flux:text size="sm">Status {{ \App\Models\InterfaceSnmp::labelStatus($uplink->status_admin) }}/{{ \App\Models\InterfaceSnmp::labelStatus($uplink->status_operasional) }} · {{ \App\Support\Tampilan::bps($uplink->kecepatan_bps) }}</flux:text>
                <flux:text size="sm">Masuk {{ \App\Support\Tampilan::bps($uplink->bps_masuk) }} · keluar {{ \App\Support\Tampilan::bps($uplink->bps_keluar) }}</flux:text>
                @if ($uplink->alasan_laju_kosong)
                    <flux:text size="sm">Trafik belum tersedia: {{ $alasanLaju[$uplink->alasan_laju_kosong] ?? $uplink->alasan_laju_kosong }}.</flux:text>
                @endif
            @else
                <flux:text size="sm">Status dan trafik: {{ \App\Support\Tampilan::TIDAK_TERSEDIA }} (belum ada data SNMP untuk port ini).</flux:text>
            @endif
        </div>
    </div>

    <div>
        <flux:heading class="mb-3">Trafik uplink</flux:heading>
        @if ($this->sampel->isEmpty())
            <flux:text>{{ \App\Support\Tampilan::TIDAK_TERSEDIA }}. Trafik hanya ada bila switch utama dibaca lewat SNMP dan port uplinknya dikenali.</flux:text>
        @else
            <flux:table data-test="tabel-trafik">
                <flux:table.columns>
                    <flux:table.column>Waktu (WIB)</flux:table.column>
                    <flux:table.column>Masuk</flux:table.column>
                    <flux:table.column>Keluar</flux:table.column>
                    <flux:table.column>Galat masuk/keluar</flux:table.column>
                    <flux:table.column>Buang masuk/keluar</flux:table.column>
                    <flux:table.column>Keterangan</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->sampel as $sampel)
                        <flux:table.row :key="'sampel-'.$sampel->id">
                            <flux:table.cell class="tabular-nums">{{ $waktu($sampel->diambil_pada) }}</flux:table.cell>
                            <flux:table.cell>{{ \App\Support\Tampilan::bps($sampel->bps_masuk) }}</flux:table.cell>
                            <flux:table.cell>{{ \App\Support\Tampilan::bps($sampel->bps_keluar) }}</flux:table.cell>
                            <flux:table.cell>{{ \App\Support\Tampilan::atauTidakTersedia($sampel->galat_masuk) }} / {{ \App\Support\Tampilan::atauTidakTersedia($sampel->galat_keluar) }}</flux:table.cell>
                            <flux:table.cell>{{ \App\Support\Tampilan::atauTidakTersedia($sampel->buang_masuk) }} / {{ \App\Support\Tampilan::atauTidakTersedia($sampel->buang_keluar) }}</flux:table.cell>
                            <flux:table.cell>{{ $sampel->alasan_laju_kosong ? ($alasanLaju[$sampel->alasan_laju_kosong] ?? $sampel->alasan_laju_kosong) : '' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            <flux:text size="sm" class="mt-2">Galat dan buang adalah tambahan sejak sampel sebelumnya.</flux:text>
        @endif
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        <div>
            <flux:heading class="mb-3">Riwayat gangguan ({{ $this->insiden->count() }})</flux:heading>
            @if ($this->insiden->isEmpty())
                <flux:text data-test="tanpa-riwayat-gangguan">Belum ada gangguan yang tercatat.</flux:text>
            @else
                <flux:table data-test="riwayat-gangguan">
                    <flux:table.columns>
                        <flux:table.column>Mulai (WIB)</flux:table.column>
                        <flux:table.column>Selesai (WIB)</flux:table.column>
                        <flux:table.column>Lama</flux:table.column>
                        <flux:table.column>Keterangan</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->insiden as $insiden)
                            <flux:table.row :key="'insiden-'.$insiden->id">
                                <flux:table.cell class="tabular-nums">{{ $waktu($insiden->mulai_pada) }}</flux:table.cell>
                                <flux:table.cell class="tabular-nums">{{ $insiden->selesai_pada ? $waktu($insiden->selesai_pada) : 'Masih berlangsung' }}</flux:table.cell>
                                <flux:table.cell>{{ $lama($insiden->mulai_pada, $insiden->selesai_pada) }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ match ($insiden->ditutup_karena) {
                                        \App\Models\Insiden::PULIH => 'Pulih',
                                        \App\Models\Insiden::PEMELIHARAAN => 'Ditutup karena pemeliharaan',
                                        default => 'Gangguan aktif',
                                    } }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>

        <div>
            <flux:heading class="mb-3">Perubahan status terakhir</flux:heading>
            @if ($this->riwayat->isEmpty())
                <flux:text>Belum ada perubahan status yang tercatat.</flux:text>
            @else
                <flux:table data-test="riwayat-status">
                    <flux:table.columns>
                        <flux:table.column>Waktu (WIB)</flux:table.column>
                        <flux:table.column>Dari</flux:table.column>
                        <flux:table.column>Menjadi</flux:table.column>
                        <flux:table.column>Sebab</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->riwayat as $baris)
                            <flux:table.row :key="'riwayat-'.$baris->id">
                                <flux:table.cell class="tabular-nums">{{ $waktu($baris->pada) }}</flux:table.cell>
                                <flux:table.cell>{{ $baris->dari->label() }}</flux:table.cell>
                                <flux:table.cell><x-status-koneksi :status="$baris->menjadi" /></flux:table.cell>
                                <flux:table.cell class="max-w-xs whitespace-normal">{{ $baris->alasan->label() }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>
    </div>
</section>
