<?php

namespace App\Impor;

use App\Enums\AksiAktivitas;
use App\Support\PencatatAktivitas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Impor CSV dengan pratinjau.
 *
 * Pratinjau dan impor menjalankan langkah yang sama di dalam transaksi;
 * pratinjau membatalkannya (rollback) di akhir. Dengan begitu laporan per baris
 * pada pratinjau sama persis dengan yang terjadi saat impor, termasuk
 * pengaruh baris sebelumnya terhadap baris sesudahnya.
 *
 * @phpstan-type BarisHasil array{nomor: int, kunci: string|null, nama: string|null, tindakan: 'baru'|'perbarui'|'galat', galat: list<string>}
 * @phpstan-type Hasil array{galat_berkas: list<string>, jumlah: array{total: int, baru: int, perbarui: int, galat: int}, baris: list<BarisHasil>}
 */
abstract class PengimporCsv
{
    public const array JENIS = ['opd', 'perangkat'];

    public static function untuk(string $jenis): self
    {
        return match ($jenis) {
            'opd' => new PengimporOpd,
            'perangkat' => new PengimporPerangkat,
            default => throw new InvalidArgumentException("Jenis impor tidak dikenal: {$jenis}"),
        };
    }

    abstract public function jenis(): string;

    abstract public function judul(): string;

    /**
     * @return class-string<Model>
     */
    abstract public function kelasModel(): string;

    /**
     * Kolom CSV, berurutan seperti di templat.
     *
     * @return array<string, array{wajib: bool, contoh: string, keterangan: string}>
     */
    abstract public function kolom(): array;

    /**
     * Kolom yang menjadi kunci: baris dengan kunci yang sudah ada memperbarui data.
     */
    abstract public function kolomKunci(): string;

    /**
     * Kolom CSV yang disimpan ke atribut model dengan nama berbeda.
     *
     * @return array<string, string>
     */
    protected function petaKolom(): array
    {
        return [];
    }

    /**
     * Memvalidasi satu baris terhadap keadaan database saat ini.
     *
     * @param  array<string, string>  $nilai
     * @return array{data: array<string, mixed>, model: Model, galat: list<string>}
     */
    abstract protected function periksa(array $nilai): array;

    /**
     * @return Hasil
     */
    public function pratinjau(string $jalur): array
    {
        return $this->proses($jalur, simpan: false);
    }

    /**
     * Menyimpan baris yang valid; baris yang bermasalah dilewati dan dilaporkan.
     *
     * @return Hasil
     */
    public function impor(string $jalur): array
    {
        return $this->proses($jalur, simpan: true);
    }

    public function templat(): string
    {
        $kolom = $this->kolom();
        $berkas = fopen('php://temp', 'r+');

        if ($berkas === false) {
            return '';
        }

        fputcsv($berkas, array_keys($kolom), ',', '"', '');
        fputcsv($berkas, array_column($kolom, 'contoh'), ',', '"', '');
        rewind($berkas);

        // BOM agar Excel membaca UTF-8 dengan benar.
        return "\xEF\xBB\xBF".stream_get_contents($berkas);
    }

    /**
     * @return Hasil
     */
    private function proses(string $jalur, bool $simpan): array
    {
        $kolom = $this->kolom();
        $bacaan = PembacaCsv::baca(
            $jalur,
            array_keys($kolom),
            array_keys(array_filter($kolom, fn (array $k) => $k['wajib'])),
        );

        $hasil = [
            'galat_berkas' => $bacaan['galat'],
            'jumlah' => ['total' => 0, 'baru' => 0, 'perbarui' => 0, 'galat' => 0],
            'baris' => [],
        ];

        if ($bacaan['baris'] === []) {
            return $hasil;
        }

        DB::beginTransaction();

        try {
            $terlihat = [];

            foreach ($bacaan['baris'] as $nomor => $nilai) {
                $baris = is_string($nilai)
                    ? ['nomor' => $nomor, 'kunci' => null, 'nama' => null, 'tindakan' => 'galat', 'galat' => [$nilai]]
                    : $this->prosesBaris($nomor, $nilai, $terlihat);

                $hasil['baris'][] = $baris;
                $hasil['jumlah']['total']++;
                $hasil['jumlah'][$baris['tindakan']]++;
            }

            if ($simpan) {
                app(PencatatAktivitas::class)->catat(AksiAktivitas::ImporCsv, rincian: [
                    'jenis' => $this->jenis(),
                    ...$hasil['jumlah'],
                ]);

                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $hasil;
    }

    /**
     * @param  array<string, string>  $nilai
     * @param  array<string, int>  $terlihat  Kunci yang sudah muncul di berkas beserta nomor barisnya.
     * @return BarisHasil
     */
    private function prosesBaris(int $nomor, array $nilai, array &$terlihat): array
    {
        $periksa = $this->periksa($nilai);
        $kunci = $periksa['data'][$this->kolomKunci()] ?? null;
        $kunci = is_string($kunci) ? $kunci : null;
        $galat = $periksa['galat'];
        $model = $periksa['model'];

        if ($kunci !== null && isset($terlihat[$kunci])) {
            $galat[] = "Kunci {$kunci} sudah muncul di baris {$terlihat[$kunci]}.";
        } elseif ($kunci !== null) {
            $terlihat[$kunci] = $nomor;
        }

        $tindakan = $model->exists ? 'perbarui' : 'baru';

        if ($galat === []) {
            $data = $periksa['data'];

            if ($model->exists) {
                // Saat memperbarui, kolom yang tidak ada di berkas dibiarkan apa adanya.
                $adaDiBerkas = array_map(fn (string $kolom) => $this->petaKolom()[$kolom] ?? $kolom, array_keys($nilai));
                $data = array_intersect_key($data, array_flip($adaDiBerkas));
            }

            try {
                // Savepoint: baris yang ditolak database tidak membatalkan baris lain.
                DB::transaction(fn () => $model->fill($data)->save());
            } catch (QueryException) {
                $galat[] = 'Ditolak oleh database karena melanggar aturan integritas data.';
            }
        }

        return [
            'nomor' => $nomor,
            'kunci' => $kunci,
            'nama' => is_string($periksa['data']['nama'] ?? null) ? $periksa['data']['nama'] : null,
            'tindakan' => $galat === [] ? $tindakan : 'galat',
            'galat' => $galat,
        ];
    }
}
