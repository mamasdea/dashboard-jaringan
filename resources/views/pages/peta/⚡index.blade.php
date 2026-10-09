<?php

use App\Enums\Izin;
use App\Models\Opd;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Peta jaringan')] class extends Component {
    /**
     * Aset yang disorot saat peta dibuka, mis. "tiang:TG-001".
     */
    #[Url]
    public string $fokus = '';

    /**
     * Aset titik yang koordinatnya dapat digeser di peta.
     *
     * @var array<string, class-string<Model>>
     */
    private const array JENIS_TITIK = [
        'opd' => Opd::class,
        'tiang' => Tiang::class,
        'titik' => TitikJaringan::class,
    ];

    /**
     * Dijalankan pada setiap request ke komponen ini, termasuk pemanggilan
     * aksi secara langsung.
     */
    public function boot(): void
    {
        $this->authorize(Izin::LihatAset->value);
    }

    /**
     * @return Collection<int, Opd>
     */
    #[Computed]
    public function pilihanOpd(): Collection
    {
        return Opd::query()->orderBy('nama')->get(['id', 'kode', 'nama']);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function konfigurasi(): array
    {
        return [
            'urlData' => route('peta.data'),
            'tile' => [
                'url' => config('peta.tile.url'),
                'atribusi' => config('peta.tile.atribusi'),
                'subdomain' => config('peta.tile.subdomain'),
                'zoomMaks' => config('peta.tile.zoom_maks'),
            ],
            'pusat' => [config('peta.pusat.lintang'), config('peta.pusat.bujur')],
            'zoom' => config('peta.zoom'),
            'fokus' => $this->fokus,
            // Hanya untuk menampilkan tombol; server tetap memeriksa di tiap aksi.
            'bolehUbah' => auth()->user()->can(Izin::KelolaAset->value),
        ];
    }

    /**
     * Menyimpan koordinat aset yang digeser di peta.
     *
     * @return array{ok: bool, pesan: string}
     */
    #[Renderless]
    public function simpanKoordinat(string $jenis, int $id, mixed $lintang, mixed $bujur): array
    {
        $kelas = self::JENIS_TITIK[$jenis] ?? null;

        abort_if($kelas === null, 404);

        $aset = $kelas::query()->findOrFail($id);

        $this->authorize('update', $aset);

        if (! is_numeric($lintang) || ! is_numeric($bujur) || abs((float) $lintang) > 90 || abs((float) $bujur) > 180) {
            return ['ok' => false, 'pesan' => 'Koordinat tidak valid.'];
        }

        $aset->lintang = number_format((float) $lintang, 6, '.', '');
        $aset->bujur = number_format((float) $bujur, 6, '.', '');
        $aset->save();

        return ['ok' => true, 'pesan' => 'Koordinat disimpan.'];
    }

    /**
     * Menyimpan jalur kabel yang digambar atau disunting di peta.
     *
     * @param  mixed  $titik  Daftar [lintang, bujur] dari Leaflet.
     * @return array{ok: bool, pesan: string, keterangan?: string}
     */
    #[Renderless]
    public function simpanJalur(int $id, mixed $titik): array
    {
        $segmen = SegmenKabel::query()->findOrFail($id);

        $this->authorize('update', $segmen);

        if (! is_array($titik) || count($titik) < 2 || count($titik) > 2000) {
            return ['ok' => false, 'pesan' => 'Jalur membutuhkan 2 sampai 2000 titik.'];
        }

        $jalur = [];

        foreach ($titik as $t) {
            if (! is_array($t) || count($t) !== 2 || ! is_numeric($t[0] ?? null) || ! is_numeric($t[1] ?? null)
                || abs((float) $t[0]) > 90 || abs((float) $t[1]) > 180) {
                return ['ok' => false, 'pesan' => 'Jalur memuat koordinat yang tidak valid.'];
            }

            // Disimpan sebagai [bujur, lintang] (urutan GeoJSON).
            $jalur[] = [round((float) $t[1], 6), round((float) $t[0], 6)];
        }

        $segmen->jalur_peta = $jalur;
        $segmen->save();

        return [
            'ok' => true,
            'pesan' => 'Jalur disimpan. Panjang peta '.number_format((float) $segmen->panjang_peta_m, 0, ',', '.').' m.',
        ];
    }
}; ?>

@assets
    @vite(['resources/js/peta.js'])
@endassets

<section class="w-full" data-peta-akar>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Peta jaringan</flux:heading>
            <flux:subheading size="lg">OPD, tiang, titik jaringan, dan jalur kabel. Klik aset untuk melihat detailnya.</flux:subheading>
        </div>

        @can(\App\Enums\Izin::KelolaAset->value)
            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700">
                <input type="checkbox" data-peta-mode-ubah class="size-4" />
                <span>Mode ubah: geser aset dan sunting jalur</span>
            </label>
        @endcan
    </div>

    {{-- Isi blok ini diatur oleh resources/js/peta.js, bukan oleh Livewire. --}}
    <div class="grid gap-4 lg:grid-cols-[18rem_1fr]" wire:ignore>
        <aside class="space-y-5 text-sm">
            <div class="relative">
                <label for="peta-cari" class="mb-1 block font-medium">Cari kode aset</label>
                <input id="peta-cari" type="search" data-peta-cari autocomplete="off" placeholder="mis. TG-001 atau nama"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900" />
                <ul data-peta-hasil class="mt-1 hidden max-h-64 overflow-auto rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"></ul>
            </div>

            <div>
                <label for="peta-opd" class="mb-1 block font-medium">OPD</label>
                <select id="peta-opd" data-peta-opd class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900">
                    <option value="">Semua OPD</option>
                    @foreach ($this->pilihanOpd as $opd)
                        <option value="{{ $opd->id }}">{{ $opd->nama }}</option>
                    @endforeach
                </select>
            </div>

            <fieldset>
                <legend class="mb-1 font-medium">Jenis aset</legend>
                <div class="space-y-1" data-peta-lapisan>
                    @foreach ([
                        'opd' => 'OPD',
                        'perangkat' => 'Perangkat utama',
                        'tiang' => 'Tiang',
                        'odc' => 'ODC',
                        'odp' => 'ODP',
                        'closure' => 'Closure',
                        'otb' => 'Terminasi/OTB',
                        'backbone' => 'Jalur backbone',
                        'distribusi' => 'Jalur distribusi',
                        'kabel-lain' => 'Jalur belum diklasifikasi',
                    ] as $lapisan => $label)
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" value="{{ $lapisan }}" checked class="size-4" />
                            <span class="peta-legenda peta-legenda-{{ $lapisan }}" aria-hidden="true"></span>
                            <span>{{ $label }}</span>
                            <span class="ms-auto tabular-nums text-zinc-500" data-peta-jumlah="{{ $lapisan }}"></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            @can(\App\Enums\Izin::KelolaAset->value)
                <div data-peta-panel-gambar class="hidden rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <label for="peta-segmen" class="mb-1 block font-medium">Gambar jalur kabel</label>
                    <select id="peta-segmen" data-peta-segmen class="mb-2 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900"></select>
                    <button type="button" data-peta-gambar class="w-full rounded-lg bg-zinc-800 px-3 py-2 font-medium text-white dark:bg-white dark:text-zinc-900">Gambar jalur</button>
                    <p class="mt-2 text-xs text-zinc-500">Klik di peta untuk tiap titik jalur, klik titik terakhir untuk selesai. Jalur yang sudah ada disunting dengan menggeser titik-titiknya.</p>
                </div>
            @endcan

            <p data-peta-status class="text-xs text-zinc-500" role="status" aria-live="polite">Memuat data peta…</p>
            <p data-peta-tanpa-posisi class="hidden text-xs text-zinc-500"></p>
        </aside>

        <div>
            <div data-peta-kanvas class="h-[70vh] min-h-96 w-full rounded-lg border border-zinc-200 dark:border-zinc-700" aria-label="Peta jaringan"></div>
            <p class="mt-2 text-xs text-zinc-500">
                Peta tidak menampilkan status koneksi. Aset tanpa koordinat tidak digambar, tetapi tetap muncul di pencarian.
            </p>
        </div>
    </div>
</section>

@script
<script>
    const pasang = () => window.pasangPetaJaringan($wire.$el, @js($this->konfigurasi), $wire);

    window.pasangPetaJaringan ? pasang() : window.addEventListener('peta:siap', pasang, { once: true });
</script>
@endscript
