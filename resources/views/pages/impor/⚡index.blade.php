<?php

use App\Enums\Izin;
use App\Impor\PengimporCsv;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Impor CSV')] class extends Component {
    use WithFileUploads;

    private const int BATAS_GALAT_TAMPIL = 500;

    private const int BATAS_CONTOH_TAMPIL = 20;

    #[Locked]
    public string $jenis = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $berkas = null;

    /**
     * Ringkasan pratinjau atau impor terakhir.
     *
     * @var array{galat_berkas: list<string>, jumlah: array{total: int, baru: int, perbarui: int, galat: int}, galat: list<array<string, mixed>>, contoh: list<array<string, mixed>>}|null
     */
    #[Locked]
    public ?array $hasil = null;

    #[Locked]
    public bool $selesai = false;

    /**
     * Dijalankan pada setiap request ke komponen ini, termasuk pemanggilan
     * aksi dan unggahan secara langsung.
     */
    public function boot(): void
    {
        $this->authorize(Izin::KelolaAset->value);
    }

    public function mount(string $jenis): void
    {
        abort_unless(in_array($jenis, PengimporCsv::JENIS, true), 404);

        $this->jenis = $jenis;
    }

    #[Computed]
    public function pengimpor(): PengimporCsv
    {
        return PengimporCsv::untuk($this->jenis);
    }

    public function updatedBerkas(): void
    {
        $this->reset('hasil', 'selesai');
        $this->resetValidation();
    }

    public function pratinjau(): void
    {
        $this->authorize('create', $this->pengimpor->kelasModel());
        $this->validasiBerkas();

        $this->selesai = false;
        $this->hasil = $this->ringkas($this->pengimpor->pratinjau($this->berkas->getRealPath()));
    }

    public function impor(): void
    {
        $this->authorize('create', $this->pengimpor->kelasModel());
        $this->validasiBerkas();

        $this->hasil = $this->ringkas($this->pengimpor->impor($this->berkas->getRealPath()));
        $this->selesai = true;
        $this->berkas = null;
    }

    public function ulangi(): void
    {
        $this->reset('berkas', 'hasil', 'selesai');
        $this->resetValidation();
    }

    public function unduhTemplat(): StreamedResponse
    {
        $isi = $this->pengimpor->templat();

        return response()->streamDownload(fn () => print($isi), "templat-{$this->jenis}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function unduhGalat(): StreamedResponse
    {
        $galat = $this->hasil['galat'] ?? [];

        return response()->streamDownload(function () use ($galat): void {
            $keluaran = fopen('php://output', 'w');

            if ($keluaran === false) {
                return;
            }

            fwrite($keluaran, "\xEF\xBB\xBF");
            fputcsv($keluaran, ['baris', 'kunci', 'kesalahan'], ',', '"', '');

            foreach ($galat as $baris) {
                fputcsv($keluaran, [$baris['nomor'], $baris['kunci'], implode(' | ', $baris['galat'])], ',', '"', '');
            }

            fclose($keluaran);
        }, "kesalahan-impor-{$this->jenis}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function validasiBerkas(): void
    {
        $this->validate([
            'berkas' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
        ]);
    }

    /**
     * Hanya baris bermasalah dan sedikit contoh baris valid yang disimpan di
     * state komponen, supaya berkas besar tidak membebani setiap request.
     *
     * @param  array{galat_berkas: list<string>, jumlah: array{total: int, baru: int, perbarui: int, galat: int}, baris: list<array<string, mixed>>}  $hasil
     * @return array{galat_berkas: list<string>, jumlah: array{total: int, baru: int, perbarui: int, galat: int}, galat: list<array<string, mixed>>, contoh: list<array<string, mixed>>}
     */
    private function ringkas(array $hasil): array
    {
        $baris = collect($hasil['baris']);

        return [
            'galat_berkas' => $hasil['galat_berkas'],
            'jumlah' => $hasil['jumlah'],
            'galat' => $baris->where('tindakan', 'galat')->take(self::BATAS_GALAT_TAMPIL)->values()->all(),
            'contoh' => $baris->where('tindakan', '!=', 'galat')->take(self::BATAS_CONTOH_TAMPIL)->values()->all(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Impor {{ $this->pengimpor->judul() }} dari CSV</flux:heading>
            <flux:subheading size="lg">Berkas diperiksa dahulu; tidak ada yang disimpan sebelum Anda menekan tombol impor.</flux:subheading>
        </div>

        <flux:button :href="route($jenis.'.index')" icon="arrow-left" wire:navigate>Kembali ke daftar</flux:button>
    </div>

    @if ($selesai && $hasil)
        <flux:callout variant="success" icon="check-circle" class="mb-6" data-test="impor-selesai">
            <flux:callout.heading>Impor selesai</flux:callout.heading>
            <flux:callout.text>
                {{ $hasil['jumlah']['baru'] }} baris ditambahkan, {{ $hasil['jumlah']['perbarui'] }} diperbarui,
                {{ $hasil['jumlah']['galat'] }} dilewati karena bermasalah.
            </flux:callout.text>
        </flux:callout>
    @endif

    @unless ($selesai)
        <div class="mb-6 grid gap-6 lg:grid-cols-2">
            <form wire:submit="pratinjau" class="space-y-4">
                <flux:heading>1. Pilih berkas</flux:heading>

                <flux:field>
                    <flux:input type="file" wire:model="berkas" accept=".csv,text/csv" aria-label="Berkas CSV" />
                    <flux:description>
                        CSV berpemisah koma atau titik koma, paling besar 1 MB dan {{ \App\Impor\PembacaCsv::BATAS_BARIS }} baris.
                        Baris pertama berisi nama kolom.
                    </flux:description>
                    <flux:error name="berkas" />
                </flux:field>

                <div class="flex flex-wrap gap-2">
                    <flux:button type="submit" variant="primary" icon="magnifying-glass" data-test="periksa-berkas">Periksa berkas</flux:button>
                    <flux:button wire:click="unduhTemplat" icon="arrow-down-tray">Unduh templat</flux:button>
                </div>
            </form>

            <div>
                <flux:heading class="mb-2">Kolom</flux:heading>
                <div class="max-h-72 overflow-auto rounded-lg border border-zinc-200 text-sm dark:border-zinc-700">
                    <table class="w-full">
                        <tbody>
                            @foreach ($this->pengimpor->kolom() as $nama => $kolom)
                                <tr class="border-b border-zinc-200 last:border-0 dark:border-zinc-700">
                                    <td class="px-3 py-2 align-top font-mono text-xs">
                                        {{ $nama }}
                                        @if ($kolom['wajib'])
                                            <flux:badge size="sm" color="amber" class="ms-1">wajib</flux:badge>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">{{ $kolom['keterangan'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endunless

    @if ($hasil)
        @if ($hasil['galat_berkas'] !== [])
            <flux:callout variant="danger" icon="x-circle" class="mb-6" data-test="galat-berkas">
                <flux:callout.heading>Berkas tidak dapat diproses sepenuhnya</flux:callout.heading>
                <flux:callout.text>
                    @foreach ($hasil['galat_berkas'] as $pesan)
                        <div>{{ $pesan }}</div>
                    @endforeach
                </flux:callout.text>
            </flux:callout>
        @endif

        @if ($hasil['jumlah']['total'] > 0)
            <flux:heading class="mb-3">{{ $selesai ? 'Hasil' : '2. Pratinjau' }}</flux:heading>

            <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4" data-test="ringkasan-impor">
                @foreach ([
                    'Baris dibaca' => $hasil['jumlah']['total'],
                    $selesai ? 'Ditambahkan' : 'Akan ditambahkan' => $hasil['jumlah']['baru'],
                    $selesai ? 'Diperbarui' : 'Akan diperbarui' => $hasil['jumlah']['perbarui'],
                    $selesai ? 'Dilewati (bermasalah)' : 'Bermasalah' => $hasil['jumlah']['galat'],
                ] as $label => $nilai)
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:text size="sm">{{ $label }}</flux:text>
                        <div class="text-2xl font-semibold tabular-nums">{{ $nilai }}</div>
                    </div>
                @endforeach
            </div>

            @if ($hasil['galat'] !== [])
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <flux:heading>Kesalahan per baris</flux:heading>
                    <flux:button size="sm" wire:click="unduhGalat" icon="arrow-down-tray">Unduh laporan kesalahan</flux:button>
                </div>

                @if ($hasil['jumlah']['galat'] > count($hasil['galat']))
                    <flux:text size="sm" class="mb-2">Menampilkan {{ count($hasil['galat']) }} dari {{ $hasil['jumlah']['galat'] }} baris bermasalah.</flux:text>
                @endif

                <flux:table class="mb-6" data-test="tabel-galat">
                    <flux:table.columns>
                        <flux:table.column>Baris</flux:table.column>
                        <flux:table.column>Kunci</flux:table.column>
                        <flux:table.column>Kesalahan</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($hasil['galat'] as $baris)
                            <flux:table.row :key="'galat-'.$baris['nomor']">
                                <flux:table.cell variant="strong">{{ $baris['nomor'] }}</flux:table.cell>
                                <flux:table.cell>{{ $baris['kunci'] ?? '—' }}</flux:table.cell>
                                <flux:table.cell class="whitespace-normal">
                                    @foreach ($baris['galat'] as $pesan)
                                        <div>{{ $pesan }}</div>
                                    @endforeach
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif

            @if ($hasil['contoh'] !== [])
                <flux:heading class="mb-2">Baris valid{{ $hasil['jumlah']['baru'] + $hasil['jumlah']['perbarui'] > count($hasil['contoh']) ? ' ('.count($hasil['contoh']).' pertama)' : '' }}</flux:heading>

                <flux:table class="mb-6">
                    <flux:table.columns>
                        <flux:table.column>Baris</flux:table.column>
                        <flux:table.column>Kunci</flux:table.column>
                        <flux:table.column>Nama</flux:table.column>
                        <flux:table.column>Tindakan</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($hasil['contoh'] as $baris)
                            <flux:table.row :key="'contoh-'.$baris['nomor']">
                                <flux:table.cell>{{ $baris['nomor'] }}</flux:table.cell>
                                <flux:table.cell variant="strong">{{ $baris['kunci'] }}</flux:table.cell>
                                <flux:table.cell class="whitespace-normal">{{ $baris['nama'] }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$baris['tindakan'] === 'baru' ? 'green' : 'sky'">
                                        {{ $baris['tindakan'] === 'baru' ? 'Baru' : 'Perbarui' }}
                                    </flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        @endif

        @if ($selesai)
            <flux:button wire:click="ulangi" icon="arrow-up-tray">Impor berkas lain</flux:button>
        @elseif ($hasil['jumlah']['baru'] + $hasil['jumlah']['perbarui'] > 0)
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="primary" wire:click="impor" wire:loading.attr="disabled" data-test="jalankan-impor">
                    Impor {{ $hasil['jumlah']['baru'] + $hasil['jumlah']['perbarui'] }} baris valid
                </flux:button>
                @if ($hasil['jumlah']['galat'] > 0)
                    <flux:text size="sm">{{ $hasil['jumlah']['galat'] }} baris bermasalah akan dilewati. Perbaiki berkas dan periksa ulang bila ingin mengimpor semuanya.</flux:text>
                @endif
            </div>
        @elseif ($hasil['jumlah']['total'] > 0)
            <flux:text>Tidak ada baris valid untuk diimpor. Perbaiki berkas lalu periksa ulang.</flux:text>
        @endif
    @endif
</section>
