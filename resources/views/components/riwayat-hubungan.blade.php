@props(['riwayat'])

@if ($riwayat->isEmpty())
    <flux:text>Belum ada perubahan yang tercatat.</flux:text>
@else
    <flux:table data-test="riwayat-hubungan">
        <flux:table.columns>
            <flux:table.column>Waktu (WIB)</flux:table.column>
            <flux:table.column>Pelaku</flux:table.column>
            <flux:table.column>Jenis</flux:table.column>
            <flux:table.column>Aksi</flux:table.column>
            <flux:table.column>Perubahan</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($riwayat as $baris)
                <flux:table.row :key="$baris->id">
                    <flux:table.cell>{{ $baris->waktu->timezone(config('app.display_timezone'))->format('d-m-Y H:i') }}</flux:table.cell>
                    <flux:table.cell>{{ $baris->pelaku_nama ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $baris->jenis->label() }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="['dibuat' => 'green', 'diubah' => 'sky', 'dihapus' => 'amber'][$baris->aksi->value]">{{ $baris->aksi->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-normal">{{ $baris->ringkasan }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
@endif
