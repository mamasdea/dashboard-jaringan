<?php

use App\Enums\KondisiTiang;
use App\Enums\PemilikTiang;
use App\Enums\StatusData;
use App\Models\Tiang;
use App\Validasi\AturanFo;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Tiang FO')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $cari = '';

    #[Url]
    public string $pemilik_saring = '';

    #[Url]
    public string $kondisi_saring = '';

    #[Url]
    public string $status = '';

    #[Locked]
    public ?int $idTarget = null;

    #[Locked]
    public bool $punyaFoto = false;

    public string $kode = '';
    public string $lintang = '';
    public string $bujur = '';
    public string $pemilik = '';
    public string $kondisi = '';
    public string $catatan = '';
    public bool $hapusFoto = false;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $foto = null;

    private const array KOLOM = ['kode', 'lintang', 'bujur', 'pemilik', 'kondisi', 'catatan'];

    /**
     * Dijalankan pada setiap request ke komponen ini, termasuk pemanggilan
     * aksi secara langsung.
     */
    public function boot(): void
    {
        $this->authorize('viewAny', Tiang::class);
    }

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'pemilik_saring', 'kondisi_saring', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Tiang>
     */
    #[Computed]
    public function daftar(): LengthAwarePaginator
    {
        return Tiang::query()
            ->withCount('segmen')
            ->when($this->cari !== '', function ($query) {
                $kata = '%'.addcslashes($this->cari, '%_\\').'%';

                $query->where(fn ($q) => $q->whereLike('kode', $kata)->orWhereLike('catatan', $kata));
            })
            ->when(PemilikTiang::tryFrom($this->pemilik_saring), fn ($query, PemilikTiang $p) => $query->where('pemilik', $p))
            ->when(KondisiTiang::tryFrom($this->kondisi_saring), fn ($query, KondisiTiang $k) => $query->where('kondisi', $k))
            ->when(StatusData::tryFrom($this->status), fn ($query, StatusData $s) => $query->where('status_data', $s))
            ->orderBy('kode')
            ->paginate(15);
    }

    public function tambah(): void
    {
        $this->authorize('create', Tiang::class);

        $this->kosongkanFormulir();

        Flux::modal('formulir-tiang')->show();
    }

    public function ubah(int $id): void
    {
        $tiang = Tiang::findOrFail($id);

        $this->authorize('update', $tiang);

        $this->kosongkanFormulir();
        $this->idTarget = $tiang->id;
        $this->punyaFoto = $tiang->foto_path !== null;
        $this->kode = $tiang->kode;
        $this->lintang = (string) $tiang->lintang;
        $this->bujur = (string) $tiang->bujur;
        $this->pemilik = (string) $tiang->pemilik?->value;
        $this->kondisi = (string) $tiang->kondisi?->value;
        $this->catatan = (string) $tiang->catatan;

        Flux::modal('formulir-tiang')->show();
    }

    public function simpan(): void
    {
        $tiang = $this->idTarget === null ? new Tiang : Tiang::findOrFail($this->idTarget);

        $tiang->exists
            ? $this->authorize('update', $tiang)
            : $this->authorize('create', Tiang::class);

        $data = AturanFo::siapkanTiang($this->only(self::KOLOM));
        $this->kode = (string) $data['kode'];

        $validator = validator(
            [...$data, 'foto' => $this->foto],
            [...AturanFo::tiang($tiang->exists ? $tiang->id : null), 'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']],
            AturanFo::pesan(),
        );

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return;
        }

        $fotoLama = $tiang->foto_path;

        $tiang->fill(Arr::except($validator->validated(), ['foto']));

        if ($this->foto !== null) {
            $tiang->foto_path = $this->foto->store(Tiang::FOLDER_FOTO, Tiang::DISK_FOTO);
        } elseif ($this->hapusFoto) {
            $tiang->foto_path = null;
        }

        $tiang->save();

        if ($fotoLama !== null && $fotoLama !== $tiang->foto_path) {
            Storage::disk(Tiang::DISK_FOTO)->delete($fotoLama);
        }

        $perluDilengkapi = $tiang->status_data === StatusData::PerluDilengkapi;

        $this->kosongkanFormulir();
        Flux::modal('formulir-tiang')->close();
        Flux::toast(
            variant: $perluDilengkapi ? 'warning' : 'success',
            text: $perluDilengkapi ? 'Tiang disimpan dengan status Perlu dilengkapi.' : 'Tiang disimpan.',
        );
    }

    public function konfirmasiHapus(int $id): void
    {
        $tiang = Tiang::findOrFail($id);

        $this->authorize('delete', $tiang);

        $this->kosongkanFormulir();
        $this->idTarget = $tiang->id;
        $this->kode = $tiang->kode;

        Flux::modal('hapus-tiang')->show();
    }

    public function hapus(): void
    {
        $tiang = Tiang::find($this->idTarget) ?? new Tiang;

        $this->authorize('delete', $tiang);
        abort_unless($tiang->exists, 404);

        if ($tiang->segmen()->exists() || $tiang->titikJaringan()->exists()) {
            $this->addError('hapus', 'Tiang ini masih dilalui kabel atau menjadi tempat titik jaringan. Lepaskan dahulu dari rute dan titik tersebut.');

            return;
        }

        $tiang->delete();

        $this->kosongkanFormulir();
        Flux::modal('hapus-tiang')->close();
        Flux::toast(variant: 'success', text: 'Tiang dihapus.');
    }

    private function kosongkanFormulir(): void
    {
        $this->reset('idTarget', 'punyaFoto', 'hapusFoto', 'foto', ...self::KOLOM);
        $this->resetValidation();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Tiang FO</flux:heading>
            <flux:subheading size="lg">Cukup isi kode untuk mulai mendata; sisanya dapat dilengkapi kemudian.</flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="printer" :href="route('aset.label', ['jenis' => 'tiang', 'kode' => $this->daftar->pluck('kode')->implode(',')])" target="_blank" data-test="cetak-label">Cetak label halaman ini</flux:button>
        @can('create', \App\Models\Tiang::class)
            <flux:button variant="primary" icon="plus" wire:click="tambah" data-test="tambah-tiang">Tambah tiang</flux:button>
        @endcan
        </div>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <flux:input wire:model.live.debounce.300ms="cari" icon="magnifying-glass" placeholder="Cari kode atau catatan" aria-label="Cari tiang" />
        <flux:select wire:model.live="pemilik_saring" aria-label="Saring pemilik">
            <flux:select.option value="">Semua pemilik</flux:select.option>
            @foreach (\App\Enums\PemilikTiang::cases() as $pilihan)
                <flux:select.option :value="$pilihan->value">{{ $pilihan->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="kondisi_saring" aria-label="Saring kondisi">
            <flux:select.option value="">Semua kondisi</flux:select.option>
            @foreach (\App\Enums\KondisiTiang::cases() as $pilihan)
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
            <flux:table.column>Koordinat</flux:table.column>
            <flux:table.column>Pemilik</flux:table.column>
            <flux:table.column>Kondisi</flux:table.column>
            <flux:table.column>Kabel lewat</flux:table.column>
            <flux:table.column>Kelengkapan</flux:table.column>
            <flux:table.column align="end">Tindakan</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->daftar as $tiang)
                <flux:table.row :key="$tiang->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="\App\Support\TautanAset::jalur($tiang)" wire:navigate>{{ $tiang->kode }}</flux:link>
                        <x-lencana-simulasi :tampil="$tiang->simulasi" class="ms-1" />
                        @if ($tiang->foto_path)
                            <flux:link :href="route('fo.tiang.foto', $tiang)" target="_blank" class="ms-1 text-xs">Foto</flux:link>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $tiang->lintang !== null ? $tiang->lintang.', '.$tiang->bujur : '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $tiang->pemilik?->label() ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $tiang->kondisi?->label() ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $tiang->segmen_count }}</flux:table.cell>
                    <flux:table.cell class="max-w-xs"><x-status-data :objek="$tiang" /></flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            @can('update', $tiang)
                                <flux:button size="sm" variant="ghost" wire:click="ubah({{ $tiang->id }})">{{ $tiang->status_data === \App\Enums\StatusData::Lengkap ? 'Ubah' : 'Lengkapi' }}</flux:button>
                            @endcan
                            @can('delete', $tiang)
                                <flux:button size="sm" variant="ghost" wire:click="konfirmasiHapus({{ $tiang->id }})">Hapus</flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7">Tidak ada tiang yang cocok.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="formulir-tiang" variant="flyout" class="w-full max-w-xl">
        <form wire:submit="simpan" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $idTarget === null ? 'Tambah tiang' : 'Ubah tiang' }}</flux:heading>
                <flux:subheading>Hanya kode yang wajib. Data yang belum ada boleh dikosongkan.</flux:subheading>
            </div>

            <flux:input wire:model="kode" label="Kode" required maxlength="50" autocomplete="off" description="Huruf besar, unik." />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="lintang" label="Lintang" inputmode="decimal" placeholder="-7.358900" />
                <flux:input wire:model="bujur" label="Bujur" inputmode="decimal" placeholder="109.903200" />
                <flux:select wire:model="pemilik" label="Pemilik" placeholder="Belum diketahui">
                    <flux:select.option value="">Belum diketahui</flux:select.option>
                    @foreach (\App\Enums\PemilikTiang::cases() as $pilihan)
                        <flux:select.option :value="$pilihan->value">{{ $pilihan->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="kondisi" label="Kondisi" placeholder="Belum diperiksa">
                    <flux:select.option value="">Belum diperiksa</flux:select.option>
                    @foreach (\App\Enums\KondisiTiang::cases() as $pilihan)
                        <flux:select.option :value="$pilihan->value">{{ $pilihan->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:field>
                <flux:label>Foto</flux:label>
                @if ($punyaFoto && ! $hapusFoto && $foto === null && $idTarget !== null)
                    <img src="{{ route('fo.tiang.foto', $idTarget) }}" alt="Foto saat ini" class="mb-2 max-h-40 rounded-lg border border-zinc-200 dark:border-zinc-700" />
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
                <flux:button variant="primary" type="submit" data-test="simpan-tiang">Simpan</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="hapus-tiang" class="w-full max-w-lg">
        <form wire:submit="hapus" class="space-y-6">
            <div>
                <flux:heading size="lg">Hapus tiang?</flux:heading>
                <flux:subheading>Tiang {{ $kode }} beserta fotonya akan dihapus.</flux:subheading>
            </div>

            <flux:error name="hapus" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit">Hapus tiang</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
