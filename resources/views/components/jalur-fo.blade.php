@props(['jalur'])

@php
    $ikon = [
        \App\Fo\Jalur::PERANGKAT => 'server-stack',
        \App\Fo\Jalur::TITIK => 'share',
        \App\Fo\Jalur::PORT => 'stop',
        \App\Fo\Jalur::KABEL => 'signal',
        \App\Fo\Jalur::SAMBUNGAN => 'link',
    ];
    $sebutan = [
        \App\Fo\Jalur::PERANGKAT => 'Perangkat',
        \App\Fo\Jalur::TITIK => 'Titik',
        \App\Fo\Jalur::PORT => 'Port terminasi',
        \App\Fo\Jalur::KABEL => 'Kabel',
        \App\Fo\Jalur::SAMBUNGAN => 'Sambungan',
    ];
@endphp

{{-- Jalur menurut inventaris. Tidak menyatakan apa pun tentang keadaan serat. --}}
<div {{ $attributes->class('rounded-lg border border-zinc-200 p-4 dark:border-zinc-700') }} data-test="jalur-fo" data-lengkap="{{ $jalur->lengkap() ? 'ya' : 'tidak' }}">
    <div class="mb-3 flex flex-wrap items-center gap-2">
        @if ($jalur->lengkap())
            <flux:badge size="sm" color="green" icon="check-circle">Tercatat dari ujung ke ujung</flux:badge>
        @else
            <flux:badge size="sm" color="amber" icon="exclamation-triangle">Jalur belum lengkap</flux:badge>
        @endif
        <flux:text size="sm">{{ count($jalur->segmenIds) }} kabel · {{ count($jalur->titikIds) }} titik</flux:text>
    </div>

    <ol class="space-y-1.5">
        @foreach ($jalur->langkah as $langkah)
            <li class="flex items-start gap-2 text-sm">
                <flux:icon :name="$ikon[$langkah['jenis']]" variant="micro" class="mt-0.5 shrink-0 text-zinc-400" />
                <span>
                    <span class="text-zinc-500">{{ $sebutan[$langkah['jenis']] }}:</span>
                    <span class="font-medium">{{ $langkah['label'] }}</span>
                    @if ($langkah['rinci'])
                        <span class="text-zinc-500">— {{ $langkah['rinci'] }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ol>

    @if ($jalur->keterbatasan !== [])
        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-3" data-test="keterbatasan-jalur">
            <flux:callout.heading>Penelusuran berhenti karena data inventaris belum lengkap</flux:callout.heading>
            <flux:callout.text>
                <ul class="list-disc ps-5">
                    @foreach ($jalur->keterbatasan as $pesan)
                        <li>{{ $pesan }}</li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif
</div>
