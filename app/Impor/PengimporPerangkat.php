<?php

namespace App\Impor;

use App\Models\Opd;
use App\Models\Perangkat;
use App\Validasi\AturanPerangkat;
use App\Validasi\Normalisasi;
use Illuminate\Support\Facades\Validator;

class PengimporPerangkat extends PengimporCsv
{
    public function jenis(): string
    {
        return 'perangkat';
    }

    public function judul(): string
    {
        return 'Perangkat';
    }

    public function kelasModel(): string
    {
        return Perangkat::class;
    }

    public function kolomKunci(): string
    {
        return 'kode_aset';
    }

    /**
     * Tidak ada kolom foto (diunggah lewat formulir) dan tidak ada kolom
     * kredensial SNMP (tidak disimpan di tabel perangkat).
     */
    public function kolom(): array
    {
        return [
            'kode_aset' => ['wajib' => true, 'contoh' => 'SIM-SW-DINKES-01', 'keterangan' => 'Unik. Kode yang sudah ada akan memperbarui perangkat tersebut.'],
            'nama' => ['wajib' => true, 'contoh' => '[SIMULASI] Switch Utama Dinkes', 'keterangan' => 'Nama perangkat.'],
            'kode_opd' => ['wajib' => true, 'contoh' => 'SIM-DINKES', 'keterangan' => 'Kode OPD pemilik; OPD harus sudah ada.'],
            'penempatan' => ['wajib' => false, 'contoh' => 'opd', 'keterangan' => 'opd atau diskominfo. Kosong berarti opd.'],
            'lokasi' => ['wajib' => false, 'contoh' => 'Ruang server lantai 1', 'keterangan' => 'Ruang atau rak.'],
            'jenis' => ['wajib' => true, 'contoh' => 'switch', 'keterangan' => 'switch, router, firewall, access_point, olt, media_converter, atau lainnya.'],
            'merek' => ['wajib' => false, 'contoh' => 'Merek Contoh', 'keterangan' => 'Merek.'],
            'model' => ['wajib' => false, 'contoh' => 'MC-2400', 'keterangan' => 'Model.'],
            'nomor_seri' => ['wajib' => false, 'contoh' => 'SIM00000001', 'keterangan' => 'Nomor seri.'],
            'ip_manajemen' => ['wajib' => false, 'contoh' => '192.0.2.11', 'keterangan' => 'Unik. Wajib bila monitoring_aktif = ya.'],
            'port_uplink' => ['wajib' => false, 'contoh' => 'GigabitEthernet0/1', 'keterangan' => 'Nama port uplink yang dipantau.'],
            'monitoring_aktif' => ['wajib' => false, 'contoh' => 'ya', 'keterangan' => 'ya atau tidak. Kosong berarti tidak.'],
            'switch_utama' => ['wajib' => false, 'contoh' => 'ya', 'keterangan' => 'ya atau tidak. Paling banyak satu per OPD, hanya untuk jenis switch.'],
            'catatan' => ['wajib' => false, 'contoh' => 'Data contoh', 'keterangan' => 'Catatan bebas.'],
            'simulasi' => ['wajib' => false, 'contoh' => 'ya', 'keterangan' => 'ya untuk data contoh; nama akan diberi awalan [SIMULASI]. Kosong berarti tidak.'],
        ];
    }

    protected function petaKolom(): array
    {
        return ['kode_opd' => 'opd_id'];
    }

    protected function periksa(array $nilai): array
    {
        $galat = [];
        $kodeOpd = Normalisasi::kode($nilai['kode_opd'] ?? null);
        $opd = $kodeOpd === null ? null : Opd::query()->where('kode', $kodeOpd)->first();

        if ($kodeOpd === null) {
            $galat[] = 'Kode OPD wajib diisi.';
        } elseif ($opd === null) {
            $galat[] = "OPD dengan kode {$kodeOpd} tidak ditemukan.";
        }

        $data = AturanPerangkat::siapkan($nilai + ['simulasi' => null, 'opd_id' => $opd?->id]);
        $model = (is_string($data['kode_aset']) ? Perangkat::query()->where('kode_aset', $data['kode_aset'])->first() : null) ?? new Perangkat;

        $aturan = AturanPerangkat::aturan($data, $model->exists ? $model->id : null);

        if ($opd === null) {
            // Sudah dilaporkan di atas dengan pesan yang lebih jelas.
            unset($aturan['opd_id']);
        }

        $validator = Validator::make($data, $aturan, AturanPerangkat::pesan());
        $galat = [...$galat, ...array_values($validator->errors()->all())];

        // Formulir memindahkan penanda switch utama; impor massal menolaknya agar
        // penanda tidak berpindah tanpa disadari.
        if ($opd !== null && $data['switch_utama'] === true) {
            $utamaLain = Perangkat::query()
                ->where('opd_id', $opd->id)
                ->where('switch_utama', true)
                ->when($model->exists, fn ($query) => $query->whereKeyNot($model->id))
                ->first();

            if ($utamaLain !== null) {
                $galat[] = "OPD {$opd->kode} sudah memiliki switch utama ({$utamaLain->kode_aset}). Ubah perangkat itu menjadi switch_utama = tidak di baris sebelumnya.";
            }
        }

        return ['data' => $data, 'model' => $model, 'galat' => $galat];
    }
}
