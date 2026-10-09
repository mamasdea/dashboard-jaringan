@php
    use App\Models\Opd;
    use App\Models\Perangkat;
    use App\Models\Tiang;
    use App\Support\TautanAset;

    $kode = TautanAset::kode($aset);

    // Kolom yang ditampilkan per jenis aset. Halaman ini hanya dapat dibuka
    // oleh pengguna yang lolos policy `view`.
    [$nama, $rincian, $daftar, $foto] = match (true) {
        $aset instanceof Opd => [
            $aset->nama,
            [
                'Alamat' => $aset->alamat,
                'Koordinat' => $aset->lintang !== null ? $aset->lintang.', '.$aset->bujur : null,
                'Kontak teknis' => collect([$aset->kontak_teknis_nama, $aset->kontak_teknis_telepon, $aset->kontak_teknis_email])->filter()->implode(' · ') ?: null,
                'Status' => $aset->aktif ? 'Aktif' : 'Nonaktif',
                'Jumlah perangkat' => (string) $aset->perangkat()->count(),
                'Switch utama' => $aset->switchUtama?->kode_aset,
            ],
            route('opd.index', ['q' => $kode]),
            null,
        ],
        $aset instanceof Perangkat => [
            $aset->nama,
            [
                'OPD pemilik' => $aset->opd->kode.' — '.$aset->opd->nama,
                'Penempatan' => $aset->penempatan->label().($aset->lokasi ? ' · '.$aset->lokasi : ''),
                'Jenis' => $aset->jenis->label(),
                'Merek / model' => collect([$aset->merek, $aset->model])->filter()->implode(' ') ?: null,
                'Nomor seri' => $aset->nomor_seri,
                'IP manajemen' => $aset->ip_manajemen,
                'Port uplink' => $aset->port_uplink,
                'Monitoring' => $aset->monitoring_aktif ? 'Diaktifkan' : 'Tidak diaktifkan',
                'Switch utama' => $aset->switch_utama ? 'Ya' : 'Tidak',
                'Catatan' => $aset->catatan,
            ],
            route('perangkat.index', ['q' => $kode]),
            $aset->foto_path !== null ? route('perangkat.foto', $aset) : null,
        ],
        $aset instanceof Tiang => [
            null,
            [
                'Koordinat' => $aset->lintang !== null ? $aset->lintang.', '.$aset->bujur : null,
                'Pemilik' => $aset->pemilik?->label(),
                'Kondisi' => $aset->kondisi?->label(),
                'Kabel yang lewat' => $aset->segmen()->orderBy('kode')->pluck('kode')->implode(', ') ?: null,
                'Titik jaringan terpasang' => $aset->titikJaringan()->orderBy('kode')->pluck('kode')->implode(', ') ?: null,
                'Catatan' => $aset->catatan,
            ],
            route('fo.tiang', ['q' => $kode]),
            $aset->foto_path !== null ? route('fo.tiang.foto', $aset) : null,
        ],
    };
@endphp

<x-layouts::app :title="$kode">
    <section class="w-full space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:text size="sm" class="uppercase tracking-wide">{{ TautanAset::labelJenis($jenis) }}</flux:text>
                <flux:heading size="xl" level="1">
                    {{ $kode }}
                    <x-lencana-simulasi :tampil="$aset->simulasi" class="ms-1" />
                </flux:heading>
                @if ($nama)
                    <flux:subheading size="lg">{{ $nama }}</flux:subheading>
                @endif
                @if ($aset instanceof Tiang)
                    <div class="mt-1"><x-status-data :objek="$aset" /></div>
                @endif
            </div>

            <flux:button :href="$daftar" icon="arrow-left" wire:navigate>Buka di daftar</flux:button>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <dl class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm" data-test="rincian-aset">
                @foreach ($rincian as $label => $nilai)
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                    <dd class="col-span-2 whitespace-pre-line break-words">{{ $nilai ?? '—' }}</dd>
                @endforeach
            </dl>

            <div class="space-y-4">
                @if ($foto)
                    <img src="{{ $foto }}" alt="Foto {{ $kode }}" class="max-h-80 w-full rounded-lg border border-zinc-200 object-contain dark:border-zinc-700" />
                @endif

                <x-kartu-qr :aset="$aset" />
            </div>
        </div>
    </section>
</x-layouts::app>
