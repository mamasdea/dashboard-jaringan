{{-- Isian App\Tiket\FormulirTiket. Butuh $this->daftarOpd dan $this->daftarPerangkat. --}}
<flux:input wire:model="form.judul" label="Judul" required maxlength="200" />

<div class="grid gap-4 sm:grid-cols-2">
    <flux:select wire:model.live="form.opd_id" label="OPD" placeholder="Tidak ditentukan">
        <flux:select.option value="">Tidak ditentukan</flux:select.option>
        @foreach ($this->daftarOpd as $opd)
            <flux:select.option :value="$opd->id">{{ $opd->nama }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select wire:model="form.perangkat_id" label="Perangkat" placeholder="Tidak ditentukan" description="Tiket dikaitkan dengan monitoring perangkat ini.">
        <flux:select.option value="">Tidak ditentukan</flux:select.option>
        @foreach ($this->daftarPerangkat as $perangkat)
            <flux:select.option :value="$perangkat->id">{{ $perangkat->kode_aset }} — {{ $perangkat->nama }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select wire:model="form.jenis_aset" label="Aset FO terkait" placeholder="Tidak ada">
        <flux:select.option value="">Tidak ada</flux:select.option>
        @foreach (\App\Tiket\FormulirTiket::ASET_FO as $nilai => $jenis)
            <flux:select.option :value="$nilai">{{ $jenis['label'] }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:input wire:model="form.kode_aset" label="Kode aset FO" maxlength="50" autocomplete="off" description="Dipakai untuk menghitung potensi terdampak." />

    <flux:input wire:model="form.waktu_mulai" type="datetime-local" label="Waktu mulai (WIB)" required />

    <flux:select wire:model="form.prioritas" label="Prioritas">
        @foreach (\App\Enums\PrioritasTiket::cases() as $p)
            <flux:select.option :value="$p->value">{{ $p->label() }}</flux:select.option>
        @endforeach
    </flux:select>
</div>

<flux:textarea wire:model="form.dugaan_penyebab" label="Dugaan penyebab" rows="2" maxlength="2000"
    description="Perkiraan awal yang belum diperiksa di lapangan. Penyebab terverifikasi diisi terpisah setelah pemeriksaan." />
