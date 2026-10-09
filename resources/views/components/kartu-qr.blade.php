@props(['aset'])

@php
    use App\Support\KodeQr;
    use App\Support\TautanAset;

    $jenis = TautanAset::jenisDari($aset);
    $kode = TautanAset::kode($aset);
    $isi = TautanAset::url($aset);
@endphp

{{-- QR hanya memuat alamat halaman detail; pemindai tetap harus masuk dan berhak melihatnya. --}}
<div {{ $attributes->class('flex flex-wrap items-center gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700') }} data-test="kartu-qr">
    <div class="rounded bg-white p-1" data-qr-isi="{{ $isi }}" role="img" aria-label="Kode QR halaman detail {{ $kode }}">
        {!! KodeQr::svg($isi, 132) !!}
    </div>

    <div class="min-w-0 flex-1 space-y-2 text-sm">
        <flux:heading>QR aset {{ $kode }}</flux:heading>
        <flux:text size="sm">
            Membuka halaman detail ini. QR hanya berisi alamat di bawah; yang memindai tetap harus masuk dan memiliki hak akses.
        </flux:text>
        <flux:text size="sm" class="break-all font-mono">{{ $isi }}</flux:text>

        <div class="flex flex-wrap gap-2">
            <flux:button size="sm" icon="printer" :href="route('aset.label', ['jenis' => $jenis, 'kode' => $kode])" target="_blank">Cetak label</flux:button>
            <flux:button size="sm" icon="map" :href="TautanAset::urlPeta($aset)" wire:navigate>Lihat di peta</flux:button>
        </div>
    </div>
</div>
