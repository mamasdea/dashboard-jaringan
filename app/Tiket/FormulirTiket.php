<?php

namespace App\Tiket;

use App\Enums\PrioritasTiket;
use App\Models\Perangkat;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\Tiket;
use App\Models\TitikJaringan;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

/**
 * Isian data tiket, dipakai saat membuat (daftar tiket) dan mengubah (rincian).
 */
class FormulirTiket extends Form
{
    /** Jenis aset FO yang dapat dikaitkan dan kolom tiketnya. */
    public const array ASET_FO = [
        'kabel' => ['label' => 'Segmen kabel', 'kolom' => 'segmen_kabel_id', 'model' => SegmenKabel::class],
        'titik' => ['label' => 'Titik jaringan', 'kolom' => 'titik_jaringan_id', 'model' => TitikJaringan::class],
        'tiang' => ['label' => 'Tiang', 'kolom' => 'tiang_id', 'model' => Tiang::class],
    ];

    private const string FORMAT_WAKTU = 'Y-m-d\TH:i';

    public string $judul = '';

    public string $opd_id = '';

    public string $perangkat_id = '';

    public string $jenis_aset = '';

    public string $kode_aset = '';

    public string $waktu_mulai = '';

    public string $prioritas = 'sedang';

    public string $dugaan_penyebab = '';

    public function kosongkan(): void
    {
        $this->reset();
        $this->resetValidation();
        $this->waktu_mulai = self::keIsian(CarbonImmutable::now());
    }

    public function isi(Tiket $tiket): void
    {
        $this->kosongkan();

        $aset = $tiket->asetFo();

        $this->judul = $tiket->judul;
        $this->opd_id = (string) $tiket->opd_id;
        $this->perangkat_id = (string) $tiket->perangkat_id;
        $this->jenis_aset = match (true) {
            $aset instanceof SegmenKabel => 'kabel',
            $aset instanceof TitikJaringan => 'titik',
            $aset instanceof Tiang => 'tiang',
            default => '',
        };
        $this->kode_aset = (string) $aset?->kode;
        $this->waktu_mulai = self::keIsian($tiket->waktu_mulai);
        $this->prioritas = $tiket->prioritas->value;
        $this->dugaan_penyebab = (string) $tiket->dugaan_penyebab;
    }

    /**
     * Memvalidasi isian dan mengembalikannya sebagai atribut tiket.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $this->judul = trim($this->judul);
        $this->kode_aset = trim($this->kode_aset);

        $this->validate([
            'judul' => ['required', 'string', 'max:200'],
            'opd_id' => ['nullable', 'integer', Rule::exists('opd', 'id')],
            'perangkat_id' => ['nullable', 'integer', Rule::exists('perangkat', 'id')],
            'jenis_aset' => ['nullable', Rule::in(array_keys(self::ASET_FO)), 'required_with:kode_aset'],
            'kode_aset' => ['nullable', 'string', 'max:50', 'required_with:jenis_aset'],
            'waktu_mulai' => ['required', 'date_format:'.self::FORMAT_WAKTU],
            'prioritas' => ['required', Rule::enum(PrioritasTiket::class)],
            'dugaan_penyebab' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'judul' => 'judul',
            'opd_id' => 'OPD',
            'perangkat_id' => 'perangkat',
            'jenis_aset' => 'jenis aset FO',
            'kode_aset' => 'kode aset FO',
            'waktu_mulai' => 'waktu mulai',
            'prioritas' => 'prioritas',
            'dugaan_penyebab' => 'dugaan penyebab',
        ]);

        $mulai = self::dariIsian($this->waktu_mulai);

        if ($mulai->greaterThan(now()->addMinute())) {
            $this->gagal('waktu_mulai', 'Waktu mulai tidak boleh di masa depan.');
        }

        $opdId = $this->opd_id === '' ? null : (int) $this->opd_id;
        $perangkat = $this->perangkat_id === '' ? null : Perangkat::query()->findOrFail((int) $this->perangkat_id);

        if ($perangkat !== null && $opdId !== null && $perangkat->opd_id !== $opdId) {
            $this->gagal('perangkat_id', 'Perangkat ini bukan milik OPD yang dipilih.');
        }

        $data = [
            'judul' => $this->judul,
            'opd_id' => $opdId ?? $perangkat?->opd_id,
            'perangkat_id' => $perangkat?->id,
            'segmen_kabel_id' => null,
            'titik_jaringan_id' => null,
            'tiang_id' => null,
            'waktu_mulai' => $mulai,
            'prioritas' => $this->prioritas,
            'dugaan_penyebab' => trim($this->dugaan_penyebab) === '' ? null : trim($this->dugaan_penyebab),
        ];

        if ($this->jenis_aset !== '') {
            $jenis = self::ASET_FO[$this->jenis_aset];
            $aset = $jenis['model']::query()->whereRaw('upper(kode) = ?', [mb_strtoupper($this->kode_aset)])->first();

            if ($aset === null) {
                $this->gagal('kode_aset', "{$jenis['label']} berkode {$this->kode_aset} tidak ditemukan.");
            }

            $data[$jenis['kolom']] = $aset->id;
        }

        return $data;
    }

    /**
     * Isian waktu memakai zona tampilan; penyimpanan selalu UTC.
     */
    public static function dariIsian(string $nilai): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(self::FORMAT_WAKTU, $nilai, (string) config('app.display_timezone'))->utc();
    }

    public static function keIsian(CarbonImmutable $waktu): string
    {
        return $waktu->timezone((string) config('app.display_timezone'))->format(self::FORMAT_WAKTU);
    }

    private function gagal(string $kolom, string $pesan): never
    {
        throw ValidationException::withMessages(["form.{$kolom}" => $pesan]);
    }
}
