<?php

use App\Enums\Izin;
use App\Enums\PrioritasTiket;
use App\Enums\StatusTiket;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\Tiket;
use App\Models\User;
use App\Tiket\FormulirTiket;
use App\Tiket\LayananTiket;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Tiket gangguan')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $cari = '';

    /** Kosong = yang masih terbuka; "semua" atau nilai StatusTiket. */
    #[Url]
    public string $status = '';

    #[Url]
    public string $prioritas = '';

    public FormulirTiket $form;

    public string $teknisi_id = '';

    /**
     * Dijalankan pada setiap request ke komponen ini, termasuk pemanggilan
     * aksi secara langsung.
     */
    public function boot(): void
    {
        $this->authorize('viewAny', Tiket::class);
    }

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'status', 'prioritas'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Teknisi hanya melihat tiket yang ditugaskan kepadanya.
     *
     * @return LengthAwarePaginator<int, Tiket>
     */
    #[Computed]
    public function daftar(): LengthAwarePaginator
    {
        $pelaku = Auth::user();

        return Tiket::query()
            ->with(['opd', 'perangkat', 'teknisi', 'insiden'])
            ->when(! $pelaku->boleh(Izin::KelolaTiket), fn ($query) => $query->where('teknisi_id', $pelaku->id))
            ->when($this->cari !== '', function ($query) {
                $kata = '%'.addcslashes($this->cari, '%_\\').'%';

                $query->where(fn ($q) => $q->whereLike('nomor', $kata)->orWhereLike('judul', $kata)
                    ->orWhereHas('opd', fn ($o) => $o->whereLike('nama', $kata)));
            })
            ->when($this->status === '', fn ($query) => $query->terbuka())
            ->when(StatusTiket::tryFrom($this->status), fn ($query, StatusTiket $s) => $query->where('status', $s))
            ->when(PrioritasTiket::tryFrom($this->prioritas), fn ($query, PrioritasTiket $p) => $query->where('prioritas', $p))
            ->orderByRaw("CASE prioritas WHEN 'kritis' THEN 0 WHEN 'tinggi' THEN 1 WHEN 'sedang' THEN 2 ELSE 3 END")
            ->orderByDesc('waktu_mulai')
            ->paginate(15);
    }

    /**
     * @return Collection<int, Opd>
     */
    #[Computed]
    public function daftarOpd(): Collection
    {
        return Opd::query()->orderBy('nama')->get(['id', 'nama', 'kode']);
    }

    /**
     * @return Collection<int, Perangkat>
     */
    #[Computed]
    public function daftarPerangkat(): Collection
    {
        return Perangkat::query()
            ->when($this->form->opd_id !== '', fn ($query) => $query->where('opd_id', (int) $this->form->opd_id))
            ->orderBy('kode_aset')->limit(500)->get(['id', 'kode_aset', 'nama']);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function daftarTeknisi(): Collection
    {
        return User::query()->orderBy('name')->get()->filter(fn (User $u) => $u->boleh(Izin::TanganiTiket))->values();
    }

    public function tambah(): void
    {
        $this->authorize('create', Tiket::class);

        $this->form->kosongkan();
        $this->teknisi_id = '';

        Flux::modal('formulir-tiket')->show();
    }

    public function simpan(LayananTiket $layanan): void
    {
        $this->authorize('create', Tiket::class);

        $data = $this->form->data();
        $teknisi = $this->teknisi_id === '' ? null : User::query()->findOrFail((int) $this->teknisi_id);

        $tiket = DB::transaction(function () use ($layanan, $data, $teknisi): Tiket {
            $tiket = $layanan->buat($data, Auth::user());
            $layanan->tugaskan($tiket, $teknisi, Auth::user());

            return $tiket;
        });

        $this->redirectRoute('tiket.rinci', $tiket, navigate: true);
    }
}; ?>

<section class="w-full space-y-6">
    @php
        $zona = config('app.display_timezone');
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Tiket gangguan</flux:heading>
            <flux:subheading size="lg">
                @can(\App\Enums\Izin::KelolaTiket->value)
                    Pekerjaan penanganan gangguan. Status tiket adalah status pekerjaan, terpisah dari status monitoring.
                @else
                    Tiket yang ditugaskan kepada Anda.
                @endcan
            </flux:subheading>
        </div>

        @can('create', \App\Models\Tiket::class)
            <flux:button variant="primary" icon="plus" wire:click="tambah" data-test="tambah-tiket">Buat tiket</flux:button>
        @endcan
    </div>

    <div class="grid gap-3 sm:grid-cols-[1fr_12rem_12rem]">
        <flux:input wire:model.live.debounce.300ms="cari" icon="magnifying-glass" placeholder="Cari nomor, judul, atau OPD" clearable />
        <flux:select wire:model.live="status" aria-label="Status pekerjaan">
            <flux:select.option value="">Masih terbuka</flux:select.option>
            <flux:select.option value="semua">Semua status</flux:select.option>
            @foreach (\App\Enums\StatusTiket::cases() as $s)
                <flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="prioritas" aria-label="Prioritas">
            <flux:select.option value="">Semua prioritas</flux:select.option>
            @foreach (\App\Enums\PrioritasTiket::cases() as $p)
                <flux:select.option :value="$p->value">{{ $p->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->daftar->isEmpty())
        <flux:text data-test="tanpa-tiket">Tidak ada tiket yang cocok.</flux:text>
    @else
        <flux:table :paginate="$this->daftar" data-test="tabel-tiket">
            <flux:table.columns>
                <flux:table.column>Nomor</flux:table.column>
                <flux:table.column>Judul</flux:table.column>
                <flux:table.column>OPD / perangkat</flux:table.column>
                <flux:table.column>Mulai (WIB)</flux:table.column>
                <flux:table.column>Prioritas</flux:table.column>
                <flux:table.column>Status pekerjaan</flux:table.column>
                <flux:table.column>Teknisi</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->daftar as $tiket)
                    <flux:table.row :key="'tiket-'.$tiket->id">
                        <flux:table.cell variant="strong" class="tabular-nums">
                            <flux:link :href="route('tiket.rinci', $tiket)" wire:navigate>{{ $tiket->nomor }}</flux:link>
                        </flux:table.cell>
                        <flux:table.cell class="max-w-sm whitespace-normal">
                            {{ $tiket->judul }}
                            @if ($tiket->sumber === \App\Models\Tiket::OTOMATIS)
                                <flux:badge size="sm" color="zinc" class="ms-1">Otomatis</flux:badge>
                            @endif
                            @if ($tiket->monitoring_pulih_pada !== null && $tiket->status->terbuka())
                                <flux:text size="sm">Monitoring sudah pulih; pekerjaan belum ditutup.</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-normal">
                            {{ $tiket->opd?->nama ?? \App\Support\Tampilan::TIDAK_TERSEDIA }}
                            @if ($tiket->perangkat)
                                <flux:text size="sm">{{ $tiket->perangkat->kode_aset }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $tiket->waktu_mulai->timezone($zona)->format('d-m-Y H:i') }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$tiket->prioritas->warna()">{{ $tiket->prioritas->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$tiket->status->warna()">{{ $tiket->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $tiket->teknisi?->name ?? 'Belum ditugaskan' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    @can('create', \App\Models\Tiket::class)
        <flux:modal name="formulir-tiket" variant="flyout" class="w-full max-w-xl">
            <form wire:submit="simpan" class="space-y-6">
                <div>
                    <flux:heading size="lg">Buat tiket</flux:heading>
                    <flux:subheading>Tiket untuk gangguan yang dilaporkan orang. Gangguan yang dideteksi monitoring membuat tiketnya sendiri.</flux:subheading>
                </div>

                @include('partials.isian-tiket')

                <flux:select wire:model="teknisi_id" label="Teknisi" placeholder="Belum ditugaskan">
                    <flux:select.option value="">Belum ditugaskan</flux:select.option>
                    @foreach ($this->daftarTeknisi as $teknisi)
                        <flux:select.option :value="$teknisi->id">{{ $teknisi->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:error name="tiket" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Batal</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="simpan-tiket">Simpan</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</section>
