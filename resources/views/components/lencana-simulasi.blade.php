@props(['tampil' => true])

@if ($tampil)
    <flux:badge size="sm" color="fuchsia" {{ $attributes }} data-test="lencana-simulasi">SIMULASI</flux:badge>
@endif
