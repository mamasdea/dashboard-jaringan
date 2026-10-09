<?php

namespace App\Validasi;

use App\Enums\JenisTitikJaringan;
use App\Enums\KlasifikasiKabel;
use App\Enums\KondisiTiang;
use App\Enums\PemilikTiang;
use App\Models\Opd;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Aturan objek inventaris FO.
 *
 * Pendataan bertahap: hanya kode (dan jenis titik) yang wajib. Kolom lain
 * boleh kosong; objeknya lalu berstatus "Perlu dilengkapi".
 */
final class AturanFo
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function siapkanTiang(array $data): array
    {
        return [
            'kode' => Normalisasi::kode($data['kode'] ?? null),
            'lintang' => Normalisasi::desimal($data['lintang'] ?? null),
            'bujur' => Normalisasi::desimal($data['bujur'] ?? null),
            'pemilik' => Normalisasi::teks($data['pemilik'] ?? null),
            'kondisi' => Normalisasi::teks($data['kondisi'] ?? null),
            'catatan' => Normalisasi::teks($data['catatan'] ?? null),
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function tiang(?int $id = null): array
    {
        return [
            'kode' => self::kode(Tiang::class, $id),
            ...self::koordinat(),
            'pemilik' => ['nullable', Rule::enum(PemilikTiang::class)],
            'kondisi' => ['nullable', Rule::enum(KondisiTiang::class)],
            'catatan' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function siapkanTitik(array $data): array
    {
        return [
            'kode' => Normalisasi::kode($data['kode'] ?? null),
            'nama' => Normalisasi::teks($data['nama'] ?? null),
            'jenis' => Normalisasi::teks($data['jenis'] ?? null),
            'lintang' => Normalisasi::desimal($data['lintang'] ?? null),
            'bujur' => Normalisasi::desimal($data['bujur'] ?? null),
            'kode_tiang' => Normalisasi::kode($data['kode_tiang'] ?? null),
            'opd_id' => Normalisasi::teks($data['opd_id'] ?? null),
            'lokasi' => Normalisasi::teks($data['lokasi'] ?? null),
            'kapasitas_port' => Normalisasi::teks($data['kapasitas_port'] ?? null),
            'catatan' => Normalisasi::teks($data['catatan'] ?? null),
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function titik(?int $id = null): array
    {
        return [
            'kode' => self::kode(TitikJaringan::class, $id),
            'nama' => ['nullable', 'string', 'max:255'],
            'jenis' => ['required', Rule::enum(JenisTitikJaringan::class)],
            ...self::koordinat(),
            'kode_tiang' => ['nullable', 'string', Rule::exists(Tiang::class, 'kode')],
            'opd_id' => ['nullable', 'integer', Rule::exists(Opd::class, 'id')],
            'lokasi' => ['nullable', 'string', 'max:255'],
            'kapasitas_port' => ['nullable', 'integer', 'between:0,576'],
            'catatan' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function siapkanSegmen(array $data): array
    {
        return [
            'kode' => Normalisasi::kode($data['kode'] ?? null),
            'nama' => Normalisasi::teks($data['nama'] ?? null),
            'klasifikasi' => Normalisasi::teks($data['klasifikasi'] ?? null),
            'jumlah_core' => Normalisasi::teks($data['jumlah_core'] ?? null),
            'kode_titik_awal' => Normalisasi::kode($data['kode_titik_awal'] ?? null),
            'kode_titik_akhir' => Normalisasi::kode($data['kode_titik_akhir'] ?? null),
            'panjang_ukur_m' => Normalisasi::desimal($data['panjang_ukur_m'] ?? null),
            'catatan' => Normalisasi::teks($data['catatan'] ?? null),
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function segmen(?int $id = null): array
    {
        return [
            'kode' => self::kode(SegmenKabel::class, $id),
            'nama' => ['nullable', 'string', 'max:255'],
            'klasifikasi' => ['nullable', Rule::enum(KlasifikasiKabel::class)],
            'jumlah_core' => ['nullable', 'integer', 'between:1,288'],
            'kode_titik_awal' => ['bail', 'nullable', 'string', Rule::exists(TitikJaringan::class, 'kode')],
            'kode_titik_akhir' => ['bail', 'nullable', 'string', 'different:kode_titik_awal', Rule::exists(TitikJaringan::class, 'kode')],
            'panjang_ukur_m' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'catatan' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function pesan(): array
    {
        return [
            'kode.regex' => 'Kode hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.',
            'kode_tiang.exists' => 'Tiang dengan kode tersebut tidak ditemukan.',
            'kode_titik_awal.exists' => 'Titik jaringan dengan kode tersebut tidak ditemukan.',
            'kode_titik_akhir.exists' => 'Titik jaringan dengan kode tersebut tidak ditemukan.',
            'kode_titik_akhir.different' => 'Titik akhir harus berbeda dari titik awal.',
            'panjang_ukur_m.gt' => 'Panjang hasil pengukuran harus lebih dari 0.',
        ];
    }

    /**
     * Perubahan yang akan memutus hubungan yang sudah tercatat. Diperiksa
     * terpisah dari aturan di atas karena juga harus berjalan saat kolomnya
     * dikosongkan.
     *
     * @return array<string, string>
     */
    public static function galatPerubahanTitik(TitikJaringan $titik, ?int $kapasitasBaru): array
    {
        $terpakai = $titik->port()
            ->where('nomor', '>', (int) $kapasitasBaru)
            ->where(fn ($query) => $query->whereNotNull('core_id')->orWhereHas('koneksiEndpoint'))
            ->max('nomor');

        return $terpakai === null ? [] : [
            'kapasitas_port' => "Kapasitas tidak dapat dikurangi di bawah {$terpakai}: port {$terpakai} masih terpakai.",
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function galatPerubahanSegmen(SegmenKabel $segmen, ?int $jumlahCoreBaru, ?int $titikAwalBaru, ?int $titikAkhirBaru): array
    {
        $galat = [];

        $terpakai = $segmen->core()
            ->where('nomor', '>', (int) $jumlahCoreBaru)
            ->whereIn('id', DB::table('pemakaian_ujung_core')->select('core_id'))
            ->max('nomor');

        if ($terpakai !== null) {
            $galat['jumlah_core'] = "Jumlah core tidak dapat dikurangi di bawah {$terpakai}: core {$terpakai} masih terhubung.";
        }

        // Ujung yang hanya bertukar posisi (awal menjadi akhir) tetap sah.
        $ujungBaru = [$titikAwalBaru, $titikAkhirBaru];

        foreach (['kode_titik_awal' => $segmen->titik_awal_id, 'kode_titik_akhir' => $segmen->titik_akhir_id] as $kolom => $lama) {
            if ($lama !== null && ! in_array($lama, $ujungBaru, true) && self::segmenTerpakaiDi($segmen, $lama)) {
                $galat[$kolom] = 'Titik ini tidak dapat diganti atau dikosongkan karena core segmen masih terhubung di sana.';
            }
        }

        return $galat;
    }

    /**
     * Mengurai jalur peta dari teks: satu titik per baris, "lintang, bujur".
     *
     * @return array{jalur: list<array{0: float, 1: float}>|null, galat: list<string>} Jalur berisi [bujur, lintang].
     */
    public static function uraiJalur(string $teks): array
    {
        $jalur = [];
        $galat = [];

        foreach (preg_split('/\R/', $teks) ?: [] as $indeks => $baris) {
            if (trim($baris) === '') {
                continue;
            }

            if (! preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*[,;\s]\s*(-?\d+(?:\.\d+)?)\s*$/', $baris, $cocok)) {
                $galat[] = 'Baris '.($indeks + 1).': tulis sebagai "lintang, bujur" dengan titik desimal, mis. -7.3589, 109.9032.';

                continue;
            }

            [$lintang, $bujur] = [(float) $cocok[1], (float) $cocok[2]];

            if (abs($lintang) > 90 || abs($bujur) > 180) {
                $galat[] = 'Baris '.($indeks + 1).': lintang harus antara -90 dan 90, bujur antara -180 dan 180.';

                continue;
            }

            $jalur[] = [$bujur, $lintang];
        }

        if ($galat === [] && count($jalur) === 1) {
            $galat[] = 'Jalur membutuhkan minimal dua titik.';
        }

        if (count($jalur) > 2000) {
            $galat[] = 'Jalur paling banyak 2000 titik.';
        }

        return ['jalur' => $jalur === [] ? null : $jalur, 'galat' => $galat];
    }

    private static function segmenTerpakaiDi(SegmenKabel $segmen, int $titikId): bool
    {
        return DB::table('pemakaian_ujung_core')
            ->where('titik_jaringan_id', $titikId)
            ->whereIn('core_id', $segmen->core()->select('id'))
            ->exists();
    }

    /**
     * @param  class-string  $model
     * @return list<mixed>
     */
    private static function kode(string $model, ?int $id): array
    {
        return ['required', 'string', 'max:50', 'regex:'.AturanOpd::POLA_KODE, Rule::unique($model, 'kode')->ignore($id)];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function koordinat(): array
    {
        return [
            'lintang' => ['nullable', 'required_with:bujur', 'numeric', 'between:-90,90'],
            'bujur' => ['nullable', 'required_with:lintang', 'numeric', 'between:-180,180'],
        ];
    }
}
