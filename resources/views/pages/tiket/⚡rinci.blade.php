<?php

use App\Enums\Izin;
use App\Enums\JenisCatatanTiket;
use App\Enums\StatusMonitoring;
use App\Enums\StatusTiket;
use App\Fo\AnalisisDampak;
use App\Fo\HasilDampak;
use App\Fo\Jalur;
use App\Fo\PenelusuranJalur;
use App\Models\CatatanTiket;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\Tiket;
use App\Models\User;
use App\Tiket\FormulirTiket;
use App\Tiket\LayananTiket;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Rincian satu tiket. Status monitoring yang tampil dibaca dari data
 * tersimpan; halaman ini tidak memicu pemeriksaan.
 */
new #[Title('Tiket gangguan')] class extends Component {
    use WithFileUploads;

    #[Locked]
    public int $tiketId;

    public FormulirTiket $form;

    public string $teknisi_id = '';

    public string $waktu_pemulihan = '';

    public string $dugaan_penyebab = '';

    public string $penyebab_terverifikasi = '';

    public string $jenis_catatan = 'tindakan';

    public string $isi_catatan = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $foto = null;

    /**
     * Dijalankan pada setiap request: pelaku harus tetap berhak melihat tiket
     * ini, misalnya setelah penugasannya dipindahkan.
     */
    public function boot(): void
    {
        $this->authorize('viewAny', Tiket::class);
    }

    public function mount(Tiket $tiket): void
    {
        $this->authorize('view', $tiket);

        $this->tiketId = $tiket->id;
        $this->muatIsian($tiket);
    }

    public function hydrate(): void
    {
        $this->authorize('view', $this->tiket);
    }

    #[Computed]
    public function tiket(): Tiket
    {
        return Tiket::query()
            ->with(['opd', 'perangkat.opd', 'segmenKabel', 'titikJaringan', 'tiang', 'insiden', 'teknisi', 'endpoint.status'])
            ->findOrFail($this->tiketId);
    }

    /**
     * @return Collection<int, CatatanTiket>
     */
    #[Computed]
    public function linimasa(): Collection
    {
        return $this->tiket->catatan()->get();
    }

    /**
     * Status monitoring terkini endpoint terkait, dari data tersimpan.
     */
    #[Computed]
    public function statusMonitoring(): ?StatusMonitoring
    {
        $status = $this->tiket->endpoint?->status;

        return $status?->setRelation('endpoint', $this->tiket->endpoint)->statusTampil();
    }

    /**
     * @return array{jalur: list<Jalur>, keterbatasan: list<string>}|null
     */
    #[Computed]
    public function jalur(): ?array
    {
        return $this->tiket->perangkat === null || ! Auth::user()->boleh(Izin::LihatAset)
            ? null
            : app(PenelusuranJalur::class)->dariPerangkat($this->tiket->perangkat);
    }

    #[Computed]
    public function dampak(): ?HasilDampak
    {
        $aset = $this->tiket->asetFo();

        return $aset === null || ! Auth::user()->boleh(Izin::LihatAset) ? null : app(AnalisisDampak::class)->untuk($aset);
    }

    /**
     * @return Collection<int, Opd>
     */
    #[Computed]
    public function daftarOpd(): Collection
    {
        return Opd::query()->orderBy('nama')->get(['id', 'nama', 'kode']);
    }

    /**
     * @return Collection<int, Perangkat>
     */
    #[Computed]
    public function daftarPerangkat(): Collection
    {
        return Perangkat::query()
            ->when($this->form->opd_id !== '', fn ($query) => $query->where('opd_id', (int) $this->form->opd_id))
            ->orderBy('kode_aset')->limit(500)->get(['id', 'kode_aset', 'nama']);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function daftarTeknisi(): Collection
    {
        return User::query()->orderBy('name')->get()->filter(fn (User $u) => $u->boleh(Izin::TanganiTiket))->values();
    }

    public function ubah(): void
    {
        $this->authorize('update', $this->tiket);

        $this->form->isi($this->tiket);

        Flux::modal('formulir-tiket')->show();
    }

    public function simpan(LayananTiket $layanan): void
    {
        $this->authorize('update', $this->tiket);

        $layanan->ubah($this->tiket, $this->form->data());

        $this->segarkan();
        Flux::modal('formulir-tiket')->close();
    }

    public function tugaskan(LayananTiket $layanan): void
    {
        $this->authorize('update', $this->tiket);

        $layanan->tugaskan(
            $this->tiket,
            $this->teknisi_id === '' ? null : User::query()->findOrFail((int) $this->teknisi_id),
            Auth::user(),
        );

        $this->segarkan();
    }

    /**
     * Mulai mengerjakan atau menunda adalah pekerjaan penangan; membatalkan
     * dan membuka kembali adalah keputusan pengelola.
     */
    public function ubahStatus(string $status, LayananTiket $layanan): void
    {
        $tujuan = StatusTiket::tryFrom($status);

        $this->authorize(in_array($tujuan, [StatusTiket::Dikerjakan, StatusTiket::Menunggu], true) ? 'tangani' : 'update', $this->tiket);

        abort_if($tujuan === null || $tujuan === StatusTiket::Selesai, 422);

        $layanan->ubahStatus($this->tiket, $tujuan, Auth::user());

        $this->segarkan();
    }

    /**
     * Menutup pekerjaan. Waktu pemulihan diisi orang yang menangani; waktu
     * pulih menurut monitoring hanya ditawarkan sebagai acuan.
     */
    public function selesaikan(LayananTiket $layanan): void
    {
        $this->authorize('tangani', $this->tiket);

        $this->validate(
            ['waktu_pemulihan' => ['required', 'date_format:Y-m-d\TH:i']],
            attributes: ['waktu_pemulihan' => 'waktu pemulihan'],
        );

        $layanan->ubahStatus($this->tiket, StatusTiket::Selesai, Auth::user(), FormulirTiket::dariIsian($this->waktu_pemulihan));

        $this->segarkan();
        Flux::modal('selesaikan-tiket')->close();
    }

    public function simpanPenyebab(LayananTiket $layanan): void
    {
        $this->authorize('tangani', $this->tiket);

        $this->validate([
            'dugaan_penyebab' => ['nullable', 'string', 'max:2000'],
            'penyebab_terverifikasi' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['dugaan_penyebab' => 'dugaan penyebab', 'penyebab_terverifikasi' => 'penyebab terverifikasi']);

        $layanan->isiPenyebab(
            $this->tiket,
            trim($this->dugaan_penyebab) === '' ? null : trim($this->dugaan_penyebab),
            trim($this->penyebab_terverifikasi) === '' ? null : trim($this->penyebab_terverifikasi),
            Auth::user(),
        );

        $this->segarkan();
    }

    public function tambahCatatan(LayananTiket $layanan): void
    {
        $this->authorize('tangani', $this->tiket);

        $this->validate([
            'jenis_catatan' => ['required', 'in:tindakan,catatan'],
            'isi_catatan' => ['required', 'string', 'max:4000'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], attributes: ['jenis_catatan' => 'jenis', 'isi_catatan' => 'isi', 'foto' => 'foto']);

        $layanan->catat(
            $this->tiket,
            JenisCatatanTiket::from($this->jenis_catatan),
            trim($this->isi_catatan),
            Auth::user(),
            $this->foto?->store(Tiket::FOLDER_FOTO, Tiket::DISK_FOTO),
        );

        $this->reset('isi_catatan', 'foto');
        unset($this->linimasa);
    }

    private function segarkan(): void
    {
        unset($this->tiket, $this->linimasa, $this->statusMonitoring, $this->jalur, $this->dampak);

        $this->muatIsian($this->tiket);
    }

    private function muatIsian(Tiket $tiket): void
    {
        $this->teknisi_id = (string) $tiket->teknisi_id;
        $this->dugaan_penyebab = (string) $tiket->dugaan_penyebab;
        $this->penyebab_terverifikasi = (string) $tiket->penyebab_terverifikasi;
        $this->waktu_pemulihan = FormulirTiket::keIsian($tiket->waktu_pemulihan ?? $tiket->monitoring_pulih_pada ?? now()->toImmutable());
    }
}; ?>

<section class="w-full space-y-8" data-test="rincian-tiket">
    @php
        $zona = config('app.display_timezone');
        $tiket = $this->tiket;
        $waktu = fn (?\Carbon\CarbonInterface $w): string => $w === null ? \App\Support\Tampilan::TIDAK_TERSEDIA : $w->timezone($zona)->format('d-m-Y H:i');
        $bolehTangani = auth()->user()->can('tangani', $tiket);
        $bolehKelola = auth()->user()->can('update', $tiket);
        $aset = $tiket->asetFo();
        $statusMonitoring = $this->statusMonitoring;
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:text size="sm" class="uppercase tracking-wide tabular-nums">{{ $tiket->nomor }}</flux:text>
            <flux:heading size="xl" level="1">{{ $tiket->judul }}</flux:heading>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <flux:badge :color="$tiket->status->warna()" data-test="status-tiket" data-status="{{ $tiket->status->value }}">Pekerjaan: {{ $tiket->status->label() }}</flux:badge>
                <flux:badge :color="$tiket->prioritas->warna()">Prioritas {{ $tiket->prioritas->label() }}</flux:badge>
                <flux:badge color="zinc">{{ $tiket->sumber === \App\Models\Tiket::OTOMATIS ? 'Dibuat otomatis dari monitoring' : 'Dibuat manual' }}</flux:badge>
                <x-lencana-simulasi :tampil="$tiket->simulasi" />
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($bolehKelola)
                <flux:button icon="pencil-square" wire:click="ubah" data-test="ubah-tiket">Ubah data</flux:button>
            @endif
            <flux:button :href="route('tiket.index')" icon="arrow-left" wire:navigate>Kembali ke daftar</flux:button>
        </div>
    </div>

    <flux:error name="tiket" data-test="galat-tiket" />

    {{-- Monitoring dan pekerjaan adalah dua hal yang berbeda. --}}
    @if ($tiket->endpoint_id !== null || $tiket->insiden_id !== null)
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="monitoring-tiket">
            <flux:heading class="mb-2">Menurut monitoring</flux:heading>
            <div class="flex flex-wrap items-center gap-2">
                @if ($statusMonitoring)
                    <x-status-koneksi :status="$statusMonitoring" />
                @endif
                @if ($tiket->insiden)
                    <flux:text>
                        Insiden mulai {{ $waktu($tiket->insiden->mulai_pada) }} WIB
                        @if ($tiket->insiden->selesai_pada)
                            · ditutup {{ $waktu($tiket->insiden->selesai_pada) }} WIB ({{ $tiket->insiden->ditutup_karena === \App\Models\Insiden::PULIH ? 'endpoint terjangkau kembali' : 'pemeliharaan' }})
                        @else
                            · masih berlangsung
                        @endif
                    </flux:text>
                @endif
            </div>

            @if ($tiket->monitoring_pulih_pada !== null && $tiket->status->terbuka())
                <flux:text class="mt-2" data-test="pulih-belum-ditutup">
                    Monitoring mencatat endpoint terjangkau kembali pada {{ $waktu($tiket->monitoring_pulih_pada) }} WIB.
                    Pekerjaan belum ditutup: tiket tetap terbuka sampai orang yang menangani menyelesaikannya.
                </flux:text>
            @endif

            <flux:text size="sm" class="mt-2">
                Monitoring hanya memeriksa keterjangkauan switch dari jaringan Diskominfo. Pemeriksaan yang gagal tidak menunjukkan penyebabnya,
                termasuk tidak menunjukkan bahwa kabel putus, dan pemeriksaan yang berhasil tidak membuktikan layanan di OPD sudah normal.
            </flux:text>
        </div>
    @endif

    <div class="grid gap-8 lg:grid-cols-3">
        <div class="space-y-8 lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2" data-test="data-tiket">
                <div>
                    <dt class="text-sm text-zinc-500">OPD</dt>
                    <dd>{{ $tiket->opd?->nama ?? \App\Support\Tampilan::TIDAK_TERSEDIA }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">Perangkat</dt>
                    <dd>{{ $tiket->perangkat ? $tiket->perangkat->kode_aset.' — '.$tiket->perangkat->nama : \App\Support\Tampilan::TIDAK_TERSEDIA }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">Aset FO terkait</dt>
                    <dd>
                        @if ($aset)
                            {{ $aset->kode }}
                            <span class="text-zinc-500">({{ $aset instanceof \App\Models\SegmenKabel ? 'segmen kabel' : ($aset instanceof \App\Models\Tiang ? 'tiang' : $aset->jenis->label()) }})</span>
                        @else
                            {{ \App\Support\Tampilan::TIDAK_TERSEDIA }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">Teknisi</dt>
                    <dd>{{ $tiket->teknisi?->name ?? 'Belum ditugaskan' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">Waktu mulai</dt>
                    <dd class="tabular-nums">{{ $waktu($tiket->waktu_mulai) }} WIB</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500">Waktu pemulihan</dt>
                    <dd class="tabular-nums" data-test="waktu-pemulihan">{{ $tiket->waktu_pemulihan ? $waktu($tiket->waktu_pemulihan).' WIB' : 'Belum dinyatakan pulih' }}</dd>
                </div>
            </dl>

            <form wire:submit="simpanPenyebab" class="space-y-4" data-test="penyebab">
                <flux:heading>Penyebab</flux:heading>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:textarea wire:model="dugaan_penyebab" label="Dugaan penyebab" rows="3" maxlength="2000" :disabled="! $bolehTangani"
                        description="Perkiraan. Belum diperiksa di lapangan." />
                    <flux:textarea wire:model="penyebab_terverifikasi" label="Penyebab terverifikasi" rows="3" maxlength="2000" :disabled="! $bolehTangani"
                        description="Hanya diisi setelah diperiksa dan dipastikan." />
                </div>
                @if ($bolehTangani)
                    <flux:button type="submit" data-test="simpan-penyebab">Simpan penyebab</flux:button>
                @endif
            </form>

            @if ($bolehTangani)
                <form wire:submit="tambahCatatan" class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="formulir-catatan">
                    <flux:heading>Tambah tindakan atau catatan</flux:heading>
                    <div class="grid gap-4 sm:grid-cols-[12rem_1fr]">
                        <flux:select wire:model="jenis_catatan" label="Jenis">
                            <flux:select.option value="tindakan">Tindakan</flux:select.option>
                            <flux:select.option value="catatan">Catatan</flux:select.option>
                        </flux:select>
                        <flux:input wire:model="foto" type="file" label="Foto" accept="image/jpeg,image/png,image/webp" description="Opsional. JPG, PNG, atau WebP, paling besar 5 MB." />
                    </div>
                    <flux:textarea wire:model="isi_catatan" label="Isi" rows="3" maxlength="4000" required />
                    <flux:button type="submit" variant="primary" data-test="tambah-catatan">Tambahkan</flux:button>
                </form>
            @endif

            <div>
                <flux:heading class="mb-3">Linimasa ({{ $this->linimasa->count() }})</flux:heading>
                <ol class="space-y-3" data-test="linimasa">
                    @foreach ($this->linimasa as $catatan)
                        <li class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="catatan-{{ $catatan->id }}">
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <flux:badge size="sm" :color="$catatan->jenis === \App\Enums\JenisCatatanTiket::Tindakan ? 'sky' : 'zinc'">{{ $catatan->jenis->label() }}</flux:badge>
                                <span class="tabular-nums text-zinc-500">{{ $catatan->dibuat_pada->timezone($zona)->format('d-m-Y H:i') }} WIB</span>
                                <span class="text-zinc-500">· {{ $catatan->pengguna_nama ?? 'Sistem' }}</span>
                            </div>
                            <p class="mt-1 whitespace-pre-line text-sm">{{ $catatan->isi }}</p>
                            @if ($catatan->foto_path)
                                <a href="{{ route('tiket.foto', [$tiket, $catatan]) }}" target="_blank" rel="noopener">
                                    <img src="{{ route('tiket.foto', [$tiket, $catatan]) }}" alt="Foto {{ strtolower($catatan->jenis->label()) }} tiket {{ $tiket->nomor }}" class="mt-2 max-h-48 rounded" loading="lazy" />
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>

        <div class="space-y-6">
            @if ($bolehKelola)
                <form wire:submit="tugaskan" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="penugasan">
                    <flux:heading>Penugasan</flux:heading>
                    <flux:select wire:model="teknisi_id" aria-label="Teknisi">
                        <flux:select.option value="">Belum ditugaskan</flux:select.option>
                        @foreach ($this->daftarTeknisi as $teknisi)
                            <flux:select.option :value="$teknisi->id">{{ $teknisi->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button type="submit" data-test="tugaskan">Simpan penugasan</flux:button>
                </form>
            @endif

            @if ($bolehTangani || $bolehKelola)
                <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="aksi-status">
                    <flux:heading>Status pekerjaan</flux:heading>
                    <div class="flex flex-wrap gap-2">
                        @if ($tiket->status->terbuka())
                            @if ($bolehTangani)
                                @if ($tiket->status !== \App\Enums\StatusTiket::Dikerjakan)
                                    <flux:button wire:click="ubahStatus('dikerjakan')" data-test="mulai-kerjakan">Mulai kerjakan</flux:button>
                                @endif
                                @if ($tiket->status === \App\Enums\StatusTiket::Dikerjakan)
                                    <flux:button wire:click="ubahStatus('menunggu')">Tunda (menunggu)</flux:button>
                                @endif
                                <flux:modal.trigger name="selesaikan-tiket">
                                    <flux:button variant="primary" data-test="buka-selesaikan">Selesaikan</flux:button>
                                </flux:modal.trigger>
                            @endif
                            @if ($bolehKelola)
                                <flux:button variant="danger" wire:click="ubahStatus('dibatalkan')" wire:confirm="Batalkan tiket ini?" data-test="batalkan">Batalkan</flux:button>
                            @endif
                        @elseif ($bolehKelola)
                            <flux:button wire:click="ubahStatus('baru')" data-test="buka-kembali">Buka kembali</flux:button>
                        @endif
                    </div>
                </div>
            @endif

            @if ($this->jalur !== null)
                <div class="space-y-3" data-test="jalur-tiket">
                    <flux:heading>Jalur FO perangkat</flux:heading>
                    <flux:text size="sm">Menurut inventaris. Tidak menunjukkan di bagian mana gangguannya.</flux:text>
                    @foreach ($this->jalur['keterbatasan'] as $pesan)
                        <flux:callout variant="warning" icon="exclamation-triangle" data-test="keterbatasan-jalur">
                            <flux:callout.text>{{ $pesan }}</flux:callout.text>
                        </flux:callout>
                    @endforeach
                    @foreach ($this->jalur['jalur'] as $jalur)
                        <x-jalur-fo :jalur="$jalur" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @if ($this->dampak !== null)
        <x-potensi-terdampak :hasil="$this->dampak" :rinci="false" />
        <flux:button :href="route('fo.penelusuran', ['jenis' => $aset instanceof \App\Models\SegmenKabel ? 'kabel' : ($aset instanceof \App\Models\Tiang ? 'tiang' : 'titik'), 'kode' => $aset->kode])" icon="arrows-pointing-out" wire:navigate>
            Lihat rincian jalur
        </flux:button>
    @endif

    @if ($bolehTangani)
        <flux:modal name="selesaikan-tiket" class="w-full max-w-lg">
            <form wire:submit="selesaikan" class="space-y-6">
                <div>
                    <flux:heading size="lg">Selesaikan tiket</flux:heading>
                    <flux:subheading>Isi kapan layanan benar-benar pulih menurut pemeriksaan Anda.</flux:subheading>
                </div>

                <flux:input wire:model="waktu_pemulihan" type="datetime-local" label="Waktu pemulihan (WIB)" required />

                @if ($statusMonitoring === \App\Enums\StatusMonitoring::TidakTerjangkau)
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.text>Monitoring masih mencatat endpoint ini tidak terjangkau.</flux:callout.text>
                    </flux:callout>
                @endif

                @if ($tiket->penyebab_terverifikasi === null)
                    <flux:text size="sm">Penyebab terverifikasi belum diisi.</flux:text>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Batal</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="selesaikan">Selesaikan tiket</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($bolehKelola)
        <flux:modal name="formulir-tiket" variant="flyout" class="w-full max-w-xl">
            <form wire:submit="simpan" class="space-y-6">
                <flux:heading size="lg">Ubah data tiket</flux:heading>

                @include('partials.isian-tiket')

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Batal</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="simpan-tiket">Simpan</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
