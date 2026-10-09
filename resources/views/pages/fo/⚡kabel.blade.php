<?php

use App\Enums\KlasifikasiKabel;
use App\Enums\StatusData;
use App\Models\SegmenKabel;
use App\Models\TitikJaringan;
use App\Validasi\AturanFo;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Segmen kabel')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $cari = '';

    #[Url]
    public string $klasifikasi_saring = '';

    #[Url]
    public string $status = '';

    #[Locked]
    public ?int $idTarget = null;

    public string $kode = '';
    public string $nama = '';
    public string $klasifikasi = '';
    public string $jumlah_core = '';
    public string $kode_titik_awal = '';
    public string $kode_titik_akhir = '';
    public string $panjang_ukur_m = '';
    public string $catatan = '';

    private const array KOLOM = ['kode', 'nama', 'klasifikasi', 'jumlah_core', 'kode_titik_awal', 'kode_titik_akhir', 'panjang_ukur_m', 'catatan'];

    /**
     * Dijalankan pada setiap request ke komponen ini, termasuk pemanggilan
     * aksi secara langsung.
     */
    public function boot(): void
    {
        $this->authorize('viewAny', SegmenKabel::class);
    }

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'klasifikasi_saring', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, SegmenKabel>
     */
    #[Computed]
    public function daftar(): LengthAwarePaginator
    {
        return SegmenKabel::query()
            ->with(['titikAwal:id,kode', 'titikAkhir:id,kode'])
            ->withCount('tiang')
            ->when($this->cari !== '', function ($query) {
                $kata = '%'.addcslashes($this->cari, '%_\\').'%';

                $query->where(fn ($q) => $q->whereLike('kode', $kata)->orWhereLike('nama', $kata));
            })
            ->when(KlasifikasiKabel::tryFrom($this->klasifikasi_saring), fn ($query, KlasifikasiKabel $k) => $query->where('klasifikasi', $k))
            ->when(StatusData::tryFrom($this->status), fn ($query, StatusData $s) => $query->where('status_data', $s))
            ->orderBy('kode')
            ->paginate(15);
    }

    public function tambah(): void
    {
        $this->authorize('create', SegmenKabel::class);

        $this->kosongkanFormulir();

        Flux::modal('formulir-segmen')->show();
    }

    public function ubah(int $id): void
    {
        $segmen = SegmenKabel::with(['titikAwal', 'titikAkhir'])->findOrFail($id);

        $this->authorize('update', $segmen);

        $this->kosongkanFormulir();
        $this->idTarget = $segmen->id;
        $this->kode = $segmen->kode;
        $this->nama = (string) $segmen->nama;
        $this->klasifikasi = (string) $segmen->klasifikasi?->value;
        $this->jumlah_core = (string) $segmen->jumlah_core;
        $this->kode_titik_awal = (string) $segmen->titikAwal?->kode;
        $this->kode_titik_akhir = (string) $segmen->titikAkhir?->kode;
        $this->panjang_ukur_m = (string) $segmen->panjang_ukur_m;
        $this->catatan = (string) $segmen->catatan;

        Flux::modal('formulir-segmen')->show();
    }

    public function simpan(): void
    {
        $segmen = $this->idTarget === null ? new SegmenKabel : SegmenKabel::findOrFail($this->idTarget);

        $segmen->exists
            ? $this->authorize('update', $segmen)
            : $this->authorize('create', SegmenKabel::class);

        $data = AturanFo::siapkanSegmen($this->only(self::KOLOM));
        $this->kode = (string) $data['kode'];

        $titik = TitikJaringan::query()
            ->whereIn('kode', array_filter([$data['kode_titik_awal'], $data['kode_titik_akhir']]))
            ->pluck('id', 'kode');
        $awalId = $titik[$data['kode_titik_awal']] ?? null;
        $akhirId = $titik[$data['kode_titik_akhir']] ?? null;

        $validator = validator($data, AturanFo::segmen($segmen->exists ? $segmen->id : null), AturanFo::pesan());

        if ($segmen->exists) {
            $validator->after(function ($v) use ($segmen, $data, $awalId, $akhirId): void {
                if ($v->errors()->hasAny(['jumlah_core', 'kode_titik_awal', 'kode_titik_akhir'])) {
                    return;
                }

                $jumlah = $data['jumlah_core'] === null ? null : (int) $data['jumlah_core'];

                foreach (AturanFo::galatPerubahanSegmen($segmen, $jumlah, $awalId, $akhirId) as $kolom => $pesan) {
                    $v->errors()->add($kolom, $pesan);
                }
            });
        }

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return;
        }

        $segmen->fill(Arr::except($validator->validated(), ['kode_titik_awal', 'kode_titik_akhir']));
        $segmen->titik_awal_id = $awalId;
        $segmen->titik_akhir_id = $akhirId;
        $segmen->save();

        $perluDilengkapi = $segmen->status_data === StatusData::PerluDilengkapi;

        $this->kosongkanFormulir();
        Flux::modal('formulir-segmen')->close();
        Flux::toast(
            variant: $perluDilengkapi ? 'warning' : 'success',
            text: $perluDilengkapi ? 'Segmen kabel disimpan dengan status Perlu dilengkapi.' : 'Segmen kabel disimpan.',
        );
    }

    public function konfirmasiHapus(int $id): void
    {
        $segmen = SegmenKabel::findOrFail($id);

        $this->authorize('delete', $segmen);

        $this->kosongkanFormulir();
        $this->idTarget = $segmen->id;
        $this->kode = $segmen->kode;

        Flux::modal('hapus-segmen')->show();
    }

    public function hapus(): void
    {
        $segmen = SegmenKabel::find($this->idTarget) ?? new SegmenKabel;

        $this->authorize('delete', $segmen);
        abort_unless($segmen->exists, 404);

        if (AturanFo::galatPerubahanSegmen($segmen, 0, null, null) !== []) {
            $this->addError('hapus', 'Core segmen ini masih tersambung, diterminasi, atau terhubung ke perangkat. Lepaskan hubungannya dahulu.');

            return;
        }

        $segmen->delete();

        $this->kosongkanFormulir();
        Flux::modal('hapus-segmen')->close();
        Flux::toast(variant: 'success', text: 'Segmen kabel dihapus.');
    }

    private function kosongkanFormulir(): void
    {
        $this->reset('idTarget', ...self::KOLOM);
        $this->resetValidation();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Segmen kabel</flux:heading>
            <flux:subheading size="lg">Bentang kabel antara dua titik jaringan, backbone maupun distribusi. Kode cukup untuk mulai mendata.</flux:subheading>
        </div>

        @can('create', \App\Models\SegmenKabel::class)
            <flux:button variant="primary" icon="plus" wire:click="tambah" data-test="tambah-segmen">Tambah segmen</flux:button>
        @endcan
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="cari" icon="magnifying-glass" placeholder="Cari kode atau nama" aria-label="Cari segmen kabel" />
        <flux:select wire:model.live="klasifikasi_saring" aria-label="Saring klasifikasi">
            <flux:select.option value="">Semua klasifikasi</flux:select.option>
            @foreach (\App\Enums\KlasifikasiKabel::cases() as $pilihan)
                <flux:select.option :value="$pilihan->value">{{ $pilihan->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" aria-label="Saring kelengkapan">
            <flux:select.option value="">Semua kelengkapan</flux:select.option>
            @foreach (\App\Enums\StatusData::cases() as $pilihan)
                <flux:select.option :value="$pilihan->value">{{ $pilihan->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->daftar">
        <flux:table.columns>
            <flux:table.column>Kode</flux:table.column>
            <flux:table.column>Klasifikasi</flux:table.column>
            <flux:table.column>Titik awal → akhir</flux:table.column>
            <flux:table.column>Core</flux:table.column>
            <flux:table.column>Panjang ukur / peta</flux:table.column>
            <flux:table.column>Tiang</flux:table.column>
            <flux:table.column>Kelengkapan</flux:table.column>
            <flux:table.column align="end">Tindakan</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->daftar as $segmen)
                <flux:table.row :key="$segmen->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="route('fo.kabel.rinci', $segmen)" wire:navigate>{{ $segmen->kode }}</flux:link>
                        <x-lencana-simulasi :tampil="$segmen->simulasi" class="ms-1" />
                        @if ($segmen->nama)
                            <flux:text size="sm">{{ $segmen->nama }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $segmen->klasifikasi?->label() ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $segmen->titikAwal?->kode ?? '?' }} → {{ $segmen->titikAkhir?->kode ?? '?' }}</flux:table.cell>
                    <flux:table.cell>{{ $segmen->jumlah_core ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $segmen->panjang_ukur_m !== null ? number_format((float) $segmen->panjang_ukur_m, 0, ',', '.').' m' : '—' }}
                        /
                        {{ $segmen->panjang_peta_m !== null ? number_format((float) $segmen->panjang_peta_m, 0, ',', '.').' m' : '—' }}
                    </flux:table.cell>
                    <flux:table.cell>{{ $segmen->tiang_count }}</flux:table.cell>
                    <flux:table.cell class="max-w-xs"><x-status-data :objek="$segmen" /></flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            <flux:button size="sm" variant="ghost" :href="route('fo.kabel.rinci', $segmen)" wire:navigate>Rute &amp; core</flux:button>
                            @can('update', $segmen)
                                <flux:button size="sm" variant="ghost" wire:click="ubah({{ $segmen->id }})">{{ $segmen->status_data === \App\Enums\StatusData::Lengkap ? 'Ubah' : 'Lengkapi' }}</flux:button>
                            @endcan
                            @can('delete', $segmen)
                                <flux:button size="sm" variant="ghost" wire:click="konfirmasiHapus({{ $segmen->id }})">Hapus</flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8">Tidak ada segmen kabel yang cocok.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="formulir-segmen" variant="flyout" class="w-full max-w-xl">
        <form wire:submit="simpan" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $idTarget === null ? 'Tambah segmen kabel' : 'Ubah segmen kabel' }}</flux:heading>
                <flux:subheading>Hanya kode yang wajib. Rute tiang dan jalur peta diisi di halaman rincian segmen.</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="kode" label="Kode" required maxlength="50" autocomplete="off" description="Huruf besar, unik." />
                <flux:select wire:model="klasifikasi" label="Klasifikasi">
                    <flux:select.option value="">Belum ditentukan</flux:select.option>
                    @foreach (\App\Enums\KlasifikasiKabel::cases() as $pilihan)
                        <flux:select.option :value="$pilihan->value">{{ $pilihan->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input wire:model="nama" label="Nama" placeholder="Diskominfo – Setda" autocomplete="off" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="kode_titik_awal" label="Kode titik awal" autocomplete="off" placeholder="OTB-DISKOMINFO" />
                <flux:input wire:model="kode_titik_akhir" label="Kode titik akhir" autocomplete="off" placeholder="CL-001" />
                <flux:input wire:model="jumlah_core" label="Jumlah core" type="number" min="1" max="288" description="Core dibuat otomatis beserta warnanya." />
                <flux:input wire:model="panjang_ukur_m" label="Panjang hasil pengukuran (m)" inputmode="decimal" description="Dari OTDR atau meteran, bukan dari peta." />
            </div>

            <flux:textarea wire:model="catatan" label="Catatan" rows="3" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="simpan-segmen">Simpan</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="hapus-segmen" class="w-full max-w-lg">
        <form wire:submit="hapus" class="space-y-6">
            <div>
                <flux:heading size="lg">Hapus segmen kabel?</flux:heading>
                <flux:subheading>{{ $kode }} beserta core dan rute tiangnya akan dihapus.</flux:subheading>
            </div>

            <flux:error name="hapus" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit">Hapus segmen</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
