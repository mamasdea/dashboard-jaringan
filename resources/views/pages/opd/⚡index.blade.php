<?php

use App\Models\Opd;
use App\Validasi\AturanOpd;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('OPD')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $cari = '';

    #[Url]
    public string $status = '';

    #[Locked]
    public ?int $idTarget = null;

    public string $kode = '';
    public string $nama = '';
    public string $alamat = '';
    public string $lintang = '';
    public string $bujur = '';
    public string $kontak_teknis_nama = '';
    public string $kontak_teknis_telepon = '';
    public string $kontak_teknis_email = '';
    public bool $aktif = true;

    /**
     * Dijalankan pada setiap request ke komponen ini, termasuk pemanggilan
     * aksi secara langsung.
     */
    public function boot(): void
    {
        $this->authorize('viewAny', Opd::class);
    }

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Opd>
     */
    #[Computed]
    public function daftar(): LengthAwarePaginator
    {
        return Opd::query()
            ->withCount('perangkat')
            ->with('switchUtama:id,opd_id,kode_aset,nama')
            ->when($this->cari !== '', function ($query) {
                $kata = '%'.addcslashes($this->cari, '%_\\').'%';

                $query->where(fn ($q) => $q
                    ->whereLike('nama', $kata)
                    ->orWhereLike('kode', $kata)
                    ->orWhereLike('alamat', $kata)
                    ->orWhereLike('kontak_teknis_nama', $kata));
            })
            ->when($this->status === 'aktif', fn ($query) => $query->where('aktif', true))
            ->when($this->status === 'nonaktif', fn ($query) => $query->where('aktif', false))
            ->orderBy('nama')
            ->paginate(15);
    }

    public function tambah(): void
    {
        $this->authorize('create', Opd::class);

        $this->kosongkanFormulir();

        Flux::modal('formulir-opd')->show();
    }

    public function ubah(int $id): void
    {
        $opd = Opd::findOrFail($id);

        $this->authorize('update', $opd);

        $this->kosongkanFormulir();
        $this->idTarget = $opd->id;
        $this->kode = $opd->kode;
        $this->nama = $opd->nama;
        $this->alamat = (string) $opd->alamat;
        $this->lintang = (string) $opd->lintang;
        $this->bujur = (string) $opd->bujur;
        $this->kontak_teknis_nama = (string) $opd->kontak_teknis_nama;
        $this->kontak_teknis_telepon = (string) $opd->kontak_teknis_telepon;
        $this->kontak_teknis_email = (string) $opd->kontak_teknis_email;
        $this->aktif = $opd->aktif;

        Flux::modal('formulir-opd')->show();
    }

    public function simpan(): void
    {
        $opd = $this->idTarget === null ? new Opd : Opd::findOrFail($this->idTarget);

        $opd->exists
            ? $this->authorize('update', $opd)
            : $this->authorize('create', Opd::class);

        $data = AturanOpd::siapkan($this->only([
            'kode', 'nama', 'alamat', 'lintang', 'bujur',
            'kontak_teknis_nama', 'kontak_teknis_telepon', 'kontak_teknis_email', 'aktif',
        ]));

        $this->kode = (string) $data['kode'];

        $validator = validator($data, AturanOpd::aturan($opd->exists ? $opd->id : null), AturanOpd::pesan());

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return;
        }

        $opd->fill($validator->validated())->save();

        $this->kosongkanFormulir();
        Flux::modal('formulir-opd')->close();
        Flux::toast(variant: 'success', text: 'OPD disimpan.');
    }

    public function konfirmasiHapus(int $id): void
    {
        $opd = Opd::findOrFail($id);

        $this->authorize('delete', $opd);

        $this->kosongkanFormulir();
        $this->idTarget = $opd->id;
        $this->kode = $opd->kode;
        $this->nama = $opd->nama;

        Flux::modal('hapus-opd')->show();
    }

    public function hapus(): void
    {
        $opd = Opd::find($this->idTarget) ?? new Opd;

        $this->authorize('delete', $opd);
        abort_unless($opd->exists, 404);

        $jumlahPerangkat = $opd->perangkat()->count();

        if ($jumlahPerangkat > 0) {
            $this->addError('hapus', "OPD ini masih memiliki {$jumlahPerangkat} perangkat. Pindahkan atau hapus perangkatnya dahulu, atau nonaktifkan OPD ini.");

            return;
        }

        if (\App\Models\TitikJaringan::query()->where('opd_id', $opd->id)->exists()) {
            $this->addError('hapus', 'OPD ini masih menjadi lokasi titik jaringan FO. Pindahkan titik tersebut dahulu, atau nonaktifkan OPD ini.');

            return;
        }

        $opd->delete();

        $this->kosongkanFormulir();
        Flux::modal('hapus-opd')->close();
        Flux::toast(variant: 'success', text: 'OPD dihapus.');
    }

    private function kosongkanFormulir(): void
    {
        $this->reset(
            'idTarget', 'kode', 'nama', 'alamat', 'lintang', 'bujur',
            'kontak_teknis_nama', 'kontak_teknis_telepon', 'kontak_teknis_email', 'aktif',
        );
        $this->resetValidation();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">OPD</flux:heading>
            <flux:subheading size="lg">Organisasi perangkat daerah yang terhubung ke jaringan intra.</flux:subheading>
        </div>

        @can('create', \App\Models\Opd::class)
            <div class="flex gap-2">
                <flux:button icon="arrow-up-tray" :href="route('impor', 'opd')" wire:navigate>Impor CSV</flux:button>
                <flux:button variant="primary" icon="plus" wire:click="tambah" data-test="tambah-opd">Tambah OPD</flux:button>
            </div>
        @endcan
    </div>

    <div class="mb-4 flex flex-wrap gap-3">
        <div class="w-full max-w-sm">
            <flux:input wire:model.live.debounce.300ms="cari" icon="magnifying-glass" placeholder="Cari nama, kode, alamat, atau kontak" aria-label="Cari OPD" />
        </div>
        <div class="w-full max-w-48">
            <flux:select wire:model.live="status" aria-label="Saring status">
                <flux:select.option value="">Semua status</flux:select.option>
                <flux:select.option value="aktif">Aktif</flux:select.option>
                <flux:select.option value="nonaktif">Nonaktif</flux:select.option>
            </flux:select>
        </div>
    </div>

    <flux:table :paginate="$this->daftar">
        <flux:table.columns>
            <flux:table.column>Kode</flux:table.column>
            <flux:table.column>Nama</flux:table.column>
            <flux:table.column>Kontak teknis</flux:table.column>
            <flux:table.column>Koordinat</flux:table.column>
            <flux:table.column>Perangkat</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column align="end">Tindakan</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->daftar as $opd)
                <flux:table.row :key="$opd->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="\App\Support\TautanAset::jalur($opd)" wire:navigate>{{ $opd->kode }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell class="max-w-sm whitespace-normal">
                        {{ $opd->nama }}
                        <x-lencana-simulasi :tampil="$opd->simulasi" class="ms-1" />
                        @if ($opd->alamat)
                            <flux:text size="sm">{{ $opd->alamat }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $opd->kontak_teknis_nama ?? '—' }}
                        @if ($opd->kontak_teknis_telepon || $opd->kontak_teknis_email)
                            <flux:text size="sm">{{ collect([$opd->kontak_teknis_telepon, $opd->kontak_teknis_email])->filter()->implode(' · ') }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $opd->lintang !== null ? $opd->lintang.', '.$opd->bujur : '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:link :href="route('perangkat.index', ['opd' => $opd->id])" wire:navigate>{{ $opd->perangkat_count }}</flux:link>
                        <flux:text size="sm">{{ $opd->switchUtama ? 'Utama: '.$opd->switchUtama->kode_aset : 'Belum ada switch utama' }}</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$opd->aktif ? 'green' : 'zinc'">{{ $opd->aktif ? 'Aktif' : 'Nonaktif' }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            @can('update', $opd)
                                <flux:button size="sm" variant="ghost" wire:click="ubah({{ $opd->id }})">Ubah</flux:button>
                            @endcan
                            @can('delete', $opd)
                                <flux:button size="sm" variant="ghost" wire:click="konfirmasiHapus({{ $opd->id }})">Hapus</flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7">Tidak ada OPD yang cocok.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="formulir-opd" variant="flyout" class="w-full max-w-xl">
        <form wire:submit="simpan" class="space-y-6">
            <flux:heading size="lg">{{ $idTarget === null ? 'Tambah OPD' : 'Ubah OPD' }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="kode" label="Kode" required maxlength="30" autocomplete="off" description="Huruf besar, unik." />
                <div class="sm:col-span-2">
                    <flux:input wire:model="nama" label="Nama" required autocomplete="off" />
                </div>
            </div>

            <flux:textarea wire:model="alamat" label="Alamat" rows="2" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="lintang" label="Lintang" inputmode="decimal" placeholder="-7.358900" />
                <flux:input wire:model="bujur" label="Bujur" inputmode="decimal" placeholder="109.903200" />
            </div>

            <flux:fieldset>
                <flux:legend>Kontak teknis</flux:legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <flux:input wire:model="kontak_teknis_nama" label="Nama" autocomplete="off" />
                    </div>
                    <flux:input wire:model="kontak_teknis_telepon" label="Telepon" type="tel" autocomplete="off" />
                    <flux:input wire:model="kontak_teknis_email" label="Email" type="email" autocomplete="off" />
                </div>
            </flux:fieldset>

            <flux:switch wire:model="aktif" label="OPD aktif" description="OPD nonaktif tetap tersimpan beserta perangkatnya." />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="simpan-opd">Simpan</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="hapus-opd" class="w-full max-w-lg">
        <form wire:submit="hapus" class="space-y-6">
            <div>
                <flux:heading size="lg">Hapus OPD?</flux:heading>
                <flux:subheading>{{ $kode }} — {{ $nama }} akan dihapus. Riwayatnya di log aktivitas tetap tersimpan.</flux:subheading>
            </div>

            <flux:error name="hapus" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit">Hapus OPD</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
