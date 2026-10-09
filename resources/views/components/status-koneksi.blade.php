@props(['status', 'ukuran' => 'sm'])

{{-- Status selalu tampil sebagai ikon dan teks; warna hanya penguat. --}}
<flux:badge :size="$ukuran" :color="$status->warna()" :icon="$status->ikon()" {{ $attributes }} data-status="{{ $status->value }}">
    {{ $status->label() }}
</flux:badge>
