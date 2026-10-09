@props(['objek'])

{{-- Lencana kelengkapan pendataan beserta daftar yang masih kurang. --}}
@if ($objek->status_data === \App\Enums\StatusData::Lengkap)
    <flux:badge size="sm" color="green">Lengkap</flux:badge>
@else
    <flux:badge size="sm" color="amber" data-test="perlu-dilengkapi">Perlu dilengkapi</flux:badge>
    <flux:text size="sm" class="whitespace-normal">Belum ada: {{ implode(', ', $objek->kekurangan()) }}</flux:text>
@endif
