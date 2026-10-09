<?php

use App\Enums\StatusPemakaian;
use App\Fo\LayananHubungan;
use App\Models\CoreKabel;
use App\Models\KoneksiEndpoint;
use App\Models\Perangkat;
use App\Models\PortPerangkat;
use App\Models\PortTerminasi;
use App\Models\RiwayatHubungan;
use App\Models\Sambungan;
use App\Models\SegmenKabel;
use App\Models\TitikJaringan;
use App\Validasi\Normalisasi;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rincian titik jaringan')] class extends Component {
    public TitikJaringan $titik;

    // Formulir sambungan.
    public string $sb_segmen_masuk = '';
    public string $sb_core_masuk = '';
    public string $sb_segmen_keluar = '';
    public string $sb_core_keluar = '';
    public string $sb_catatan = '';

    // Formulir terminasi.
    public string $tm_port = '';
    public string $tm_segmen = '';
    public string $tm_core = '';

    // Formulir koneksi endpoint.
    public string $ep_perangkat = '';
    public string $ep_port_perangkat = '';
    public string $ep_lewat = 'port';
    public string $ep_port = '';
    public string $ep_segmen = '';
    public string $ep_core = '';
    public string $ep_catatan = '';

    /**
     * Dijalankan pada setiap request ke komponen ini, termasuk pemanggilan
     * aksi secara langsung.
     */
    public function boot(): void
    {
        $this->authorize('viewAny', TitikJaringan::class);
    }

    public function mount(TitikJaringan $titik): void
    {
        $this->authorize('view', $titik);

        $this->titik = $titik;
        $this->ep_lewat = $titik->jenis->berPort() ? 'port' : 'core';
    }

    /**
     * Segmen yang berujung di titik ini.
     *
     * @return Collection<int, SegmenKabel>
     */
    #[Computed]
    public function segmen(): Collection
    {
        return $this->titik->segmen()->with(['core', 'titikAwal:id,kode', 'titikAkhir:id,kode'])->orderBy('kode')->get();
    }

    /**
     * ID core yang ujungnya di titik ini sudah dipakai.
     *
     * @return list<int>
     */
    #[Computed]
    public function coreTerpakaiDiSini(): array
    {
        return DB::table('pemakaian_ujung_core')->where('titik_jaringan_id', $this->titik->id)->pluck('core_id')->map(intval(...))->all();
    }

    /**
     * Core yang masih dapat dipakai di titik ini, per segmen.
     *
     * @return array<int, list<CoreKabel>>
     */
    #[Computed]
    public function coreBebas(): array
    {
        return $this->segmen
            ->mapWithKeys(fn (SegmenKabel $s) => [$s->id => $s->core
                ->filter(fn (CoreKabel $c) => $c->status !== StatusPemakaian::Rusak && ! in_array($c->id, $this->coreTerpakaiDiSini, true))
                ->values()
                ->all()])
            ->all();
    }

    /**
     * @return Collection<int, Sambungan>
     */
    #[Computed]
    public function daftarSambungan(): Collection
    {
        return $this->titik->sambungan()->with(['coreMasuk.segmen', 'coreKeluar.segmen'])->orderBy('id')->get();
    }

    /**
     * @return Collection<int, PortTerminasi>
     */
    #[Computed]
    public function daftarPort(): Collection
    {
        return $this->titik->port()->with(['core.segmen', 'koneksiEndpoint.portPerangkat.perangkat'])->get();
    }

    /**
     * Koneksi endpoint yang langsung ke core di titik ini (tanpa port).
     *
     * @return Collection<int, KoneksiEndpoint>
     */
    #[Computed]
    public function endpointLangsung(): Collection
    {
        return KoneksiEndpoint::query()
            ->with(['core.segmen', 'portPerangkat.perangkat'])
            ->where('titik_jaringan_id', $this->titik->id)
            ->get();
    }

    /**
     * @return Collection<int, Perangkat>
     */
    #[Computed]
    public function pilihanPerangkat(): Collection
    {
        return Perangkat::query()->orderBy('kode_aset')->get(['id', 'kode_aset', 'nama']);
    }

    /**
     * @return Collection<int, RiwayatHubungan>
     */
    #[Computed]
    public function riwayat(): Collection
    {
        return RiwayatHubungan::query()->where('titik_jaringan_id', $this->titik->id)->orderByDesc('id')->limit(50)->get();
    }

    public function updated(string $properti): void
    {
        // Pilihan core bergantung pada segmen yang dipilih.
        match ($properti) {
            'sb_segmen_masuk' => $this->sb_core_masuk = '',
            'sb_segmen_keluar' => $this->sb_core_keluar = '',
            'tm_segmen' => $this->tm_core = '',
            'ep_segmen' => $this->ep_core = '',
            default => null,
        };
    }

    public function sambung(LayananHubungan $layanan): void
    {
        $this->authorize('update', $this->titik);
        $this->resetValidation();

        $masuk = $this->coreDipilih($this->sb_core_masuk, 'sb_core_masuk');
        $keluar = $this->coreDipilih($this->sb_core_keluar, 'sb_core_keluar');

        if ($masuk === null || $keluar === null) {
            return;
        }

        $layanan->sambung($this->titik, $masuk, $keluar, Normalisasi::teks($this->sb_catatan));

        $this->reset('sb_core_masuk', 'sb_core_keluar', 'sb_catatan');
        $this->segarkan('Core disambung.');
    }

    public function lepasSambungan(LayananHubungan $layanan, int $id): void
    {
        $this->authorize('update', $this->titik);

        $layanan->lepasSambungan($this->titik->sambungan()->findOrFail($id));

        $this->segarkan('Sambungan dilepas.');
    }

    public function terminasikan(LayananHubungan $layanan): void
    {
        $this->authorize('update', $this->titik);
        $this->resetValidation();

        $port = $this->portDipilih($this->tm_port, 'tm_port');
        $core = $this->coreDipilih($this->tm_core, 'tm_core');

        if ($port === null || $core === null) {
            return;
        }

        $layanan->terminasikan($port, $core);

        $this->reset('tm_port', 'tm_core');
        $this->segarkan('Core diterminasi ke port.');
    }

    public function lepasTerminasi(LayananHubungan $layanan, int $portId): void
    {
        $this->authorize('update', $this->titik);

        $layanan->lepasTerminasi($this->titik->port()->findOrFail($portId));

        $this->segarkan('Terminasi dilepas.');
    }

    public function hubungkanEndpoint(LayananHubungan $layanan): void
    {
        $this->authorize('update', $this->titik);
        $this->resetValidation();

        $perangkat = ctype_digit($this->ep_perangkat) ? Perangkat::find((int) $this->ep_perangkat) : null;
        $namaPort = Normalisasi::teks($this->ep_port_perangkat);

        if ($perangkat === null) {
            $this->addError('ep_perangkat', 'Pilih perangkat.');
        }

        if ($namaPort === null || mb_strlen($namaPort) > 100) {
            $this->addError('ep_port_perangkat', 'Isi nama port perangkat (paling banyak 100 karakter).');
        }

        $port = $this->ep_lewat === 'port' ? $this->portDipilih($this->ep_port, 'ep_port') : null;
        $core = $this->ep_lewat === 'core' ? $this->coreDipilih($this->ep_core, 'ep_core') : null;

        if ($this->getErrorBag()->isNotEmpty() || $perangkat === null || $namaPort === null) {
            return;
        }

        DB::transaction(function () use ($layanan, $perangkat, $namaPort, $port, $core): void {
            // Port perangkat didaftarkan saat pertama kali dipakai.
            $portPerangkat = PortPerangkat::firstOrCreate(['perangkat_id' => $perangkat->id, 'nama' => $namaPort]);

            $layanan->hubungkanEndpoint($portPerangkat, $port, $core, $core === null ? null : $this->titik, Normalisasi::teks($this->ep_catatan));
        });

        $this->reset('ep_port_perangkat', 'ep_port', 'ep_core', 'ep_catatan');
        $this->segarkan('Port perangkat dihubungkan.');
    }

    public function lepasEndpoint(LayananHubungan $layanan, int $id): void
    {
        $this->authorize('update', $this->titik);

        $koneksi = KoneksiEndpoint::query()
            ->where(fn ($query) => $query
                ->where('titik_jaringan_id', $this->titik->id)
                ->orWhereIn('port_terminasi_id', $this->titik->port()->select('id')))
            ->findOrFail($id);

        $layanan->lepasEndpoint($koneksi);

        $this->segarkan('Koneksi endpoint dilepas.');
    }

    public function tandaiPort(LayananHubungan $layanan, int $portId): void
    {
        $this->authorize('update', $this->titik);

        $port = $this->titik->port()->findOrFail($portId);

        $layanan->tandaiPort($port, $port->status !== StatusPemakaian::Rusak);

        $this->segarkan(null);
    }

    /**
     * Core hanya diterima bila berasal dari segmen yang berujung di titik ini.
     */
    private function coreDipilih(string $id, string $kolom): ?CoreKabel
    {
        $core = ctype_digit($id)
            ? CoreKabel::query()->with('segmen')->whereIn('segmen_kabel_id', $this->segmen->modelKeys())->find((int) $id)
            : null;

        if ($core === null) {
            $this->addError($kolom, 'Pilih core.');
        }

        return $core;
    }

    private function portDipilih(string $id, string $kolom): ?PortTerminasi
    {
        $port = ctype_digit($id) ? $this->titik->port()->with('titik')->find((int) $id) : null;

        if ($port === null) {
            $this->addError($kolom, 'Pilih port.');
        }

        return $port;
    }

    private function segarkan(?string $pesan): void
    {
        // Aksi berhasil: pesan kesalahan dari percobaan sebelumnya tidak lagi berlaku.
        $this->resetValidation();

        unset(
            $this->segmen, $this->coreTerpakaiDiSini, $this->coreBebas, $this->daftarSambungan,
            $this->daftarPort, $this->endpointLangsung, $this->riwayat,
        );

        if ($pesan !== null) {
            Flux::toast(variant: 'success', text: $pesan);
        }
    }
}; ?>

<section class="w-full space-y-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">
                {{ $titik->kode }}
                <x-lencana-simulasi :tampil="$titik->simulasi" class="ms-1" />
            </flux:heading>
            <flux:subheading size="lg">
                {{ $titik->jenis->label() }}{{ $titik->nama ? ' · '.$titik->nama : '' }}
                {{ $titik->opd ? ' · OPD '.$titik->opd->kode : '' }}{{ $titik->tiang ? ' · Tiang '.$titik->tiang->kode : '' }}{{ $titik->lokasi ? ' · '.$titik->lokasi : '' }}
            </flux:subheading>
            <div class="mt-1"><x-status-data :objek="$titik" /></div>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('fo.penelusuran', ['jenis' => 'titik', 'kode' => $titik->kode])" icon="arrows-right-left" wire:navigate>Potensi terdampak</flux:button>
            <flux:button :href="route('fo.titik')" icon="arrow-left" wire:navigate>Kembali ke daftar</flux:button>
        </div>
    </div>

    <flux:error name="hubungan" data-test="galat-hubungan" />

    <div>
        <flux:heading class="mb-3">Kabel yang berujung di sini</flux:heading>
        @if ($this->segmen->isEmpty())
            <flux:text>Belum ada segmen kabel yang titik awal atau akhirnya di sini. Atur di daftar segmen kabel.</flux:text>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-test="kabel-di-titik">
                @foreach ($this->segmen as $s)
                    <div class="rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700" wire:key="segmen-{{ $s->id }}">
                        <flux:link :href="route('fo.kabel.rinci', $s)" wire:navigate class="font-semibold">{{ $s->kode }}</flux:link>
                        <flux:text size="sm">
                            {{ $s->klasifikasi?->label() ?? 'Klasifikasi belum ditentukan' }} ·
                            {{ $s->titikAwal?->kode ?? '?' }} → {{ $s->titikAkhir?->kode ?? '?' }}
                        </flux:text>
                        <flux:text size="sm">{{ count($this->coreBebas[$s->id]) }} dari {{ $s->core->count() }} core masih bebas di titik ini</flux:text>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div>
        <flux:heading class="mb-3">Sambungan core ({{ $this->daftarSambungan->count() }})</flux:heading>

        @if ($this->daftarSambungan->isNotEmpty())
            <flux:table class="mb-4" data-test="tabel-sambungan">
                <flux:table.columns>
                    <flux:table.column>Core masuk</flux:table.column>
                    <flux:table.column>Core keluar</flux:table.column>
                    <flux:table.column>Catatan</flux:table.column>
                    <flux:table.column align="end">Tindakan</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->daftarSambungan as $sb)
                        <flux:table.row :key="$sb->id">
                            <flux:table.cell variant="strong">{{ $sb->coreMasuk->label() }}</flux:table.cell>
                            <flux:table.cell variant="strong">{{ $sb->coreKeluar->label() }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal">{{ $sb->catatan ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="end">
                                @can('update', $titik)
                                    <flux:button size="sm" variant="ghost" wire:click="lepasSambungan({{ $sb->id }})" wire:confirm="Lepas sambungan ini?">Lepas</flux:button>
                                @endcan
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        @can('update', $titik)
            @if ($this->segmen->count() >= 1)
                <form wire:submit="sambung" class="grid gap-3 rounded-lg border border-zinc-200 p-4 sm:grid-cols-2 lg:grid-cols-5 dark:border-zinc-700">
                    @foreach (['masuk' => 'Kabel masuk', 'keluar' => 'Kabel keluar'] as $arah => $label)
                        <flux:select wire:model.live="sb_segmen_{{ $arah }}" :label="$label">
                            <flux:select.option value="">Pilih kabel</flux:select.option>
                            @foreach ($this->segmen as $s)
                                <flux:select.option :value="$s->id">{{ $s->kode }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="sb_core_{{ $arah }}" label="Core {{ $arah }}" wire:key="sb-core-{{ $arah }}-{{ $this->{'sb_segmen_'.$arah} }}">
                            <flux:select.option value="">Pilih core</flux:select.option>
                            @foreach ($this->coreBebas[(int) $this->{'sb_segmen_'.$arah}] ?? [] as $c)
                                <flux:select.option :value="$c->id">{{ $c->nomor }} · {{ $c->warna }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endforeach
                    <div class="flex items-end">
                        <flux:button type="submit" variant="primary" data-test="sambung">Sambung</flux:button>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-5">
                        <flux:input wire:model="sb_catatan" placeholder="Catatan sambungan (opsional)" aria-label="Catatan sambungan" />
                    </div>
                </form>
            @endif
        @endcan
    </div>

    @if ($titik->jenis->berPort())
        <div>
            <flux:heading class="mb-3">Port terminasi ({{ $this->daftarPort->count() }})</flux:heading>

            @if ($this->daftarPort->isEmpty())
                <flux:text>Kapasitas port belum diisi. Lengkapi dari daftar titik jaringan.</flux:text>
            @else
                <flux:table class="mb-4" data-test="tabel-port">
                    <flux:table.columns>
                        <flux:table.column>Port</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column>Core diterminasi</flux:table.column>
                        <flux:table.column>Terhubung ke perangkat</flux:table.column>
                        <flux:table.column align="end">Tindakan</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->daftarPort as $port)
                            <flux:table.row :key="$port->id">
                                <flux:table.cell variant="strong">{{ $port->nomor }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="['tersedia' => 'green', 'terpakai' => 'sky', 'rusak' => 'red'][$port->status->value]">{{ $port->status->label() }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>{{ $port->core?->label() ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $port->koneksiEndpoint?->portPerangkat->label() ?? '—' }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    @can('update', $titik)
                                        <div class="flex justify-end gap-1">
                                            @if ($port->core_id !== null)
                                                <flux:button size="sm" variant="ghost" wire:click="lepasTerminasi({{ $port->id }})" wire:confirm="Lepas core dari port {{ $port->nomor }}?">Lepas core</flux:button>
                                            @endif
                                            @if ($port->koneksiEndpoint)
                                                <flux:button size="sm" variant="ghost" wire:click="lepasEndpoint({{ $port->koneksiEndpoint->id }})" wire:confirm="Lepas koneksi perangkat dari port {{ $port->nomor }}?">Lepas perangkat</flux:button>
                                            @endif
                                            <flux:button size="sm" variant="ghost" wire:click="tandaiPort({{ $port->id }})">
                                                {{ $port->status === \App\Enums\StatusPemakaian::Rusak ? 'Pulihkan' : 'Tandai rusak' }}
                                            </flux:button>
                                        </div>
                                    @endcan
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                @can('update', $titik)
                    <form wire:submit="terminasikan" class="grid gap-3 rounded-lg border border-zinc-200 p-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-zinc-700">
                        <flux:select wire:model="tm_port" label="Port">
                            <flux:select.option value="">Pilih port kosong</flux:select.option>
                            @foreach ($this->daftarPort->filter(fn ($p) => $p->core_id === null && $p->status !== \App\Enums\StatusPemakaian::Rusak) as $p)
                                <flux:select.option :value="$p->id">{{ $p->nomor }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model.live="tm_segmen" label="Kabel">
                            <flux:select.option value="">Pilih kabel</flux:select.option>
                            @foreach ($this->segmen as $s)
                                <flux:select.option :value="$s->id">{{ $s->kode }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="tm_core" label="Core" wire:key="tm-core-{{ $tm_segmen }}">
                            <flux:select.option value="">Pilih core</flux:select.option>
                            @foreach ($this->coreBebas[(int) $tm_segmen] ?? [] as $c)
                                <flux:select.option :value="$c->id">{{ $c->nomor }} · {{ $c->warna }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <div class="flex items-end">
                            <flux:button type="submit" variant="primary" data-test="terminasikan">Terminasikan</flux:button>
                        </div>
                    </form>
                @endcan
            @endif
        </div>
    @endif

    <div>
        <flux:heading class="mb-1">Koneksi endpoint</flux:heading>
        <flux:text size="sm" class="mb-3">Hubungan port perangkat dengan port terminasi di titik ini, atau langsung dengan core bila tanpa patch panel.</flux:text>

        @if ($this->endpointLangsung->isNotEmpty())
            <flux:table class="mb-4" data-test="tabel-endpoint-langsung">
                <flux:table.columns>
                    <flux:table.column>Port perangkat</flux:table.column>
                    <flux:table.column>Langsung ke core</flux:table.column>
                    <flux:table.column align="end">Tindakan</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->endpointLangsung as $ep)
                        <flux:table.row :key="$ep->id">
                            <flux:table.cell variant="strong">{{ $ep->portPerangkat->label() }}</flux:table.cell>
                            <flux:table.cell>{{ $ep->core?->label() }}</flux:table.cell>
                            <flux:table.cell align="end">
                                @can('update', $titik)
                                    <flux:button size="sm" variant="ghost" wire:click="lepasEndpoint({{ $ep->id }})" wire:confirm="Lepas koneksi ini?">Lepas</flux:button>
                                @endcan
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        @can('update', $titik)
            <form wire:submit="hubungkanEndpoint" class="grid gap-3 rounded-lg border border-zinc-200 p-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-zinc-700">
                <flux:select wire:model="ep_perangkat" label="Perangkat">
                    <flux:select.option value="">Pilih perangkat</flux:select.option>
                    @foreach ($this->pilihanPerangkat as $p)
                        <flux:select.option :value="$p->id">{{ $p->kode_aset }} — {{ $p->nama }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="ep_port_perangkat" label="Port perangkat" placeholder="GigabitEthernet0/1" autocomplete="off" />
                <flux:select wire:model.live="ep_lewat" label="Dihubungkan ke">
                    @if ($titik->jenis->berPort())
                        <flux:select.option value="port">Port terminasi</flux:select.option>
                    @endif
                    <flux:select.option value="core">Langsung ke core</flux:select.option>
                </flux:select>

                @if ($ep_lewat === 'port')
                    <flux:select wire:model="ep_port" label="Port terminasi">
                        <flux:select.option value="">Pilih port</flux:select.option>
                        @foreach ($this->daftarPort->filter(fn ($p) => $p->koneksiEndpoint === null && $p->status !== \App\Enums\StatusPemakaian::Rusak) as $p)
                            <flux:select.option :value="$p->id">{{ $p->nomor }}{{ $p->core ? ' · '.$p->core->label() : ' · belum berisi core' }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <flux:select wire:model.live="ep_segmen" label="Kabel">
                        <flux:select.option value="">Pilih kabel</flux:select.option>
                        @foreach ($this->segmen as $s)
                            <flux:select.option :value="$s->id">{{ $s->kode }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="ep_core" label="Core" wire:key="ep-core-{{ $ep_segmen }}">
                        <flux:select.option value="">Pilih core</flux:select.option>
                        @foreach ($this->coreBebas[(int) $ep_segmen] ?? [] as $c)
                            <flux:select.option :value="$c->id">{{ $c->nomor }} · {{ $c->warna }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <div class="sm:col-span-2 lg:col-span-3">
                    <flux:input wire:model="ep_catatan" placeholder="Catatan koneksi (opsional)" aria-label="Catatan koneksi" />
                </div>
                <div class="flex items-end">
                    <flux:button type="submit" variant="primary" data-test="hubungkan-endpoint">Hubungkan</flux:button>
                </div>
            </form>
        @endcan
    </div>

    <x-kartu-qr :aset="$titik" />

    <div>
        <flux:heading class="mb-3">Riwayat hubungan di titik ini</flux:heading>
        <x-riwayat-hubungan :riwayat="$this->riwayat" />
    </div>
</section>
