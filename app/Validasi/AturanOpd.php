<?php

namespace App\Validasi;

use App\Models\Opd;
use Illuminate\Validation\Rule;

/**
 * Aturan OPD yang dipakai bersama oleh formulir dan impor CSV.
 */
final class AturanOpd
{
    public const string POLA_KODE = '/^[A-Z0-9][A-Z0-9._-]*$/';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function siapkan(array $data): array
    {
        $hasil = [
            'kode' => Normalisasi::kode($data['kode'] ?? null),
            'nama' => Normalisasi::teks($data['nama'] ?? null),
            'alamat' => Normalisasi::teks($data['alamat'] ?? null),
            'lintang' => Normalisasi::desimal($data['lintang'] ?? null),
            'bujur' => Normalisasi::desimal($data['bujur'] ?? null),
            'kontak_teknis_nama' => Normalisasi::teks($data['kontak_teknis_nama'] ?? null),
            'kontak_teknis_telepon' => Normalisasi::teks($data['kontak_teknis_telepon'] ?? null),
            'kontak_teknis_email' => Normalisasi::teks($data['kontak_teknis_email'] ?? null),
            'aktif' => Normalisasi::boolean($data['aktif'] ?? null, true),
        ];

        if (array_key_exists('simulasi', $data)) {
            $hasil['simulasi'] = Normalisasi::boolean($data['simulasi'], false);
        }

        return $hasil;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function aturan(?int $id = null): array
    {
        return [
            'kode' => ['required', 'string', 'max:30', 'regex:'.self::POLA_KODE, Rule::unique(Opd::class, 'kode')->ignore($id)],
            'nama' => ['required', 'string', 'max:255'],
            'alamat' => ['nullable', 'string', 'max:1000'],
            'lintang' => ['nullable', 'required_with:bujur', 'numeric', 'between:-90,90'],
            'bujur' => ['nullable', 'required_with:lintang', 'numeric', 'between:-180,180'],
            'kontak_teknis_nama' => ['nullable', 'string', 'max:255'],
            'kontak_teknis_telepon' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{5,30}$/'],
            'kontak_teknis_email' => ['nullable', 'string', 'email', 'max:255'],
            'aktif' => ['boolean'],
            'simulasi' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function pesan(): array
    {
        return [
            'kode.regex' => 'Kode hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.',
            'kontak_teknis_telepon.regex' => 'Nomor telepon hanya boleh berisi angka, spasi, dan tanda + ( ) -.',
        ];
    }
}
