<?php

namespace App\Validasi;

use App\Enums\JenisPerangkat;
use App\Enums\PenempatanPerangkat;
use App\Models\Opd;
use App\Models\Perangkat;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Aturan perangkat yang dipakai bersama oleh formulir dan impor CSV.
 */
final class AturanPerangkat
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function siapkan(array $data): array
    {
        $hasil = [
            'opd_id' => Normalisasi::teks($data['opd_id'] ?? null),
            'penempatan' => mb_strtolower(Normalisasi::teks($data['penempatan'] ?? null) ?? PenempatanPerangkat::Opd->value),
            'lokasi' => Normalisasi::teks($data['lokasi'] ?? null),
            'kode_aset' => Normalisasi::kode($data['kode_aset'] ?? null),
            'nama' => Normalisasi::teks($data['nama'] ?? null),
            'jenis' => ($jenis = Normalisasi::teks($data['jenis'] ?? null)) === null ? null : mb_strtolower($jenis),
            'merek' => Normalisasi::teks($data['merek'] ?? null),
            'model' => Normalisasi::teks($data['model'] ?? null),
            'nomor_seri' => Normalisasi::teks($data['nomor_seri'] ?? null),
            'ip_manajemen' => Normalisasi::teks($data['ip_manajemen'] ?? null),
            'port_uplink' => Normalisasi::teks($data['port_uplink'] ?? null),
            'monitoring_aktif' => Normalisasi::boolean($data['monitoring_aktif'] ?? null, false),
            'switch_utama' => Normalisasi::boolean($data['switch_utama'] ?? null, false),
            'catatan' => Normalisasi::teks($data['catatan'] ?? null),
        ];

        if (array_key_exists('simulasi', $data)) {
            $hasil['simulasi'] = Normalisasi::boolean($data['simulasi'], false);
        }

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $data  Data yang sudah melalui siapkan().
     * @return array<string, list<mixed>>
     */
    public static function aturan(array $data, ?int $id = null): array
    {
        return [
            'opd_id' => ['required', 'integer', Rule::exists(Opd::class, 'id')],
            'penempatan' => ['required', Rule::enum(PenempatanPerangkat::class)],
            'lokasi' => ['nullable', 'string', 'max:255'],
            'kode_aset' => ['required', 'string', 'max:50', 'regex:'.AturanOpd::POLA_KODE, Rule::unique(Perangkat::class, 'kode_aset')->ignore($id)],
            'nama' => ['required', 'string', 'max:255'],
            'jenis' => ['required', Rule::enum(JenisPerangkat::class)],
            'merek' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'nomor_seri' => ['nullable', 'string', 'max:100'],
            // `bail`: teks yang bukan IP tidak boleh sampai ke kueri kolom inet.
            'ip_manajemen' => ['bail', 'nullable', 'required_if_accepted:monitoring_aktif', 'ip', Rule::unique(Perangkat::class, 'ip_manajemen')->ignore($id)],
            'port_uplink' => ['nullable', 'string', 'max:100'],
            'monitoring_aktif' => ['boolean'],
            'switch_utama' => ['boolean', function (string $atribut, mixed $nilai, Closure $gagal) use ($data): void {
                if ($nilai === true && ($data['jenis'] ?? null) !== JenisPerangkat::Switch->value) {
                    $gagal('Penanda switch utama hanya untuk perangkat berjenis switch.');
                }
            }],
            'catatan' => ['nullable', 'string', 'max:5000'],
            'simulasi' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function pesan(): array
    {
        return [
            'kode_aset.regex' => 'Kode aset hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.',
            'ip_manajemen.required_if_accepted' => 'IP manajemen wajib diisi bila monitoring diaktifkan.',
            'ip_manajemen.unique' => 'IP manajemen sudah dipakai perangkat lain.',
        ];
    }
}
