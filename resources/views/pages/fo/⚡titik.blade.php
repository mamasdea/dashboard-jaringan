<?php

use App\Enums\JenisTitikJaringan;
use App\Enums\StatusData;
use App\Models\Opd;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use App\Validasi\AturanFo;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Titik jaringan')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $cari = '';

    #[Url]
    public string $jenis_saring = '';

    #[Url]
    public string $status = '';

    #[Locked]
    public ?int $idTarget = null;

    public string $kode = '';
    public string $nama = '';
    public string $jenis = 'closure';
    public string $lintang = '';
    public string $bujur = '';
    public string $kode_tiang = '';
    public string $opd_id = '';
    public string $lokasi = '';
    public string $kapasitas_port = '';
    public string $catatan = '';

    private const array KOLOM = ['kode', 'nama', 'jenis', 'lintang', 'bujur', 'kode_tiang', 'opd_id', 'lokasi', 'kapasitas_port', 'catatan'];

    /**
     * Dijalankan pada setiap request ke komponen ini, termasuk pemanggilan
     * aksi secara langsung.
     */
    public function boot(): void
    {
        $this->authorize('viewAny', TitikJaringan::class);
    }

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'jenis_saring', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, TitikJaringan>
     */
    #[Computed]
    public function daftar(): LengthAwarePaginator
    {
        return TitikJaringan::query()
            ->with(['tiang:id,kode', 'opd:id,kode'])
            ->withCount(['port', 'sambungan'])
            ->when($this->cari !== '', function ($query) {
                $kata = '%'.addcslashes($this->cari, '%_\\').'%';

                $query->where(fn ($q) => $q->whereLike('kode', $kata)->orWhereLike('nama', $kata)->orWhereLike('lokasi', $kata));
            })
            ->when(JenisTitikJaringan::tryFrom($this->jenis_saring), fn ($query, JenisTitikJaringan $j) => $query->where('jenis', $j))
            ->when(StatusData::tryFrom($this->status), fn ($query, StatusData $s) => $query->where('status_data', $s))
            ->orderBy('kode')
            ->paginate(15);
    }

    /**
     * @return Collection<int, Opd>
     */
    #[Computed]
    public function pilihanOpd(): Collection
    {
        return Opd::query()->orderBy('nama')->get(['id', 'kode', 'nama']);
    }

    public function tambah(): void
    {
        $this->authorize('create', TitikJaringan::class);

        $this->kosongkanFormulir();

        Flux::modal('formulir-titik')->show();
    }

    public function ubah(int $id): void
    {
        $titik = TitikJaringan::with('tiang')->findOrFail($id);

        $this->authorize('update', $titik);

        $this->kosongkanFormulir();
        $this->idTarget = $titik->id;
        $this->kode = $titik->kode;
        $this->nama = (string) $titik->nama;
        $this->jenis = $titik->jenis->value;
        $this->lintang = (string) $titik->lintang;
        $this->bujur = (string) $titik->bujur;
        $this->kode_tiang = (string) $titik->tiang?->kode;
        $this->opd_id = (string) $titik->opd_id;
        $this->lokasi = (string) $titik->lokasi;
        $this->kapasitas_port = (string) $titik->kapasitas_port;
        $this->catatan = (string) $titik->catatan;

        Flux::modal('formulir-titik')->show();
    }

    public function simpan(): void
    {
        $titik = $this->idTarget === null ? new TitikJaringan : TitikJaringan::findOrFail($this->idTarget);

        $titik->exists
            ? $this->authorize('update', $titik)
            : $this->authorize('create', TitikJaringan::class);

        $data = AturanFo::siapkanTitik($this->only(self::KOLOM));
        $this->kode = (string) $data['kode'];

        $validator = validator($data, AturanFo::titik($titik->exists ? $titik->id : null), AturanFo::pesan());

        if ($titik->exists) {
            $validator->after(function ($v) use ($titik, $data): void {
                if (! $v->errors()->has('kapasitas_port')) {
                    $kapasitas = $data['kapasitas_port'] === null ? null : (int) $data['kapasitas_port'];

                    foreach (AturanFo::galatPerubahanTitik($titik, $kapasitas) as $kolom => $pesan) {
                        $v->errors()->add($kolom, $pesan);
                    }
                }
            });
        }

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return;
        }

        $bersih = $validator->validated();

        $titik->fill(Arr::except($bersih, ['kode_tiang']));
        $titik->tiang_id = $bersih['kode_tiang'] === null ? null : Tiang::query()->where('kode', $bersih['kode_tiang'])->value('id');
        $titik->save();

        $perluDilengkapi = $titik->status_data === StatusData::PerluDilengkapi;

        $this->kosongkanFormulir();
        Flux::modal('formulir-titik')->close();
        Flux::toast(
            variant: $perluDilengkapi ? 'warning' : 'success',
            text: $perluDilengkapi ? 'Titik jaringan disimpan dengan status Perlu dilengkapi.' : 'Titik jaringan disimpan.',
        );
    }

    public function konfirmasiHapus(int $id): void
    {
        $titik = TitikJaringan::findOrFail($id);

        $this->authorize('delete', $titik);

        $this->kosongkanFormulir();
        $this->idTarget = $titik->id;
        $this->kode = $titik->kode;

        Flux::modal('hapus-titik')->show();
    }

    public function hapus(): void
    {
        $titik = TitikJaringan::find($this->idTarget) ?? new TitikJaringan;

        $this->authorize('delete', $titik);
        abort_unless($titik->exists, 404);

        $terpakai = $titik->segmen()->exists()
            || $titik->sambungan()->exists()
            || $titik->port()->where(fn ($query) => $query->whereNotNull('core_id')->orWhereHas('koneksiEndpoint'))->exists();

        if ($terpakai) {
            $this->addError('hapus', 'Titik ini masih menjadi ujung kabel atau memiliki sambungan, terminasi, atau koneksi. Lepaskan dahulu.');

            return;
        }

        $titik->delete();

        $this->kosongkanFormulir();
        Flux::modal('hapus-titik')->close();
        Flux::toast(variant: 'success', text: 'Titik jaringan dihapus.');
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
            <flux:heading size="xl" level="1">Titik jaringan</flux:heading>
            <flux:subheading size="lg">ODC, ODP, closure, dan terminasi/OTB. Kode dan jenis cukup untuk mulai mendata.</flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="printer" :href="route('aset.label', ['jenis' => 'titik', 'kode' => $this->daftar->pluck('kode')->implode(',')])" target="_blank" data-test="cetak-label">Cetak label halaman ini</flux:button>
        @can('create', \App\Models\TitikJaringan::class)
            <flux:button variant="primary" icon="plus" wire:click="tambah" data-test="tambah-titik">Tambah titik</flux:button>
        @endcan
        </div>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="cari" icon="magnifying-glass" placeholder="Cari kode, nama, atau lokasi" aria-label="Cari titik jaringan" />
        <flux:select wire:model.live="jenis_saring" aria-label="Saring jenis">
            <flux:select.option value="">Semua jenis</flux:select.option>
            @foreach (\App\Enums\JenisTitikJaringan::cases() as $pilihan)
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
            <flux:table.column>Jenis</flux:table.column>
            <flux:table.column>Lokasi</flux:table.column>
            <flux:table.column>Port</flux:table.column>
            <flux:table.column>Sambungan</flux:table.column>
            <flux:table.column>Kelengkapan</flux:table.column>
            <flux:table.column align="end">Tindakan</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->daftar as $titik)
                <flux:table.row :key="$titik->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="route('fo.titik.rinci', $titik)" wire:navigate>{{ $titik->kode }}</flux:link>
                        <x-lencana-simulasi :tampil="$titik->simulasi" class="ms-1" />
                        @if ($titik->nama)
                            <flux:text size="sm">{{ $titik->nama }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $titik->jenis->label() }}</flux:table.cell>
                    <flux:table.cell class="max-w-xs whitespace-normal">
                        {{ collect([
                            $titik->opd ? 'OPD '.$titik->opd->kode : null,
                            $titik->tiang ? 'Tiang '.$titik->tiang->kode : null,
                            $titik->lokasi,
                            $titik->lintang !== null ? $titik->lintang.', '.$titik->bujur : null,
                        ])->filter()->implode(' · ') ?: '—' }}
                    </flux:table.cell>
                    <flux:table.cell>{{ $titik->jenis->berPort() ? ($titik->kapasitas_port ?? '—') : 'Tanpa port' }}</flux:table.cell>
                    <flux:table.cell>{{ $titik->sambungan_count }}</flux:table.cell>
                    <flux:table.cell class="max-w-xs"><x-status-data :objek="$titik" /></flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            <flux:button size="sm" variant="ghost" :href="route('fo.titik.rinci', $titik)" wire:navigate>Hubungan</flux:button>
                            @can('update', $titik)
                                <flux:button size="sm" variant="ghost" wire:click="ubah({{ $titik->id }})">{{ $titik->status_data === \App\Enums\StatusData::Lengkap ? 'Ubah' : 'Lengkapi' }}</flux:button>
                            @endcan
                            @can('delete', $titik)
                                <flux:button size="sm" variant="ghost" wire:click="konfirmasiHapus({{ $titik->id }})">Hapus</flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7">Tidak ada titik jaringan yang cocok.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="formulir-titik" variant="flyout" class="w-full max-w-xl">
        <form wire:submit="simpan" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $idTarget === null ? 'Tambah titik jaringan' : 'Ubah titik jaringan' }}</flux:heading>
                <flux:subheading>Hanya kode dan jenis yang wajib. Data yang belum ada boleh dikosongkan.</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="kode" label="Kode" required maxlength="50" autocomplete="off" description="Huruf besar, unik." />
                <flux:select wire:model="jenis" label="Jenis" required>
                    @foreach (\App\Enums\JenisTitikJaringan::cases() as $pilihan)
                        <flux:select.option :value="$pilihan->value">{{ $pilihan->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input wire:model="nama" label="Nama" autocomplete="off" />

            <flux:fieldset>
                <flux:legend>Lokasi</flux:legend>
                <flux:description>Isi salah satu atau lebih: koordinat, tiang tempat terpasang, atau gedung OPD.</flux:description>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="lintang" label="Lintang" inputmode="decimal" placeholder="-7.358900" />
                    <flux:input wire:model="bujur" label="Bujur" inputmode="decimal" placeholder="109.903200" />
                    <flux:input wire:model="kode_tiang" label="Kode tiang" autocomplete="off" placeholder="TG-00123" />
                    <flux:select wire:model="opd_id" label="OPD">
                        <flux:select.option value="">Tidak di gedung OPD</flux:select.option>
                        @foreach ($this->pilihanOpd as $pilihan)
                            <flux:select.option :value="$pilihan->id">{{ $pilihan->nama }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="sm:col-span-2">
                        <flux:input wire:model="lokasi" label="Keterangan lokasi" placeholder="Rak A, ruang server" autocomplete="off" />
                    </div>
                </div>
            </flux:fieldset>

            <flux:input wire:model="kapasitas_port" label="Kapasitas port" type="number" min="0" max="576"
                description="Port terminasi dibuat otomatis sebanyak ini. Kosongkan untuk closure." />

            <flux:textarea wire:model="catatan" label="Catatan" rows="3" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="simpan-titik">Simpan</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="hapus-titik" class="w-full max-w-lg">
        <form wire:submit="hapus" class="space-y-6">
            <div>
                <flux:heading size="lg">Hapus titik jaringan?</flux:heading>
                <flux:subheading>{{ $kode }} beserta port terminasinya akan dihapus.</flux:subheading>
            </div>

            <flux:error name="hapus" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit">Hapus titik</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
