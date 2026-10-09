<?php

use App\Enums\StatusPemakaian;
use App\Fo\LayananHubungan;
use App\Models\CoreKabel;
use App\Models\KoneksiEndpoint;
use App\Models\PortTerminasi;
use App\Models\RiwayatHubungan;
use App\Models\Sambungan;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Validasi\AturanFo;
use App\Validasi\Normalisasi;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rincian segmen kabel')] class extends Component {
    public SegmenKabel $segmen;

    public string $kode_tiang_baru = '';

    public string $jalur_teks = '';

    /**
     * Dijalankan pada setiap request ke komponen ini, termasuk pemanggilan
     * aksi secara langsung.
     */
    public function boot(): void
    {
        $this->authorize('viewAny', SegmenKabel::class);
    }

    public function mount(SegmenKabel $segmen): void
    {
        $this->authorize('view', $segmen);

        $this->segmen = $segmen;
        $this->jalur_teks = $this->jalurSebagaiTeks($segmen->jalur_peta);
    }

    /**
     * @return Collection<int, Tiang>
     */
    #[Computed]
    public function rute(): Collection
    {
        return $this->segmen->tiang()->get();
    }

    /**
     * @return Collection<int, CoreKabel>
     */
    #[Computed]
    public function daftarCore(): Collection
    {
        return $this->segmen->core()->get();
    }

    /**
     * Pemakaian tiap ujung core: [core_id][titik_jaringan_id] => keterangan.
     *
     * @return array<int, array<int, string>>
     */
    #[Computed]
    public function pemakaian(): array
    {
        $ids = $this->daftarCore->modelKeys();
        $peta = [];

        Sambungan::query()
            ->with(['coreMasuk.segmen', 'coreKeluar.segmen'])
            ->where(fn ($query) => $query->whereIn('core_masuk_id', $ids)->orWhereIn('core_keluar_id', $ids))
            ->get()
            ->each(function (Sambungan $s) use (&$peta, $ids): void {
                if (in_array($s->core_masuk_id, $ids, true)) {
                    $peta[$s->core_masuk_id][$s->titik_jaringan_id] = 'Sambung ke '.$s->coreKeluar->label();
                }

                if (in_array($s->core_keluar_id, $ids, true)) {
                    $peta[$s->core_keluar_id][$s->titik_jaringan_id] = 'Sambung ke '.$s->coreMasuk->label();
                }
            });

        PortTerminasi::query()->with('titik')->whereIn('core_id', $ids)->get()
            ->each(function (PortTerminasi $p) use (&$peta): void {
                $peta[(int) $p->core_id][$p->titik_jaringan_id] = 'Terminasi di '.$p->label();
            });

        KoneksiEndpoint::query()->with('portPerangkat.perangkat')->whereIn('core_id', $ids)->get()
            ->each(function (KoneksiEndpoint $k) use (&$peta): void {
                $peta[(int) $k->core_id][(int) $k->titik_jaringan_id] = 'Langsung ke '.$k->portPerangkat->label();
            });

        return $peta;
    }

    /**
     * @return Collection<int, RiwayatHubungan>
     */
    #[Computed]
    public function riwayat(): Collection
    {
        return RiwayatHubungan::query()->where('segmen_kabel_id', $this->segmen->id)->orderByDesc('id')->limit(30)->get();
    }

    public function tambahTiang(LayananHubungan $layanan): void
    {
        $this->authorize('update', $this->segmen);

        $kode = Normalisasi::kode($this->kode_tiang_baru);
        $tiang = $kode === null ? null : Tiang::query()->where('kode', $kode)->first();

        if ($tiang === null) {
            $this->addError('kode_tiang_baru', 'Tiang dengan kode tersebut tidak ditemukan. Tambahkan dahulu di halaman Tiang.');

            return;
        }

        $this->resetValidation();
        $this->aturRute($layanan, [...$this->rute->modelKeys(), $tiang->id]);
        $this->kode_tiang_baru = '';
    }

    public function geserTiang(LayananHubungan $layanan, int $tiangId, int $arah): void
    {
        $this->authorize('update', $this->segmen);

        $ids = $this->rute->modelKeys();
        $posisi = array_search($tiangId, $ids, true);
        $tujuan = $posisi === false ? null : $posisi + ($arah < 0 ? -1 : 1);

        if ($posisi === false || $tujuan < 0 || $tujuan >= count($ids)) {
            return;
        }

        [$ids[$posisi], $ids[$tujuan]] = [$ids[$tujuan], $ids[$posisi]];

        $this->aturRute($layanan, $ids);
    }

    public function lepasTiang(LayananHubungan $layanan, int $tiangId): void
    {
        $this->authorize('update', $this->segmen);

        $this->aturRute($layanan, array_values(array_diff($this->rute->modelKeys(), [$tiangId])));
    }

    /**
     * Mengisi kotak jalur dari titik awal, tiang berurutan, lalu titik akhir.
     * Belum disimpan sampai tombol simpan ditekan.
     */
    public function susunJalur(): void
    {
        $this->authorize('update', $this->segmen);

        $objek = collect([$this->segmen->titikAwal, ...$this->rute, $this->segmen->titikAkhir])->filter();
        $berkoordinat = $objek->filter(fn ($o) => $o->lintang !== null && $o->bujur !== null);

        $this->jalur_teks = $berkoordinat->map(fn ($o) => $o->lintang.', '.$o->bujur)->implode("\n");
        $this->resetValidation('jalur_teks');

        $terlewat = $objek->count() - $berkoordinat->count();

        Flux::toast(
            variant: $terlewat > 0 ? 'warning' : 'success',
            text: $terlewat > 0
                ? "{$terlewat} titik/tiang tanpa koordinat dilewati. Periksa lalu simpan jalur."
                : 'Jalur disusun dari titik dan tiang. Periksa lalu simpan.',
        );
    }

    public function simpanJalur(): void
    {
        $this->authorize('update', $this->segmen);

        $hasil = AturanFo::uraiJalur($this->jalur_teks);

        if ($hasil['galat'] !== []) {
            $this->resetValidation('jalur_teks');

            foreach ($hasil['galat'] as $pesan) {
                $this->addError('jalur_teks', $pesan);
            }

            return;
        }

        $this->resetValidation('jalur_teks');
        $this->segmen->jalur_peta = $hasil['jalur'];
        $this->segmen->save();
        $this->jalur_teks = $this->jalurSebagaiTeks($this->segmen->jalur_peta);

        Flux::toast(variant: 'success', text: $hasil['jalur'] === null ? 'Jalur peta dikosongkan.' : 'Jalur peta disimpan dan panjang peta dihitung ulang.');
    }

    public function tandaiCore(LayananHubungan $layanan, int $coreId): void
    {
        $this->authorize('update', $this->segmen);

        $core = $this->segmen->core()->findOrFail($coreId);

        $layanan->tandaiCore($core, $core->status !== StatusPemakaian::Rusak);

        unset($this->daftarCore);
    }

    /**
     * @param  list<int>  $ids
     */
    private function aturRute(LayananHubungan $layanan, array $ids): void
    {
        $layanan->aturRuteTiang($this->segmen, $ids);

        $this->resetValidation();
        unset($this->rute, $this->riwayat);
    }

    /**
     * @param  list<array{0: float, 1: float}>|null  $jalur
     */
    private function jalurSebagaiTeks(?array $jalur): string
    {
        return collect($jalur ?? [])->map(fn (array $titik) => $titik[1].', '.$titik[0])->implode("\n");
    }
}; ?>

<section class="w-full space-y-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">
                {{ $segmen->kode }}
                <x-lencana-simulasi :tampil="$segmen->simulasi" class="ms-1" />
            </flux:heading>
            <flux:subheading size="lg">
                Segmen kabel {{ $segmen->klasifikasi?->label() ?? '(klasifikasi belum ditentukan)' }}{{ $segmen->nama ? ' · '.$segmen->nama : '' }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('fo.penelusuran', ['jenis' => 'kabel', 'kode' => $segmen->kode])" icon="arrows-right-left" wire:navigate>Potensi terdampak</flux:button>
            <flux:button :href="route('fo.kabel')" icon="arrow-left" wire:navigate>Kembali ke daftar</flux:button>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-test="ringkasan-segmen">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text size="sm">Titik awal → akhir</flux:text>
            <div class="font-semibold">
                @if ($segmen->titikAwal)
                    <flux:link :href="route('fo.titik.rinci', $segmen->titikAwal)" wire:navigate>{{ $segmen->titikAwal->kode }}</flux:link>
                @else
                    ?
                @endif
                →
                @if ($segmen->titikAkhir)
                    <flux:link :href="route('fo.titik.rinci', $segmen->titikAkhir)" wire:navigate>{{ $segmen->titikAkhir->kode }}</flux:link>
                @else
                    ?
                @endif
            </div>
        </div>
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text size="sm">Panjang hasil pengukuran</flux:text>
            <div class="font-semibold tabular-nums">{{ $segmen->panjang_ukur_m !== null ? number_format((float) $segmen->panjang_ukur_m, 2, ',', '.').' m' : 'Belum diukur' }}</div>
        </div>
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text size="sm">Panjang hasil perhitungan peta</flux:text>
            <div class="font-semibold tabular-nums">{{ $segmen->panjang_peta_m !== null ? number_format((float) $segmen->panjang_peta_m, 2, ',', '.').' m' : 'Belum ada jalur' }}</div>
            @if ($segmen->panjang_ukur_m !== null && $segmen->panjang_peta_m !== null)
                <flux:text size="sm">Selisih {{ number_format((float) $segmen->panjang_ukur_m - (float) $segmen->panjang_peta_m, 2, ',', '.') }} m (ukur − peta)</flux:text>
            @endif
        </div>
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text size="sm">Kelengkapan</flux:text>
            <x-status-data :objek="$segmen" />
        </div>
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        <div>
            <flux:heading class="mb-1">Rute tiang</flux:heading>
            <flux:text size="sm" class="mb-3">Urutan tiang yang dilalui dari titik awal ke titik akhir. Satu tiang boleh dilalui banyak kabel.</flux:text>

            <ol class="mb-3 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="rute-tiang">
                @forelse ($this->rute as $tiang)
                    <li class="flex items-center justify-between gap-2 px-3 py-2 text-sm" wire:key="rute-{{ $tiang->id }}">
                        <span>
                            <span class="me-2 inline-block w-6 text-end tabular-nums text-zinc-500">{{ $tiang->pivot->urutan }}.</span>
                            <span class="font-medium">{{ $tiang->kode }}</span>
                            @if ($tiang->lintang === null)
                                <flux:badge size="sm" color="amber" class="ms-1">tanpa koordinat</flux:badge>
                            @endif
                        </span>
                        @can('update', $segmen)
                            <span class="flex gap-1">
                                <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="geserTiang({{ $tiang->id }}, -1)" :disabled="$loop->first" aria-label="Naikkan {{ $tiang->kode }}" />
                                <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="geserTiang({{ $tiang->id }}, 1)" :disabled="$loop->last" aria-label="Turunkan {{ $tiang->kode }}" />
                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="lepasTiang({{ $tiang->id }})" aria-label="Lepas {{ $tiang->kode }} dari rute" />
                            </span>
                        @endcan
                    </li>
                @empty
                    <li class="px-3 py-2 text-sm text-zinc-500">Belum ada tiang dalam rute.</li>
                @endforelse
            </ol>

            @can('update', $segmen)
                <form wire:submit="tambahTiang" class="flex items-start gap-2">
                    <div class="flex-1">
                        <flux:input wire:model="kode_tiang_baru" placeholder="Kode tiang berikutnya" aria-label="Kode tiang berikutnya" autocomplete="off" />
                        <flux:error name="kode_tiang_baru" />
                    </div>
                    <flux:button type="submit" icon="plus" data-test="tambah-tiang-rute">Tambah ke akhir rute</flux:button>
                </form>
            @endcan
            <flux:error name="hubungan" />
        </div>

        <div>
            <flux:heading class="mb-1">Jalur peta</flux:heading>
            <flux:text size="sm" class="mb-3">Satu titik per baris: lintang, bujur. Panjang peta dihitung dari jalur ini dan tidak mengubah panjang hasil pengukuran.</flux:text>

            <form wire:submit="simpanJalur" class="space-y-3">
                <flux:textarea wire:model="jalur_teks" rows="8" class="font-mono text-sm" aria-label="Jalur peta" placeholder="-7.358900, 109.903200&#10;-7.359700, 109.904100" :readonly="auth()->user()->cannot('update', $segmen)" />
                <flux:error name="jalur_teks" />

                @can('update', $segmen)
                    <div class="flex flex-wrap gap-2">
                        <flux:button type="submit" variant="primary" data-test="simpan-jalur">Simpan jalur</flux:button>
                        <flux:button wire:click="susunJalur">Susun dari titik dan tiang</flux:button>
                    </div>
                @endcan
            </form>
        </div>
    </div>

    <div>
        <flux:heading class="mb-3">Core ({{ $this->daftarCore->count() }})</flux:heading>

        @if ($this->daftarCore->isEmpty())
            <flux:text>Jumlah core belum diisi. Lengkapi dari daftar segmen kabel.</flux:text>
        @else
            <flux:table data-test="tabel-core">
                <flux:table.columns>
                    <flux:table.column>Core</flux:table.column>
                    <flux:table.column>Tube</flux:table.column>
                    <flux:table.column>Warna</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Di {{ $segmen->titikAwal?->kode ?? 'titik awal' }}</flux:table.column>
                    <flux:table.column>Di {{ $segmen->titikAkhir?->kode ?? 'titik akhir' }}</flux:table.column>
                    <flux:table.column align="end">Tindakan</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->daftarCore as $core)
                        <flux:table.row :key="$core->id">
                            <flux:table.cell variant="strong">{{ $core->nomor }}</flux:table.cell>
                            <flux:table.cell>{{ $core->tube }}</flux:table.cell>
                            <flux:table.cell>{{ $core->warna }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="['tersedia' => 'green', 'terpakai' => 'sky', 'rusak' => 'red'][$core->status->value]">{{ $core->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-normal">{{ $this->pemakaian[$core->id][$segmen->titik_awal_id] ?? '—' }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal">{{ $this->pemakaian[$core->id][$segmen->titik_akhir_id] ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="end">
                                @can('update', $segmen)
                                    <flux:button size="sm" variant="ghost" wire:click="tandaiCore({{ $core->id }})">
                                        {{ $core->status === \App\Enums\StatusPemakaian::Rusak ? 'Pulihkan' : 'Tandai rusak' }}
                                    </flux:button>
                                @endcan
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            <flux:text size="sm" class="mt-2">Sambungan dan terminasi diatur di halaman titik jaringan pada tiap ujung kabel.</flux:text>
        @endif
    </div>

    <x-kartu-qr :aset="$segmen" />

    <div>
        <flux:heading class="mb-3">Riwayat rute</flux:heading>
        <x-riwayat-hubungan :riwayat="$this->riwayat" />
    </div>
</section>
