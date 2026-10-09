@props(['hasil', 'rinci' => true])

@php
    $aset = $hasil->aset;
    $sebutan = match (true) {
        $aset instanceof \App\Models\SegmenKabel => 'Segmen kabel',
        $aset instanceof \App\Models\TitikJaringan => $aset->jenis->label(),
        default => 'Tiang',
    };
@endphp

<div {{ $attributes->class('space-y-4') }} data-test="potensi-terdampak">
    <div>
        <flux:heading size="lg" data-test="label-dampak">{{ \App\Fo\AnalisisDampak::LABEL }}</flux:heading>
        <flux:text>
            {{ $sebutan }} <span class="font-medium">{{ $aset->kode }}</span>
            <x-lencana-simulasi :tampil="$aset->simulasi" class="ms-1" />
            · {{ count($hasil->opd) }} OPD · {{ $hasil->jumlahJalur() }} jalur tercatat · {{ count($hasil->segmen) }} kabel diperiksa
        </flux:text>
    </div>

    <flux:callout icon="information-circle">
        <flux:callout.text>
            {{ \App\Fo\AnalisisDampak::CATATAN }}
            Hasil ini bukan diagnosis: pemeriksaan monitoring yang gagal tidak menunjukkan bahwa kabel putus.
        </flux:callout.text>
    </flux:callout>

    @if ($hasil->opd === [])
        <flux:text data-test="tanpa-opd-terdampak">
            Tidak ada OPD yang tercatat memakai aset ini. Itu belum berarti tidak ada yang terdampak; periksa keterbatasan di bawah.
        </flux:text>
    @else
        <flux:table data-test="tabel-terdampak">
            <flux:table.columns>
                <flux:table.column>OPD</flux:table.column>
                <flux:table.column>Perangkat di ujung jalur</flux:table.column>
                <flux:table.column>Jalur</flux:table.column>
                <flux:table.column>Kelengkapan data</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($hasil->opd as $baris)
                    <flux:table.row :key="'terdampak-'.$baris['opd']->id">
                        <flux:table.cell variant="strong">
                            {{ $baris['opd']->nama }}
                            <x-lencana-simulasi :tampil="$baris['opd']->simulasi" class="ms-1" />
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-normal">
                            @foreach ($baris['perangkat'] as $perangkat)
                                <div>{{ $perangkat->kode_aset }} <span class="text-zinc-500">— {{ $perangkat->nama }}{{ $perangkat->switch_utama ? ' (switch utama)' : '' }}</span></div>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ count($baris['jalur']) }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($baris['lengkap'])
                                <flux:badge size="sm" color="green" icon="check-circle">Jalur lengkap</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber" icon="exclamation-triangle">Jalur belum lengkap</flux:badge>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    @if ($hasil->keterbatasan !== [])
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="keterbatasan-dampak">
            <flux:callout.heading>Keterbatasan data</flux:callout.heading>
            <flux:callout.text>
                <ul class="list-disc ps-5">
                    @foreach ($hasil->keterbatasan as $pesan)
                        <li>{{ $pesan }}</li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    <flux:text size="sm">Core yang belum dipakai pada kabel yang diperiksa: {{ $hasil->coreTidakTerpakai }}.</flux:text>

    @if ($rinci)
        @php
            $semua = collect($hasil->opd)->flatMap(fn (array $baris) => $baris['jalur'])->concat($hasil->jalurTanpaPerangkat)->unique(fn (\App\Fo\Jalur $j) => $j->kunci())->values();
        @endphp

        @if ($semua->isNotEmpty())
            <div class="space-y-3">
                <flux:heading>Rincian jalur ({{ $semua->count() }})</flux:heading>
                @foreach ($semua as $jalur)
                    <x-jalur-fo :jalur="$jalur" />
                @endforeach
            </div>
        @endif
    @endif
</div>
