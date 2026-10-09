<?php

namespace App\Impor;

use App\Models\Opd;
use App\Validasi\AturanOpd;
use Illuminate\Support\Facades\Validator;

class PengimporOpd extends PengimporCsv
{
    public function jenis(): string
    {
        return 'opd';
    }

    public function judul(): string
    {
        return 'OPD';
    }

    public function kelasModel(): string
    {
        return Opd::class;
    }

    public function kolomKunci(): string
    {
        return 'kode';
    }

    public function kolom(): array
    {
        return [
            'kode' => ['wajib' => true, 'contoh' => 'SIM-DINKES', 'keterangan' => 'Unik. Kode yang sudah ada akan memperbarui OPD tersebut.'],
            'nama' => ['wajib' => true, 'contoh' => '[SIMULASI] Dinas Kesehatan', 'keterangan' => 'Nama OPD.'],
            'alamat' => ['wajib' => false, 'contoh' => 'Jl. Contoh No. 1, Wonosobo', 'keterangan' => 'Alamat kantor.'],
            'lintang' => ['wajib' => false, 'contoh' => '-7.360000', 'keterangan' => 'Derajat desimal, -90 sampai 90. Wajib bila bujur diisi.'],
            'bujur' => ['wajib' => false, 'contoh' => '109.900000', 'keterangan' => 'Derajat desimal, -180 sampai 180. Wajib bila lintang diisi.'],
            'kontak_teknis_nama' => ['wajib' => false, 'contoh' => '[SIMULASI] Kontak Teknis', 'keterangan' => 'Nama narahubung teknis.'],
            'kontak_teknis_telepon' => ['wajib' => false, 'contoh' => '0286 000001', 'keterangan' => 'Nomor telepon narahubung.'],
            'kontak_teknis_email' => ['wajib' => false, 'contoh' => 'kontak@example.test', 'keterangan' => 'Email narahubung.'],
            'aktif' => ['wajib' => false, 'contoh' => 'ya', 'keterangan' => 'ya atau tidak. Kosong berarti ya.'],
            'simulasi' => ['wajib' => false, 'contoh' => 'ya', 'keterangan' => 'ya untuk data contoh; nama akan diberi awalan [SIMULASI]. Kosong berarti tidak.'],
        ];
    }

    protected function periksa(array $nilai): array
    {
        $data = AturanOpd::siapkan($nilai + ['simulasi' => null]);
        $model = (is_string($data['kode']) ? Opd::query()->where('kode', $data['kode'])->first() : null) ?? new Opd;

        $validator = Validator::make($data, AturanOpd::aturan($model->exists ? $model->id : null), AturanOpd::pesan());

        return ['data' => $data, 'model' => $model, 'galat' => array_values($validator->errors()->all())];
    }
}
