<?php

use App\Enums\JenisPerangkat;
use App\Enums\PenempatanPerangkat;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Validasi\AturanPerangkat;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Perangkat')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $cari = '';

    #[Url]
    public string $opd = '';

    #[Url]
    public string $jenis_saring = '';

    #[Url]
    public string $penempatan_saring = '';

    #[Url]
    public string $monitoring = '';

    #[Locked]
    public ?int $idTarget = null;

    #[Locked]
    public ?int $idDilihat = null;

    #[Locked]
    public bool $punyaFoto = false;

    public string $opd_id = '';
    public string $penempatan = 'opd';
    public string $lokasi = '';
    public string $kode_aset = '';
    public string $nama = '';
    public string $jenis = 'switch';
    public string $merek = '';
    public string $model = '';
    public string $nomor_seri = '';
    public string $ip_manajemen = '';
    public string $port_uplink = '';
    public bool $monitoring_aktif = false;
    public bool $switch_utama = false;
    public string $catatan = '';
    public bool $hapusFoto = false;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $foto = null;

    private const array KOLOM_FORMULIR = [
        'opd_id', 'penempatan', 'lokasi', 'kode_aset', 'nama', 'jenis', 'merek', 'model', 'nomor_seri',
        'ip_manajemen', 'port_uplink', 'monitoring_aktif', 'switch_utama', 'catatan',
    ];

    /**
     * Dijalankan pada setiap request ke komponen ini, termasuk pemanggilan
     * aksi dan unggahan secara langsung.
     */
    public function boot(): void
    {
        $this->authorize('viewAny', Perangkat::class);
    }

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'opd', 'jenis_saring', 'penempatan_saring', 'monitoring'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Perangkat>
     */
    #[Computed]
    public function daftar(): LengthAwarePaginator
    {
        return Perangkat::query()
            ->with('opd:id,kode,nama')
            ->when($this->cari !== '', function ($query) {
                $kata = '%'.addcslashes($this->cari, '%_\\').'%';

                $query->where(fn ($q) => $q
                    ->whereLike('nama', $kata)
                    ->orWhereLike('kode_aset', $kata)
                    ->orWhereLike('nomor_seri', $kata)
                    ->orWhereLike('merek', $kata)
                    ->orWhereLike('model', $kata)
                    ->orWhereRaw('host(ip_manajemen) ilike ?', [$kata]));
            })
            ->when(ctype_digit($this->opd), fn ($query) => $query->where('opd_id', (int) $this->opd))
            ->when(JenisPerangkat::tryFrom($this->jenis_saring), fn ($query, JenisPerangkat $jenis) => $query->where('jenis', $jenis))
            ->when(PenempatanPerangkat::tryFrom($this->penempatan_saring), fn ($query, PenempatanPerangkat $p) => $query->where('penempatan', $p))
            ->when($this->monitoring === 'aktif', fn ($query) => $query->where('monitoring_aktif', true))
            ->when($this->monitoring === 'nonaktif', fn ($query) => $query->where('monitoring_aktif', false))
            ->when($this->monitoring === 'utama', fn ($query) => $query->where('switch_utama', true))
            ->orderBy('kode_aset')
            ->paginate(15);
    }

    /**
     * @return Collection<int, Opd>
     */
    #[Computed]
    public function pilihanOpd(): Collection
    {
        return Opd::query()->orderBy('nama')->get(['id', 'kode', 'nama', 'aktif']);
    }

    #[Computed]
    public function dilihat(): ?Perangkat
    {
        return $this->idDilihat === null ? null : Perangkat::query()->with('opd')->find($this->idDilihat);
    }

    public function lihat(int $id): void
    {
        $perangkat = Perangkat::findOrFail($id);

        $this->authorize('view', $perangkat);

        $this->idDilihat = $perangkat->id;
        unset($this->dilihat);

        Flux::modal('rincian-perangkat')->show();
    }

    public function tambah(): void
    {
        $this->authorize('create', Perangkat::class);

        $this->kosongkanFormulir();

        if (ctype_digit($this->opd)) {
            $this->opd_id = $this->opd;
        }

        Flux::modal('formulir-perangkat')->show();
    }

    public function ubah(int $id): void
    {
        $perangkat = Perangkat::findOrFail($id);

        $this->authorize('update', $perangkat);

        $this->kosongkanFormulir();
        $this->idTarget = $perangkat->id;
        $this->punyaFoto = $perangkat->foto_path !== null;
        $this->opd_id = (string) $perangkat->opd_id;
        $this->penempatan = $perangkat->penempatan->value;
        $this->lokasi = (string) $perangkat->lokasi;
        $this->kode_aset = $perangkat->kode_aset;
        $this->nama = $perangkat->nama;
        $this->jenis = $perangkat->jenis->value;
        $this->merek = (string) $perangkat->merek;
        $this->model = (string) $perangkat->model;
        $this->nomor_seri = (string) $perangkat->nomor_seri;
        $this->ip_manajemen = (string) $perangkat->ip_manajemen;
        $this->port_uplink = (string) $perangkat->port_uplink;
        $this->monitoring_aktif = $perangkat->monitoring_aktif;
        $this->switch_utama = $perangkat->switch_utama;
        $this->catatan = (string) $perangkat->catatan;

        Flux::modal('formulir-perangkat')->show();
    }

    public function simpan(): void
    {
        $perangkat = $this->idTarget === null ? new Perangkat : Perangkat::findOrFail($this->idTarget);

        $perangkat->exists
            ? $this->authorize('update', $perangkat)
            : $this->authorize('create', Perangkat::class);

        $data = AturanPerangkat::siapkan($this->only(self::KOLOM_FORMULIR));

        $this->kode_aset = (string) $data['kode_aset'];

        $validator = validator(
            [...$data, 'foto' => $this->foto],
            [
                ...AturanPerangkat::aturan($data, $perangkat->exists ? $perangkat->id : null),
                'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ],
            AturanPerangkat::pesan(),
        );

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return;
        }

        $fotoLama = $perangkat->foto_path;

        DB::transaction(function () use ($perangkat, $validator): void {
            $perangkat->fill(Arr::except($validator->validated(), ['foto']));

            if ($perangkat->switch_utama) {
                // Satu switch utama per OPD: penanda dipindahkan ke perangkat ini.
                $perangkat->lepasSwitchUtamaLain();
            }

            if ($this->foto !== null) {
                $perangkat->foto_path = $this->foto->store(Perangkat::FOLDER_FOTO, Perangkat::DISK_FOTO);
            } elseif ($this->hapusFoto) {
                $perangkat->foto_path = null;
            }

            $perangkat->save();
        });

        if ($fotoLama !== null && $fotoLama !== $perangkat->foto_path) {
            Storage::disk(Perangkat::DISK_FOTO)->delete($fotoLama);
        }

        $this->kosongkanFormulir();
        Flux::modal('formulir-perangkat')->close();
        Flux::toast(variant: 'success', text: 'Perangkat disimpan.');
    }

    public function konfirmasiHapus(int $id): void
    {
        $perangkat = Perangkat::findOrFail($id);

        $this->authorize('delete', $perangkat);

        $this->kosongkanFormulir();
        $this->idTarget = $perangkat->id;
        $this->kode_aset = $perangkat->kode_aset;
        $this->nama = $perangkat->nama;

        Flux::modal('hapus-perangkat')->show();
    }

    public function hapus(): void
    {
        $perangkat = Perangkat::find($this->idTarget) ?? new Perangkat;

        $this->authorize('delete', $perangkat);
        abort_unless($perangkat->exists, 404);

        if ($perangkat->port()->whereHas('koneksiEndpoint')->exists()) {
            $this->addError('hapus', 'Port perangkat ini masih terhubung ke jaringan FO. Lepas koneksi endpoint-nya dahulu di halaman titik jaringan.');

            return;
        }

        $perangkat->delete();

        $this->kosongkanFormulir();
        Flux::modal('hapus-perangkat')->close();
        Flux::toast(variant: 'success', text: 'Perangkat dihapus.');
    }

    private function kosongkanFormulir(): void
    {
        $this->reset('idTarget', 'punyaFoto', 'hapusFoto', 'foto', ...self::KOLOM_FORMULIR);
        $this->resetValidation();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Perangkat</flux:heading>
            <flux:subheading size="lg">Perangkat jaringan aktif di Diskominfo dan OPD.</flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="printer" :href="route('aset.label', ['jenis' => 'perangkat', 'kode' => $this->daftar->pluck('kode_aset')->implode(',')])" target="_blank" data-test="cetak-label">Cetak label halaman ini</flux:button>
        @can('create', \App\Models\Perangkat::class)
            <div class="flex gap-2">
                <flux:button icon="arrow-up-tray" :href="route('impor', 'perangkat')" wire:navigate>Impor CSV</flux:button>
                <flux:button variant="primary" icon="plus" wire:click="tambah" data-test="tambah-perangkat">Tambah perangkat</flux:button>
            </div>
        @endcan
        </div>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <flux:input wire:model.live.debounce.300ms="cari" icon="magnifying-glass" placeholder="Cari nama, kode aset, nomor seri, IP" aria-label="Cari perangkat" />
        <flux:select wire:model.live="opd" aria-label="Saring OPD">
            <flux:select.option value="">Semua OPD</flux:select.option>
            @foreach ($this->pilihanOpd as $pilihan)
                <flux:select.option :value="$pilihan->id">{{ $pilihan->nama }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="jenis_saring" aria-label="Saring jenis">
            <flux:select.option value="">Semua jenis</flux:select.option>
            @foreach (\App\Enums\JenisPerangkat::cases() as $pilihan)
                <flux:select.option :value="$pilihan->value">{{ $pilihan->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="penempatan_saring" aria-label="Saring penempatan">
            <flux:select.option value="">Semua penempatan</flux:select.option>
            @foreach (\App\Enums\PenempatanPerangkat::cases() as $pilihan)
                <flux:select.option :value="$pilihan->value">{{ $pilihan->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="monitoring" aria-label="Saring monitoring">
            <flux:select.option value="">Semua perangkat</flux:select.option>
            <flux:select.option value="aktif">Monitoring diaktifkan</flux:select.option>
            <flux:select.option value="nonaktif">Monitoring tidak diaktifkan</flux:select.option>
            <flux:select.option value="utama">Switch utama</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$this->daftar">
        <flux:table.columns>
            <flux:table.column>Kode aset</flux:table.column>
            <flux:table.column>Nama</flux:table.column>
            <flux:table.column>OPD pemilik</flux:table.column>
            <flux:table.column>Jenis</flux:table.column>
            <flux:table.column>IP manajemen</flux:table.column>
            <flux:table.column>Monitoring</flux:table.column>
            <flux:table.column align="end">Tindakan</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->daftar as $perangkat)
                <flux:table.row :key="$perangkat->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="\App\Support\TautanAset::jalur($perangkat)" wire:navigate>{{ $perangkat->kode_aset }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell class="max-w-sm whitespace-normal">
                        {{ $perangkat->nama }}
                        <x-lencana-simulasi :tampil="$perangkat->simulasi" class="ms-1" />
                        @if ($perangkat->switch_utama)
                            <flux:badge size="sm" color="amber" class="ms-1">Switch utama</flux:badge>
                        @endif
                        <flux:text size="sm">{{ collect([$perangkat->merek, $perangkat->model])->filter()->implode(' ') ?: '—' }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $perangkat->opd->kode }}
                        <flux:text size="sm">{{ $perangkat->penempatan->label() }}{{ $perangkat->lokasi ? ' · '.$perangkat->lokasi : '' }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>{{ $perangkat->jenis->label() }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $perangkat->ip_manajemen ?? '—' }}
                        @if ($perangkat->port_uplink)
                            <flux:text size="sm">Uplink: {{ $perangkat->port_uplink }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$perangkat->monitoring_aktif ? 'green' : 'zinc'">
                            {{ $perangkat->monitoring_aktif ? 'Diaktifkan' : 'Tidak diaktifkan' }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            <flux:button size="sm" variant="ghost" wire:click="lihat({{ $perangkat->id }})">Lihat</flux:button>
                            @can('update', $perangkat)
                                <flux:button size="sm" variant="ghost" wire:click="ubah({{ $perangkat->id }})">Ubah</flux:button>
                            @endcan
                            @can('delete', $perangkat)
                                <flux:button size="sm" variant="ghost" wire:click="konfirmasiHapus({{ $perangkat->id }})">Hapus</flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7">Tidak ada perangkat yang cocok.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="rincian-perangkat" variant="flyout" class="w-full max-w-xl">
        @if ($this->dilihat)
            @php($p = $this->dilihat)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        {{ $p->nama }}
                        <x-lencana-simulasi :tampil="$p->simulasi" class="ms-1" />
                    </flux:heading>
                    <flux:subheading>{{ $p->kode_aset }} · {{ $p->jenis->label() }}</flux:subheading>
                </div>

                @if ($p->foto_path)
                    <img src="{{ route('perangkat.foto', $p) }}?v={{ $p->updated_at?->timestamp }}" alt="Foto {{ $p->nama }}" class="max-h-80 w-full rounded-lg border border-zinc-200 object-contain dark:border-zinc-700" />
                @endif

                <dl class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
                    @foreach ([
                        'OPD pemilik' => $p->opd->kode.' — '.$p->opd->nama,
                        'Penempatan' => $p->penempatan->label().($p->lokasi ? ' · '.$p->lokasi : ''),
                        'Merek / model' => collect([$p->merek, $p->model])->filter()->implode(' ') ?: '—',
                        'Nomor seri' => $p->nomor_seri ?? '—',
                        'IP manajemen' => $p->ip_manajemen ?? '—',
                        'Port uplink' => $p->port_uplink ?? '—',
                        'Monitoring' => $p->monitoring_aktif ? 'Diaktifkan' : 'Tidak diaktifkan',
                        'Switch utama' => $p->switch_utama ? 'Ya' : 'Tidak',
                        'Catatan' => $p->catatan ?? '—',
                    ] as $label => $nilai)
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                        <dd class="col-span-2 whitespace-pre-line break-words">{{ $nilai }}</dd>
                    @endforeach
                </dl>

                <flux:text size="sm">
                    "Monitoring diaktifkan" hanya menandai perangkat untuk dipantau. Status koneksi belum tersedia di tahap ini.
                </flux:text>
            </div>
        @endif
    </flux:modal>

    <flux:modal name="formulir-perangkat" variant="flyout" class="w-full max-w-2xl">
        <form wire:submit="simpan" class="space-y-6">
            <flux:heading size="lg">{{ $idTarget === null ? 'Tambah perangkat' : 'Ubah perangkat' }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="kode_aset" label="Kode aset" required maxlength="50" autocomplete="off" description="Huruf besar, unik." />
                <div class="sm:col-span-2">
                    <flux:input wire:model="nama" label="Nama" required autocomplete="off" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="opd_id" label="OPD pemilik" required placeholder="Pilih OPD">
                    @foreach ($this->pilihanOpd as $pilihan)
                        <flux:select.option :value="$pilihan->id">{{ $pilihan->nama }}{{ $pilihan->aktif ? '' : ' (nonaktif)' }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="penempatan" label="Penempatan" required>
                    @foreach (\App\Enums\PenempatanPerangkat::cases() as $pilihan)
                        <flux:select.option :value="$pilihan->value">{{ $pilihan->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input wire:model="lokasi" label="Lokasi" placeholder="Ruang server lantai 1, rak A" autocomplete="off" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="jenis" label="Jenis" required>
                    @foreach (\App\Enums\JenisPerangkat::cases() as $pilihan)
                        <flux:select.option :value="$pilihan->value">{{ $pilihan->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="nomor_seri" label="Nomor seri" autocomplete="off" />
                <flux:input wire:model="merek" label="Merek" autocomplete="off" />
                <flux:input wire:model="model" label="Model" autocomplete="off" />
                <flux:input wire:model="ip_manajemen" label="IP manajemen" placeholder="10.0.0.1" autocomplete="off" />
                <flux:input wire:model="port_uplink" label="Port uplink yang dipantau" placeholder="GigabitEthernet0/1" autocomplete="off" />
            </div>

            <flux:switch wire:model="monitoring_aktif" label="Monitoring diaktifkan" description="Membutuhkan IP manajemen. Kredensial SNMP tidak diisi di sini." />
            <flux:switch wire:model="switch_utama" label="Switch utama OPD" description="Satu per OPD. Penanda pada switch lain di OPD yang sama akan dilepas." />

            <flux:field>
                <flux:label>Foto</flux:label>
                @if ($punyaFoto && ! $hapusFoto && $foto === null && $idTarget !== null)
                    <img src="{{ route('perangkat.foto', $idTarget) }}" alt="Foto saat ini" class="mb-2 max-h-40 rounded-lg border border-zinc-200 dark:border-zinc-700" />
                    <flux:checkbox wire:model.live="hapusFoto" label="Hapus foto ini" />
                @endif
                <flux:input type="file" wire:model="foto" accept="image/jpeg,image/png,image/webp" />
                <flux:description>JPG, PNG, atau WebP, paling besar 5 MB.</flux:description>
                <flux:error name="foto" />
            </flux:field>

            <flux:textarea wire:model="catatan" label="Catatan" rows="3" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="simpan-perangkat">Simpan</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="hapus-perangkat" class="w-full max-w-lg">
        <form wire:submit="hapus" class="space-y-6">
            <div>
                <flux:heading size="lg">Hapus perangkat?</flux:heading>
                <flux:subheading>{{ $kode_aset }} — {{ $nama }} beserta fotonya akan dihapus. Riwayatnya di log aktivitas tetap tersimpan.</flux:subheading>
            </div>

            <flux:error name="hapus" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit">Hapus perangkat</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
