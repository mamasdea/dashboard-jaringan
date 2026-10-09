<?php

use App\Enums\Izin;
use App\Fo\AnalisisDampak;
use App\Fo\HasilDampak;
use App\Fo\Jalur;
use App\Fo\PenelusuranJalur;
use App\Models\Perangkat;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Penelusuran jalur FO dan potensi terdampak. Hanya membaca inventaris; tidak
 * memicu pemeriksaan dan tidak memakai hasil monitoring.
 */
new #[Title('Penelusuran jalur FO')] class extends Component {
    public const array JENIS = [
        'perangkat' => ['label' => 'Perangkat', 'model' => Perangkat::class, 'kolom' => 'kode_aset'],
        'kabel' => ['label' => 'Segmen kabel', 'model' => SegmenKabel::class, 'kolom' => 'kode'],
        'titik' => ['label' => 'Titik jaringan', 'model' => TitikJaringan::class, 'kolom' => 'kode'],
        'tiang' => ['label' => 'Tiang', 'model' => Tiang::class, 'kolom' => 'kode'],
    ];

    #[Url]
    public string $jenis = 'perangkat';

    #[Url]
    public string $kode = '';

    public function boot(): void
    {
        $this->authorize(Izin::LihatAset->value);
    }

    public function updatedJenis(): void
    {
        $this->kode = '';
    }

    /**
     * @return array{label: string, model: class-string<Perangkat|SegmenKabel|TitikJaringan|Tiang>, kolom: string}
     */
    private function jenisDipilih(): array
    {
        return self::JENIS[$this->jenis] ?? self::JENIS['perangkat'];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function daftarJenis(): array
    {
        return array_map(fn (array $jenis) => $jenis['label'], self::JENIS);
    }

    /**
     * Kode yang ditawarkan saat mengetik.
     *
     * @return list<string>
     */
    #[Computed]
    public function pilihan(): array
    {
        $jenis = $this->jenisDipilih();

        return $jenis['model']::query()->orderBy($jenis['kolom'])->limit(500)->pluck($jenis['kolom'])->all();
    }

    #[Computed]
    public function aset(): Perangkat|SegmenKabel|TitikJaringan|Tiang|null
    {
        $kode = trim($this->kode);

        if ($kode === '') {
            return null;
        }

        $jenis = $this->jenisDipilih();

        return $jenis['model']::query()->whereRaw("upper({$jenis['kolom']}) = ?", [mb_strtoupper($kode)])->first();
    }

    /**
     * @return array{jalur: list<Jalur>, keterbatasan: list<string>}|null
     */
    #[Computed]
    public function jalur(): ?array
    {
        return $this->aset instanceof Perangkat ? app(PenelusuranJalur::class)->dariPerangkat($this->aset) : null;
    }

    #[Computed]
    public function dampak(): ?HasilDampak
    {
        return $this->aset === null || $this->aset instanceof Perangkat ? null : app(AnalisisDampak::class)->untuk($this->aset);
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">Penelusuran jalur FO</flux:heading>
        <flux:subheading size="lg">
            Pilih perangkat untuk melihat jalur seratnya, atau pilih kabel, titik jaringan, atau tiang untuk melihat OPD yang berpotensi terdampak.
        </flux:subheading>
    </div>

    <div class="grid gap-4 sm:grid-cols-[14rem_1fr]">
        <flux:select wire:model.live="jenis" label="Jenis" data-test="jenis-penelusuran">
            @foreach ($this->daftarJenis as $nilai => $label)
                <flux:select.option :value="$nilai">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model.live.debounce.400ms="kode" label="Kode aset" list="pilihan-kode" autocomplete="off" placeholder="Ketik atau pilih kode" data-test="kode-penelusuran" />
        <datalist id="pilihan-kode">
            @foreach ($this->pilihan as $pilihan)
                <option value="{{ $pilihan }}"></option>
            @endforeach
        </datalist>
    </div>

    @if (trim($kode) === '')
        <flux:text>Belum ada aset yang dipilih.</flux:text>
    @elseif ($this->aset === null)
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="aset-tidak-ditemukan">
            <flux:callout.text>{{ $this->daftarJenis[$jenis] ?? 'Aset' }} berkode "{{ trim($kode) }}" tidak ditemukan.</flux:callout.text>
        </flux:callout>
    @elseif ($this->jalur !== null)
        <div class="space-y-4" data-test="hasil-jalur">
            <div>
                <flux:heading size="lg">
                    Jalur FO {{ $this->aset->kode_aset }}
                    <x-lencana-simulasi :tampil="$this->aset->simulasi" class="ms-1" />
                </flux:heading>
                <flux:text>{{ $this->aset->nama }} · {{ $this->aset->opd->nama }}</flux:text>
            </div>

            <flux:callout icon="information-circle">
                <flux:callout.text>
                    Jalur ini disusun dari hubungan FO yang tercatat di inventaris. Jalur yang tercatat lengkap tidak berarti seratnya berfungsi,
                    dan pemeriksaan monitoring yang gagal tidak menunjukkan di bagian mana gangguannya.
                </flux:callout.text>
            </flux:callout>

            @foreach ($this->jalur['keterbatasan'] as $pesan)
                <flux:callout variant="warning" icon="exclamation-triangle" data-test="keterbatasan-jalur">
                    <flux:callout.heading>Jalur tidak dapat ditelusuri</flux:callout.heading>
                    <flux:callout.text>{{ $pesan }}</flux:callout.text>
                </flux:callout>
            @endforeach

            @foreach ($this->jalur['jalur'] as $jalur)
                <x-jalur-fo :jalur="$jalur" />
            @endforeach
        </div>
    @elseif ($this->dampak !== null)
        <x-potensi-terdampak :hasil="$this->dampak" />
    @endif
</section>
