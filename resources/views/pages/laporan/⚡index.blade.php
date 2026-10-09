<?php

use App\Enums\Izin;
use App\Laporan\DaftarLaporan;
use App\Laporan\DokumenLaporan;
use App\Laporan\Periode;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Laporan. Hanya membaca data tersimpan; tidak memicu pemeriksaan dan tidak
 * mengubah apa pun.
 */
new #[Title('Laporan')] class extends Component {
    #[Url]
    public string $jenis = 'ketersediaan';

    #[Url]
    public string $dari = '';

    #[Url]
    public string $sampai = '';

    public function boot(): void
    {
        $this->authorize(Izin::LihatLaporan->value);
    }

    public function mount(): void
    {
        if (! isset(DaftarLaporan::JENIS[$this->jenis])) {
            $this->jenis = 'ketersediaan';
        }

        try {
            $periode = Periode::dariIsian($this->dari, $this->sampai);
        } catch (ValidationException) {
            $periode = Periode::dariIsian(null, null);
        }

        $this->dari = $periode->dari;
        $this->sampai = $periode->sampai;
    }

    /**
     * @return array<string, array{label: string, berperiode: bool}>
     */
    #[Computed]
    public function daftarJenis(): array
    {
        return DaftarLaporan::JENIS;
    }

    #[Computed]
    public function berperiode(): bool
    {
        return DaftarLaporan::JENIS[$this->jenis]['berperiode'] ?? true;
    }

    /**
     * Null bila rentang tanggal tidak sah; galatnya ada di error bag.
     */
    #[Computed]
    public function dokumen(): ?DokumenLaporan
    {
        abort_unless(isset(DaftarLaporan::JENIS[$this->jenis]), 404);

        try {
            return app(DaftarLaporan::class)->buat($this->jenis, Periode::dariIsian($this->dari, $this->sampai));
        } catch (ValidationException $galat) {
            $this->setErrorBag($galat->validator->errors());

            return null;
        }
    }
}; ?>

<section class="w-full space-y-6" data-test="halaman-laporan">
    @php
        $dokumen = $this->dokumen;
        $tautan = fn (string $format): string => route('laporan.ekspor', ['jenis' => $jenis, 'format' => $format, 'dari' => $dari, 'sampai' => $sampai]);
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Laporan</flux:heading>
            <flux:subheading size="lg">Disusun dari data yang tersimpan. Definisi setiap angka tercantum di bawah tabel dan ikut diekspor.</flux:subheading>
        </div>

        @if ($dokumen)
            <div class="flex gap-2">
                <flux:button :href="$tautan('csv')" icon="arrow-down-tray" data-test="ekspor-csv">CSV</flux:button>
                <flux:button :href="$tautan('pdf')" icon="arrow-down-tray" data-test="ekspor-pdf">PDF</flux:button>
            </div>
        @endif
    </div>

    <div class="grid gap-3 sm:grid-cols-[1fr_11rem_11rem]">
        <flux:select wire:model.live="jenis" label="Laporan" data-test="jenis-laporan">
            @foreach ($this->daftarJenis as $nilai => $j)
                <flux:select.option :value="$nilai">{{ $j['label'] }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.500ms="dari" type="date" label="Dari" :disabled="! $this->berperiode" />
        <flux:input wire:model.live.debounce.500ms="sampai" type="date" label="Sampai" :disabled="! $this->berperiode" />
    </div>

    @if ($dokumen === null)
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="periode-tidak-sah">
            <flux:callout.text>Periksa rentang tanggal: awal tidak boleh setelah akhir, dan paling panjang {{ \App\Laporan\Periode::HARI_MAKSIMUM }} hari.</flux:callout.text>
        </flux:callout>
    @else
        <div>
            <flux:heading size="lg">{{ $dokumen->judul }}</flux:heading>
            <flux:text>
                {{ $dokumen->periode === null ? 'Keadaan saat laporan dibuat' : 'Periode '.$dokumen->periode }}
                · dibuat {{ \App\Laporan\DokumenLaporan::waktu($dokumen->dibuatPada) }} WIB
            </flux:text>
        </div>

        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-test="ringkasan-laporan">
            @foreach ($dokumen->ringkasan as $nama => $nilai)
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <dt class="text-sm text-zinc-500">{{ $nama }}</dt>
                    <dd class="text-lg font-semibold tabular-nums">{{ $nilai }}</dd>
                </div>
            @endforeach
        </dl>

        @foreach ($dokumen->bagian as $nomor => $bagian)
            <div wire:key="bagian-{{ $jenis }}-{{ $nomor }}">
                <flux:heading class="mb-3">{{ $bagian->judul }} ({{ count($bagian->baris) }})</flux:heading>
                @if ($bagian->baris === [])
                    <flux:text>{{ $bagian->kosong }}</flux:text>
                @else
                    <flux:table data-test="tabel-laporan">
                        <flux:table.columns>
                            @foreach ($bagian->kolom as $kolom)
                                <flux:table.column>{{ $kolom }}</flux:table.column>
                            @endforeach
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($bagian->baris as $i => $baris)
                                <flux:table.row :key="'baris-'.$nomor.'-'.$i">
                                    @foreach ($baris as $k => $sel)
                                        <flux:table.cell class="max-w-xs whitespace-normal {{ $k === 0 ? '' : 'tabular-nums' }}">{{ $sel }}</flux:table.cell>
                                    @endforeach
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </div>
        @endforeach

        <flux:callout icon="information-circle" data-test="definisi-laporan">
            <flux:callout.heading>Definisi dan keterbatasan</flux:callout.heading>
            <flux:callout.text>
                <ul class="list-disc ps-5">
                    @foreach ($dokumen->catatan as $catatan)
                        <li>{{ $catatan }}</li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif
</section>
