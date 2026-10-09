<?php

use App\Enums\AksiAktivitas;
use App\Enums\Izin;
use App\Enums\Peran;
use App\Models\LogAktivitas;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Log aktivitas')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $cari = '';

    #[Url]
    public string $aksi = '';

    public function boot(): void
    {
        $this->authorize(Izin::LihatLogAktivitas->value);
    }

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'aksi'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, LogAktivitas>
     */
    #[Computed]
    public function daftar(): LengthAwarePaginator
    {
        return LogAktivitas::query()
            ->when(AksiAktivitas::tryFrom($this->aksi), fn ($query, AksiAktivitas $aksi) => $query->where('aksi', $aksi))
            ->when($this->cari !== '', function ($query) {
                $kata = '%'.addcslashes($this->cari, '%_\\').'%';

                $query->where(fn ($q) => $q->whereLike('pelaku_nama', $kata)->orWhereLike('subjek_label', $kata));
            })
            ->orderByDesc('waktu')
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <div class="mb-6">
        <flux:heading size="xl" level="1">Log aktivitas</flux:heading>
        <flux:subheading size="lg">Catatan masuk, perubahan pengguna, dan akses yang ditolak. Tidak dapat diubah atau dihapus.</flux:subheading>
    </div>

    <div class="mb-4 flex flex-wrap gap-3">
        <div class="w-full max-w-sm">
            <flux:input wire:model.live.debounce.300ms="cari" icon="magnifying-glass" placeholder="Cari pelaku atau subjek" aria-label="Cari log" />
        </div>
        <div class="w-full max-w-xs">
            <flux:select wire:model.live="aksi" aria-label="Saring aksi">
                <flux:select.option value="">Semua aksi</flux:select.option>
                @foreach (\App\Enums\AksiAktivitas::cases() as $pilihan)
                    <flux:select.option :value="$pilihan->value">{{ $pilihan->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <flux:table :paginate="$this->daftar">
        <flux:table.columns>
            <flux:table.column>Waktu (WIB)</flux:table.column>
            <flux:table.column>Pelaku</flux:table.column>
            <flux:table.column>Aksi</flux:table.column>
            <flux:table.column>Subjek</flux:table.column>
            <flux:table.column>Rincian</flux:table.column>
            <flux:table.column>Alamat IP</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->daftar as $log)
                <flux:table.row :key="$log->id">
                    <flux:table.cell>{{ $log->waktu->timezone(config('app.display_timezone'))->format('d-m-Y H:i:s') }}</flux:table.cell>
                    <flux:table.cell variant="strong">
                        {{ $log->pelaku_nama ?? '—' }}
                        @if ($log->pelaku_peran)
                            <flux:text size="sm">{{ \App\Enums\Peran::tryFrom($log->pelaku_peran)?->label() ?? $log->pelaku_peran }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$log->aksi->warna()">{{ $log->aksi->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ $log->subjek_label ?? '—' }}</flux:table.cell>
                    <flux:table.cell class="max-w-md whitespace-normal">
                        @foreach ($log->rincian ?? [] as $kunci => $nilai)
                            <div class="text-xs">
                                <span class="font-medium">{{ $kunci }}:</span>
                                @if (is_array($nilai) && array_key_exists('dari', $nilai) && array_key_exists('menjadi', $nilai))
                                    {{ is_bool($nilai['dari']) ? ($nilai['dari'] ? 'ya' : 'tidak') : ($nilai['dari'] ?? '—') }} → {{ is_bool($nilai['menjadi']) ? ($nilai['menjadi'] ? 'ya' : 'tidak') : ($nilai['menjadi'] ?? '—') }}
                                @else
                                    {{ is_scalar($nilai) || $nilai === null ? $nilai : json_encode($nilai) }}
                                @endif
                            </div>
                        @endforeach
                    </flux:table.cell>
                    <flux:table.cell>{{ $log->alamat_ip ?? '—' }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">Belum ada aktivitas yang cocok.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
