<?php

use App\Dashboard\KesehatanKolektor;
use App\Dashboard\RingkasanStatus;
use App\Dashboard\StatusOpd;
use App\Enums\Izin;
use App\Enums\StatusMonitoring;
use App\Models\Insiden;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Dashboard status. Seluruh isinya dibaca dari hasil monitoring yang sudah
 * tersimpan; membuka atau menyegarkan halaman ini tidak pernah memicu ping
 * maupun SNMP. Pemeriksaan hanya dijalankan proses kolektor.
 */
new #[Title('Status Koneksi OPD ke Diskominfo')] class extends Component {
    #[Url]
    public string $status = '';

    /**
     * Dijalankan pada setiap request ke komponen ini, termasuk penyegaran berkala.
     */
    public function boot(): void
    {
        $this->authorize(Izin::LihatDashboard->value);
    }

    /**
     * @return Collection<int, StatusOpd>
     */
    #[Computed]
    public function daftar(): Collection
    {
        return app(RingkasanStatus::class)->opd();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function jumlah(): array
    {
        return app(RingkasanStatus::class)->jumlah($this->daftar);
    }

    /**
     * @return Collection<int, StatusOpd>
     */
    #[Computed]
    public function tersaring(): Collection
    {
        $pilihan = StatusMonitoring::tryFrom($this->status);

        return $pilihan === null ? $this->daftar : $this->daftar->filter(fn (StatusOpd $s) => $s->tampil === $pilihan)->values();
    }

    /**
     * Gangguan aktif: insiden yang belum ditutup, terlama di atas.
     *
     * @return Collection<int, StatusOpd>
     */
    #[Computed]
    public function gangguan(): Collection
    {
        return $this->daftar
            ->filter(fn (StatusOpd $s) => $s->insidenTerbuka !== null)
            ->sortBy(fn (StatusOpd $s) => $s->insidenTerbuka?->mulai_pada)
            ->values();
    }

    /**
     * Tiket yang masih terbuka untuk endpoint yang sedang terganggu.
     *
     * @return Collection<int, \App\Models\Tiket> Berkunci id endpoint.
     */
    #[Computed]
    public function tiketGangguan(): Collection
    {
        return \App\Models\Tiket::query()->terbuka()
            ->whereIn('endpoint_id', $this->gangguan->map(fn (StatusOpd $s) => $s->endpoint?->id)->filter()->all())
            ->orderByDesc('id')->get()->keyBy('endpoint_id');
    }

    #[Computed]
    public function pemeriksaanTerakhir(): ?CarbonImmutable
    {
        return app(RingkasanStatus::class)->pemeriksaanTerakhir();
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function kolektor(): array
    {
        return app(KesehatanKolektor::class)->periksa();
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function konfigurasiPeta(): array
    {
        return [
            'urlData' => route('dashboard.peta'),
            'segarkanDetik' => config('dashboard.segarkan_detik'),
            'tile' => [
                'url' => config('peta.tile.url'),
                'atribusi' => config('peta.tile.atribusi'),
                'subdomain' => config('peta.tile.subdomain'),
                'zoomMaks' => config('peta.tile.zoom_maks'),
            ],
            'pusat' => [config('peta.pusat.lintang'), config('peta.pusat.bujur')],
            'zoom' => config('peta.zoom'),
        ];
    }
}; ?>

@assets
    @vite(['resources/js/peta-status.js'])
@endassets

{{-- wire:poll hanya menjalankan ulang pembacaan database di atas. --}}
<section class="w-full space-y-8" wire:poll.{{ config('dashboard.segarkan_detik') }}s data-test="dashboard-status">
    @php
        $zona = config('app.display_timezone');
        $durasi = fn (\Carbon\CarbonInterface $mulai): string => \App\Support\Tampilan::durasi((int) max(0, $mulai->diffInSeconds(now(), absolute: false)));
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Status Koneksi OPD ke Diskominfo</flux:heading>
            <flux:subheading size="lg">
                Keterjangkauan switch utama tiap OPD dari jaringan Diskominfo. Bukan ukuran layanan internet di OPD.
            </flux:subheading>
        </div>

        <div class="text-end text-sm" data-test="waktu-pemeriksaan">
            <flux:text size="sm">Pemeriksaan terakhir</flux:text>
            <div class="font-semibold tabular-nums">
                {{ $this->pemeriksaanTerakhir === null ? 'Belum ada pemeriksaan' : $this->pemeriksaanTerakhir->timezone($zona)->format('d-m-Y H:i:s').' WIB' }}
            </div>
            <flux:text size="sm">
                Halaman dibaca ulang tiap {{ config('dashboard.segarkan_detik') }} detik · terakhir {{ now()->timezone($zona)->format('H:i:s') }} WIB
            </flux:text>
        </div>
    </div>

    @if ($this->daftar->contains(fn ($s) => $s->simulasi()) || $this->kolektor['simulasi'])
        <flux:callout icon="beaker" color="fuchsia" data-test="peringatan-simulasi">
            <flux:callout.heading>Halaman ini memuat data SIMULASI</flux:callout.heading>
            <flux:callout.text>
                Baris berlencana SIMULASI bukan hasil pemeriksaan perangkat sungguhan.
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Jumlah OPD per status. Tiap kartu juga tombol saring. --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" data-test="jumlah-status">
        @foreach (\App\Enums\StatusMonitoring::cases() as $pilihan)
            <button
                type="button"
                wire:click="$set('status', '{{ $status === $pilihan->value ? '' : $pilihan->value }}')"
                wire:key="jumlah-{{ $pilihan->value }}"
                aria-pressed="{{ $status === $pilihan->value ? 'true' : 'false' }}"
                data-jumlah="{{ $pilihan->value }}"
                @class([
                    'rounded-lg border p-4 text-start transition',
                    'border-zinc-800 ring-2 ring-zinc-800 dark:border-white dark:ring-white' => $status === $pilihan->value,
                    'border-zinc-200 hover:border-zinc-400 dark:border-zinc-700' => $status !== $pilihan->value,
                ])
            >
                <span class="flex items-center gap-2 text-sm font-medium">
                    <flux:icon :name="$pilihan->ikon()" variant="mini" />
                    {{ $pilihan->label() }}
                </span>
                <span class="mt-1 block text-3xl font-semibold tabular-nums">{{ $this->jumlah[$pilihan->value] }}</span>
                <span class="text-xs text-zinc-500">OPD</span>
            </button>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <flux:heading class="mb-3">Gangguan aktif ({{ $this->gangguan->count() }})</flux:heading>

            @if ($this->gangguan->isEmpty())
                <flux:text data-test="tanpa-gangguan">Tidak ada gangguan aktif yang tercatat.</flux:text>
            @else
                <flux:table data-test="gangguan-aktif">
                    <flux:table.columns>
                        <flux:table.column>OPD</flux:table.column>
                        <flux:table.column>Status saat ini</flux:table.column>
                        <flux:table.column>Mulai (WIB)</flux:table.column>
                        <flux:table.column>Lama</flux:table.column>
                        <flux:table.column>Tiket</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->gangguan as $s)
                            <flux:table.row :key="'gangguan-'.$s->opd->id">
                                <flux:table.cell variant="strong">
                                    <flux:link :href="route('dashboard.opd', $s->opd)" wire:navigate>{{ $s->opd->nama }}</flux:link>
                                    <x-lencana-simulasi :tampil="$s->simulasi()" class="ms-1" />
                                </flux:table.cell>
                                <flux:table.cell>
                                    <x-status-koneksi :status="$s->tampil" />
                                    @if ($s->tampil === \App\Enums\StatusMonitoring::BelumDiketahui)
                                        <flux:text size="sm">Belum ada bukti pulih.</flux:text>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ $s->insidenTerbuka->mulai_pada->timezone($zona)->format('d-m-Y H:i:s') }}</flux:table.cell>
                                <flux:table.cell>{{ $durasi($s->insidenTerbuka->mulai_pada) }}</flux:table.cell>
                                <flux:table.cell class="tabular-nums" data-test="tiket-gangguan">
                                    @php $tiket = $this->tiketGangguan->get($s->endpoint?->id); @endphp
                                    @if ($tiket === null)
                                        Belum ada
                                    @elseif (auth()->user()->can('view', $tiket))
                                        <flux:link :href="route('tiket.rinci', $tiket)" wire:navigate>{{ $tiket->nomor }}</flux:link>
                                    @else
                                        {{ $tiket->nomor }}
                                    @endif
                                    @if ($tiket)
                                        <flux:text size="sm">{{ $tiket->status->label() }}</flux:text>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="kesehatan-kolektor" data-kolektor="{{ $this->kolektor['status'] }}">
            <flux:heading class="mb-2">Kolektor monitoring</flux:heading>
            <flux:badge :color="$this->kolektor['warna']" :icon="$this->kolektor['ikon']">{{ $this->kolektor['label'] }}</flux:badge>
            <flux:text size="sm" class="mt-2">{{ $this->kolektor['penjelasan'] }}</flux:text>

            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                <dt class="text-zinc-500">Tanda hidup terakhir</dt>
                <dd class="tabular-nums">{{ $this->kolektor['detak_terakhir']?->timezone($zona)->format('d-m-Y H:i:s') ?? 'Belum pernah' }}</dd>
                <dt class="text-zinc-500">Proses aktif</dt>
                <dd class="tabular-nums">{{ $this->kolektor['proses_aktif'] }}</dd>
                <dt class="text-zinc-500">Adapter</dt>
                <dd>
                    {{ $this->kolektor['adapter'] ?? 'Tidak tersedia' }}
                    <x-lencana-simulasi :tampil="$this->kolektor['simulasi']" class="ms-1" />
                </dd>
                <dt class="text-zinc-500">Endpoint terlambat</dt>
                <dd class="tabular-nums">{{ $this->kolektor['endpoint_terlambat'] }}</dd>
                <dt class="text-zinc-500">Galat kolektor (15 menit)</dt>
                <dd class="tabular-nums">{{ $this->kolektor['galat_kolektor'] }}</dd>
            </dl>
        </div>
    </div>

    <div>
        <flux:heading class="mb-1">Peta status</flux:heading>
        <flux:text size="sm" class="mb-3">
            Huruf pada penanda menunjukkan status:
            @foreach (\App\Enums\StatusMonitoring::cases() as $pilihan)
                <span class="me-3 inline-flex items-center gap-1 whitespace-nowrap">
                    <span class="peta-status-penanda peta-status-{{ $pilihan->value }}" aria-hidden="true">{{ $pilihan->kode() }}</span>
                    {{ $pilihan->label() }}
                </span>
            @endforeach
        </flux:text>

        <div wire:ignore>
            <div data-peta-status class="h-96 w-full rounded-lg border border-zinc-200 dark:border-zinc-700" aria-label="Peta status koneksi OPD"></div>
            <p data-peta-status-catatan class="mt-2 text-xs text-zinc-500" role="status" aria-live="polite">Memuat peta…</p>
        </div>
    </div>

    <div>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <flux:heading>
                {{-- Nilai saringan yang tidak dikenal diperlakukan sebagai "semua". --}}
                {{ ($saringan = \App\Enums\StatusMonitoring::tryFrom($status)) === null ? 'Semua OPD' : 'OPD berstatus '.$saringan->label() }}
                ({{ $this->tersaring->count() }})
            </flux:heading>
            @if ($status !== '')
                <flux:button size="sm" wire:click="$set('status', '')">Tampilkan semua</flux:button>
            @endif
        </div>

        <flux:table data-test="tabel-opd">
            <flux:table.columns>
                <flux:table.column>OPD</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Perangkat utama</flux:table.column>
                <flux:table.column>Latency</flux:table.column>
                <flux:table.column>Packet loss</flux:table.column>
                <flux:table.column>Diperiksa terakhir (WIB)</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->tersaring as $s)
                    <flux:table.row :key="'opd-'.$s->opd->id">
                        <flux:table.cell variant="strong" class="max-w-xs whitespace-normal">
                            <flux:link :href="route('dashboard.opd', $s->opd)" wire:navigate>{{ $s->opd->nama }}</flux:link>
                            <x-lencana-simulasi :tampil="$s->simulasi()" class="ms-1" />
                        </flux:table.cell>
                        <flux:table.cell class="max-w-xs whitespace-normal">
                            <x-status-koneksi :status="$s->tampil" />
                            @if ($s->keterangan())
                                <flux:text size="sm">{{ $s->keterangan() }}</flux:text>
                            @elseif ($s->status)
                                <flux:text size="sm">sejak {{ $s->status->sejak->timezone($zona)->format('d-m H:i') }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $s->perangkatUtama?->kode_aset ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ \App\Support\Tampilan::atauTidakTersedia($s->dipantau() ? $s->status?->latency_ms : null, 'ms') }}</flux:table.cell>
                        <flux:table.cell>{{ \App\Support\Tampilan::atauTidakTersedia($s->dipantau() ? $s->status?->packet_loss_persen : null, '%') }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $s->status?->hasil_valid_terakhir_pada?->timezone($zona)->format('d-m-Y H:i:s') ?? 'Belum pernah' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">Tidak ada OPD dengan status ini.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>

@script
<script>
    const pasang = () => window.pasangPetaStatus($wire.$el, @js($this->konfigurasiPeta));

    window.pasangPetaStatus ? pasang() : window.addEventListener('peta-status:siap', pasang, { once: true });
</script>
@endscript
