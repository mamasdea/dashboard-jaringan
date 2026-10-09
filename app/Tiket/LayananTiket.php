<?php

namespace App\Tiket;

use App\Enums\Izin;
use App\Enums\JenisCatatanTiket;
use App\Enums\PrioritasTiket;
use App\Enums\StatusTiket;
use App\Models\CatatanTiket;
use App\Models\EndpointMonitoring;
use App\Models\Insiden;
use App\Models\Tiket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Semua perubahan tiket lewat sini, supaya linimasanya selalu terisi.
 *
 * Status tiket adalah status pekerjaan. Monitoring hanya boleh membuat tiket
 * dan menambah catatan; ia tidak pernah mengubah status pekerjaan dan tidak
 * pernah mengisi penyebab.
 */
class LayananTiket
{
    public const string CATATAN_OTOMATIS = 'Dibuat otomatis dari insiden monitoring: endpoint tidak menjawab pemeriksaan dari jaringan Diskominfo. '
        .'Penyebab belum diketahui. Pemeriksaan yang gagal tidak menunjukkan apakah gangguan ada di kabel, perangkat, atau catu daya.';

    /**
     * Membuat tiket untuk insiden yang baru dibuka. Bila endpoint sudah punya
     * tiket yang masih terbuka (otomatis ataupun manual), insiden dikaitkan ke
     * tiket itu dan tidak ada tiket baru.
     */
    public function bukaDariInsiden(Insiden $insiden, EndpointMonitoring $endpoint): ?Tiket
    {
        $terbuka = Tiket::query()->terbuka()->where('endpoint_id', $endpoint->id)->orderBy('id')->lockForUpdate()->first();

        if ($terbuka !== null) {
            $berulang = $terbuka->insiden_id !== null && $terbuka->insiden_id !== $insiden->id;

            $terbuka->insiden_id = $insiden->id;
            $terbuka->monitoring_pulih_pada = null;
            $terbuka->save();

            $this->catat($terbuka, JenisCatatanTiket::Sistem, $berulang
                ? 'Monitoring kembali mencatat endpoint tidak terjangkau. Tiket ini masih terbuka, sehingga tidak dibuat tiket baru.'
                : 'Monitoring mencatat endpoint tidak terjangkau. Insiden dikaitkan dengan tiket ini.');

            return $terbuka;
        }

        if (! config('tiket.otomatis')) {
            return null;
        }

        $perangkat = $endpoint->perangkat;

        $tiket = Tiket::create([
            'judul' => "Endpoint tidak terjangkau: {$endpoint->nama}",
            'opd_id' => $perangkat?->opd_id,
            'perangkat_id' => $perangkat?->id,
            'endpoint_id' => $endpoint->id,
            'insiden_id' => $insiden->id,
            'sumber' => Tiket::OTOMATIS,
            'waktu_mulai' => $insiden->mulai_pada,
            'prioritas' => PrioritasTiket::tryFrom((string) config('tiket.prioritas_otomatis')) ?? PrioritasTiket::Tinggi,
            'simulasi' => $insiden->simulasi,
        ]);

        $this->catat($tiket, JenisCatatanTiket::Sistem, self::CATATAN_OTOMATIS);

        return $tiket;
    }

    /**
     * Monitoring menutup insiden. Tiket hanya diberi keterangan; status
     * pekerjaan dan waktu pemulihannya tetap menunggu orang yang menangani.
     */
    public function catatPenutupanInsiden(Insiden $insiden): void
    {
        $pulih = $insiden->ditutup_karena === Insiden::PULIH;

        Tiket::query()->where('insiden_id', $insiden->id)->get()->each(function (Tiket $tiket) use ($insiden, $pulih): void {
            if ($pulih) {
                $tiket->monitoring_pulih_pada = $insiden->selesai_pada;
                $tiket->save();
            }

            if ($tiket->status->terbuka()) {
                $this->catat($tiket, JenisCatatanTiket::Sistem, $pulih
                    ? 'Monitoring mencatat endpoint terjangkau kembali. Status pekerjaan tidak diubah; tutup tiket setelah pekerjaan benar-benar selesai.'
                    : 'Insiden monitoring ditutup karena endpoint masuk masa pemeliharaan. Status pekerjaan tidak diubah.');
            }
        });
    }

    /**
     * Tiket yang dibuat orang. Bila endpoint perangkatnya sedang punya insiden
     * terbuka yang belum bertiket, insiden itu dikaitkan.
     *
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data, ?User $pelaku): Tiket
    {
        return DB::transaction(function () use ($data, $pelaku): Tiket {
            $tiket = new Tiket($data);
            $tiket->sumber = Tiket::MANUAL;
            $tiket->dibuat_oleh = $pelaku?->id;
            $this->kaitkanMonitoring($tiket);
            $tiket->save();

            $this->catat($tiket, JenisCatatanTiket::Sistem, 'Tiket dibuat'.($tiket->insiden_id === null ? '.' : ' dan dikaitkan dengan insiden monitoring yang sedang berlangsung.'), $pelaku);

            return $tiket;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function ubah(Tiket $tiket, array $data): void
    {
        DB::transaction(function () use ($tiket, $data): void {
            $tiket->fill($data);

            if ($tiket->isDirty('perangkat_id')) {
                $this->kaitkanMonitoring($tiket);
            }

            if ($tiket->waktu_pemulihan !== null && $tiket->waktu_pemulihan->lessThan($tiket->waktu_mulai)) {
                $this->gagal('Waktu mulai tidak boleh setelah waktu pemulihan.');
            }

            $tiket->save();
        });
    }

    public function tugaskan(Tiket $tiket, ?User $teknisi, ?User $pelaku): void
    {
        if ($teknisi !== null && ! $teknisi->boleh(Izin::TanganiTiket)) {
            $this->gagal("{$teknisi->name} tidak memiliki izin menangani tiket.");
        }

        if ($tiket->teknisi_id === $teknisi?->id) {
            return;
        }

        DB::transaction(function () use ($tiket, $teknisi, $pelaku): void {
            $tiket->teknisi_id = $teknisi?->id;

            if ($tiket->status === StatusTiket::Baru && $teknisi !== null) {
                $tiket->status = StatusTiket::Ditugaskan;
            } elseif ($tiket->status->terbuka() && $teknisi === null) {
                $tiket->status = StatusTiket::Baru;
            }

            $tiket->save();

            $this->catat($tiket, JenisCatatanTiket::Sistem, $teknisi === null ? 'Penugasan dicabut.' : "Ditugaskan kepada {$teknisi->name}.", $pelaku);
        });
    }

    /**
     * Mengubah status pekerjaan. "Selesai" membutuhkan waktu pemulihan yang
     * dinyatakan orang; waktu pulih menurut monitoring tidak dipakai otomatis.
     */
    public function ubahStatus(Tiket $tiket, StatusTiket $status, ?User $pelaku, ?CarbonInterface $waktuPemulihan = null): void
    {
        $dari = $tiket->status;

        // Membuka kembali: kembali ke antrean, bukan langsung ke status kerja.
        if (! $dari->terbuka() && $status->terbuka()) {
            $status = $tiket->teknisi_id === null ? StatusTiket::Baru : StatusTiket::Ditugaskan;
        }

        if ($dari === $status) {
            return;
        }

        if (in_array($status, [StatusTiket::Ditugaskan, StatusTiket::Dikerjakan, StatusTiket::Menunggu], true) && $tiket->teknisi_id === null) {
            $this->gagal('Tiket belum ditugaskan kepada teknisi.');
        }

        if (! $dari->terbuka() && ! $status->terbuka()) {
            $this->gagal('Tiket sudah ditutup. Buka kembali dahulu untuk mengubahnya.');
        }

        if ($status === StatusTiket::Selesai) {
            $waktuPemulihan = $waktuPemulihan === null ? null : CarbonImmutable::instance($waktuPemulihan)->utc();

            match (true) {
                $waktuPemulihan === null => $this->gagal('Isi waktu pemulihan untuk menyelesaikan tiket.'),
                $waktuPemulihan->lessThan($tiket->waktu_mulai) => $this->gagal('Waktu pemulihan tidak boleh sebelum waktu mulai gangguan.'),
                $waktuPemulihan->greaterThan(now()->addMinute()) => $this->gagal('Waktu pemulihan tidak boleh di masa depan.'),
                default => null,
            };

            $tiket->waktu_pemulihan = $waktuPemulihan;
        } elseif ($status->terbuka()) {
            $tiket->waktu_pemulihan = null;
        }

        try {
            DB::transaction(function () use ($tiket, $dari, $status, $pelaku): void {
                $tiket->status = $status;
                $tiket->save();

                $this->catat($tiket, JenisCatatanTiket::Sistem, "Status pekerjaan: {$dari->label()} → {$status->label()}.", $pelaku);
            });
        } catch (UniqueConstraintViolationException) {
            $tiket->refresh();
            $this->gagal('Endpoint ini sudah memiliki tiket otomatis lain yang masih terbuka.');
        }
    }

    /**
     * Dugaan dan penyebab terverifikasi disimpan terpisah; yang kedua hanya
     * diisi oleh orang yang sudah memeriksa.
     */
    public function isiPenyebab(Tiket $tiket, ?string $dugaan, ?string $terverifikasi, ?User $pelaku): void
    {
        DB::transaction(function () use ($tiket, $dugaan, $terverifikasi, $pelaku): void {
            $tiket->dugaan_penyebab = $dugaan;
            $tiket->penyebab_terverifikasi = $terverifikasi;

            $berubah = array_keys(array_filter([
                'dugaan penyebab' => $tiket->isDirty('dugaan_penyebab'),
                'penyebab terverifikasi' => $tiket->isDirty('penyebab_terverifikasi'),
            ]));

            if ($berubah === []) {
                return;
            }

            $tiket->save();

            $this->catat($tiket, JenisCatatanTiket::Sistem, ucfirst(implode(' dan ', $berubah)).' diperbarui.', $pelaku);
        });
    }

    public function catat(Tiket $tiket, JenisCatatanTiket $jenis, string $isi, ?User $pelaku = null, ?string $fotoPath = null): CatatanTiket
    {
        return CatatanTiket::create([
            'tiket_id' => $tiket->id,
            'pengguna_id' => $pelaku?->id,
            'pengguna_nama' => $pelaku?->name,
            'jenis' => $jenis,
            'isi' => $isi,
            'foto_path' => $fotoPath,
            'dibuat_pada' => now(),
        ]);
    }

    /**
     * Menyamakan endpoint tiket dengan perangkatnya dan mengaitkan insiden
     * terbuka yang belum bertiket.
     */
    private function kaitkanMonitoring(Tiket $tiket): void
    {
        $endpoint = $tiket->perangkat_id === null
            ? null
            : EndpointMonitoring::query()->where('perangkat_id', $tiket->perangkat_id)->first();

        if ($tiket->endpoint_id === $endpoint?->id) {
            return;
        }

        $tiket->endpoint_id = $endpoint?->id;
        $tiket->insiden_id = null;
        $tiket->monitoring_pulih_pada = null;

        if ($endpoint === null) {
            return;
        }

        $insiden = Insiden::query()->where('endpoint_id', $endpoint->id)->whereNull('selesai_pada')->first();

        if ($insiden !== null && ! Tiket::query()->where('insiden_id', $insiden->id)->exists()) {
            $tiket->insiden_id = $insiden->id;
        }
    }

    private function gagal(string $pesan): never
    {
        throw ValidationException::withMessages(['tiket' => $pesan]);
    }
}
