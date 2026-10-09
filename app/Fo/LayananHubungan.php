<?php

namespace App\Fo;

use App\Enums\AksiHubungan;
use App\Enums\JenisHubungan;
use App\Enums\StatusPemakaian;
use App\Models\CoreKabel;
use App\Models\KoneksiEndpoint;
use App\Models\PortPerangkat;
use App\Models\PortTerminasi;
use App\Models\RiwayatHubungan;
use App\Models\Sambungan;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya pintu untuk mengubah hubungan jaringan FO: sambungan core,
 * terminasi ke port, koneksi endpoint, dan rute tiang.
 *
 * Setiap perubahan dijalankan dalam transaksi, memperbarui status core/port,
 * dan menambah satu baris riwayat. Pemakaian ganda ditolak di sini dengan
 * pesan yang jelas, dan sekali lagi oleh database (pemakaian_ujung_core)
 * bila dua perubahan terjadi bersamaan.
 */
class LayananHubungan
{
    public function sambung(TitikJaringan $titik, CoreKabel $masuk, CoreKabel $keluar, ?string $catatan = null): Sambungan
    {
        if ($masuk->is($keluar)) {
            $this->gagal('Core masuk dan core keluar tidak boleh sama.');
        }

        $this->pastikanUjungCoreBebas($masuk, $titik);
        $this->pastikanUjungCoreBebas($keluar, $titik);

        return $this->transaksi(function () use ($titik, $masuk, $keluar, $catatan): Sambungan {
            $sambungan = Sambungan::create([
                'titik_jaringan_id' => $titik->id,
                'core_masuk_id' => $masuk->id,
                'core_keluar_id' => $keluar->id,
                'catatan' => $catatan,
            ]);

            $this->segarkanStatusCore($masuk, $keluar);
            $this->catat(
                JenisHubungan::Sambungan, AksiHubungan::Dibuat, $titik, null,
                "{$masuk->label()} disambung ke {$keluar->label()} di {$titik->kode}",
                null, $this->potretSambungan($sambungan, $masuk, $keluar),
            );

            return $sambungan;
        });
    }

    public function lepasSambungan(Sambungan $sambungan): void
    {
        $masuk = $sambungan->coreMasuk;
        $keluar = $sambungan->coreKeluar;
        $titik = $sambungan->titik;

        $this->transaksi(function () use ($sambungan, $masuk, $keluar, $titik): void {
            $potret = $this->potretSambungan($sambungan, $masuk, $keluar);

            $sambungan->delete();

            $this->segarkanStatusCore($masuk, $keluar);
            $this->catat(
                JenisHubungan::Sambungan, AksiHubungan::Dihapus, $titik, null,
                "Sambungan {$masuk->label()} dengan {$keluar->label()} di {$titik->kode} dilepas",
                $potret, null,
            );
        });
    }

    public function terminasikan(PortTerminasi $port, CoreKabel $core): void
    {
        $titik = $port->titik;

        if ($port->core_id !== null) {
            $this->gagal("{$port->label()} sudah berisi core lain. Lepas dahulu sebelum mengganti.");
        }

        if ($port->status === StatusPemakaian::Rusak) {
            $this->gagal("{$port->label()} ditandai rusak.");
        }

        $this->pastikanUjungCoreBebas($core, $titik);

        $this->transaksi(function () use ($port, $core, $titik): void {
            $port->core_id = $core->id;
            $port->save();

            $this->segarkanStatusCore($core);
            $this->segarkanStatusPort($port);
            $this->catat(
                JenisHubungan::Terminasi, AksiHubungan::Dibuat, $titik, null,
                "{$core->label()} diterminasi ke {$port->label()}",
                null, ['port_terminasi_id' => $port->id, 'port' => $port->label(), 'core_id' => $core->id, 'core' => $core->label()],
            );
        });
    }

    public function lepasTerminasi(PortTerminasi $port): void
    {
        $core = $port->core;

        if ($core === null) {
            $this->gagal("{$port->label()} tidak berisi core.");
        }

        $this->transaksi(function () use ($port, $core): void {
            $port->core_id = null;
            $port->save();

            $this->segarkanStatusCore($core);
            $this->segarkanStatusPort($port);
            $this->catat(
                JenisHubungan::Terminasi, AksiHubungan::Dihapus, $port->titik, null,
                "Terminasi {$core->label()} di {$port->label()} dilepas",
                ['port_terminasi_id' => $port->id, 'port' => $port->label(), 'core_id' => $core->id, 'core' => $core->label()], null,
            );
        });
    }

    /**
     * Menghubungkan port perangkat ke port terminasi, atau langsung ke ujung
     * core di sebuah titik (tepat salah satu).
     */
    public function hubungkanEndpoint(
        PortPerangkat $portPerangkat,
        ?PortTerminasi $port = null,
        ?CoreKabel $core = null,
        ?TitikJaringan $titik = null,
        ?string $catatan = null,
    ): KoneksiEndpoint {
        if (($port === null) === ($core === null)) {
            $this->gagal('Pilih tepat satu sasaran: port terminasi atau core.');
        }

        if ($portPerangkat->koneksiEndpoint()->exists()) {
            $this->gagal("{$portPerangkat->label()} sudah terhubung ke tempat lain.");
        }

        if ($port !== null) {
            $titik = $port->titik;

            if ($port->status === StatusPemakaian::Rusak) {
                $this->gagal("{$port->label()} ditandai rusak.");
            }

            if ($port->koneksiEndpoint()->exists()) {
                $this->gagal("{$port->label()} sudah terhubung ke port perangkat lain.");
            }

            $sasaran = $port->label();
        } else {
            if ($titik === null) {
                $this->gagal('Koneksi langsung ke core membutuhkan titik jaringan.');
            }

            $this->pastikanUjungCoreBebas($core, $titik);

            $sasaran = "{$core->label()} di {$titik->kode}";
        }

        return $this->transaksi(function () use ($portPerangkat, $port, $core, $titik, $catatan, $sasaran): KoneksiEndpoint {
            $koneksi = KoneksiEndpoint::create([
                'port_perangkat_id' => $portPerangkat->id,
                'port_terminasi_id' => $port?->id,
                'core_id' => $core?->id,
                'titik_jaringan_id' => $core === null ? null : $titik->id,
                'catatan' => $catatan,
            ]);

            if ($core !== null) {
                $this->segarkanStatusCore($core);
            }

            if ($port !== null) {
                $this->segarkanStatusPort($port);
            }

            $this->catat(
                JenisHubungan::KoneksiEndpoint, AksiHubungan::Dibuat, $titik, null,
                "{$portPerangkat->label()} dihubungkan ke {$sasaran}",
                null, $this->potretEndpoint($koneksi, $portPerangkat, $sasaran),
            );

            return $koneksi;
        });
    }

    public function lepasEndpoint(KoneksiEndpoint $koneksi): void
    {
        $portPerangkat = $koneksi->portPerangkat;
        $port = $koneksi->portTerminasi;
        $core = $koneksi->core;
        $titik = $port !== null ? $port->titik : $koneksi->titik;
        $sasaran = $port !== null ? $port->label() : ($core?->label().' di '.$titik?->kode);

        $this->transaksi(function () use ($koneksi, $portPerangkat, $port, $core, $titik, $sasaran): void {
            $potret = $this->potretEndpoint($koneksi, $portPerangkat, $sasaran);

            $koneksi->delete();

            if ($core !== null) {
                $this->segarkanStatusCore($core);
            }

            if ($port !== null) {
                $this->segarkanStatusPort($port);
            }

            $this->catat(
                JenisHubungan::KoneksiEndpoint, AksiHubungan::Dihapus, $titik, null,
                "Koneksi {$portPerangkat->label()} dengan {$sasaran} dilepas",
                $potret, null,
            );
        });
    }

    /**
     * Menetapkan tiang yang dilalui segmen, berurutan dari titik awal.
     *
     * @param  list<int>  $tiangIds
     */
    public function aturRuteTiang(SegmenKabel $segmen, array $tiangIds): void
    {
        if (count($tiangIds) !== count(array_unique($tiangIds))) {
            $this->gagal('Satu tiang tidak dapat muncul dua kali dalam rute segmen yang sama.');
        }

        $kode = Tiang::query()->whereKey($tiangIds)->pluck('kode', 'id');

        if ($kode->count() !== count($tiangIds)) {
            $this->gagal('Ada tiang dalam rute yang tidak ditemukan.');
        }

        $sebelum = $segmen->tiang()->pluck('kode')->all();
        $sesudah = array_map(fn (int $id): string => $kode[$id], $tiangIds);

        if ($sebelum === $sesudah) {
            return;
        }

        $this->transaksi(function () use ($segmen, $tiangIds, $sebelum, $sesudah): void {
            // Hapus lalu isi ulang: urutan unik per segmen, sehingga penukaran
            // posisi tidak dapat dilakukan baris demi baris.
            DB::table('tiang_segmen')->where('segmen_kabel_id', $segmen->id)->delete();
            DB::table('tiang_segmen')->insert(array_map(
                fn (int $id, int $indeks): array => ['segmen_kabel_id' => $segmen->id, 'tiang_id' => $id, 'urutan' => $indeks + 1],
                $tiangIds,
                array_keys($tiangIds),
            ));

            $this->catat(
                JenisHubungan::RuteTiang,
                $sebelum === [] ? AksiHubungan::Dibuat : ($sesudah === [] ? AksiHubungan::Dihapus : AksiHubungan::Diubah),
                null, $segmen,
                "Rute tiang {$segmen->kode}: ".($sesudah === [] ? 'dikosongkan' : implode(' → ', $sesudah)),
                ['tiang' => $sebelum], ['tiang' => $sesudah],
            );
        });

        $segmen->unsetRelation('tiang');
    }

    /**
     * Menandai core rusak, atau memulihkannya ke status yang diturunkan dari
     * hubungan yang tercatat.
     */
    public function tandaiCore(CoreKabel $core, bool $rusak): void
    {
        $core->status = $rusak ? StatusPemakaian::Rusak : StatusPemakaian::Tersedia;
        $core->save();

        $this->segarkanStatusCore($core);
    }

    public function tandaiPort(PortTerminasi $port, bool $rusak): void
    {
        $port->status = $rusak ? StatusPemakaian::Rusak : StatusPemakaian::Tersedia;
        $port->save();

        $this->segarkanStatusPort($port);
    }

    public function ujungCoreTerpakai(CoreKabel $core, TitikJaringan $titik): bool
    {
        return DB::table('pemakaian_ujung_core')
            ->where('core_id', $core->id)
            ->where('titik_jaringan_id', $titik->id)
            ->exists();
    }

    private function pastikanUjungCoreBebas(CoreKabel $core, TitikJaringan $titik): void
    {
        if (! $core->segmen->berujungDi($titik)) {
            $this->gagal("Kabel {$core->segmen->kode} tidak berujung di {$titik->kode}.");
        }

        if ($core->status === StatusPemakaian::Rusak) {
            $this->gagal("{$core->label()} ditandai rusak.");
        }

        if ($this->ujungCoreTerpakai($core, $titik)) {
            $this->gagal("{$core->label()} sudah dipakai di {$titik->kode}. Lepas hubungan yang ada dahulu.");
        }
    }

    private function segarkanStatusCore(CoreKabel ...$daftar): void
    {
        foreach ($daftar as $core) {
            if ($core->status === StatusPemakaian::Rusak) {
                continue;
            }

            $terpakai = DB::table('pemakaian_ujung_core')->where('core_id', $core->id)->exists();

            $core->status = $terpakai ? StatusPemakaian::Terpakai : StatusPemakaian::Tersedia;
            $core->save();
        }
    }

    private function segarkanStatusPort(PortTerminasi $port): void
    {
        if ($port->status === StatusPemakaian::Rusak) {
            return;
        }

        $terpakai = $port->core_id !== null || $port->koneksiEndpoint()->exists();

        $port->status = $terpakai ? StatusPemakaian::Terpakai : StatusPemakaian::Tersedia;
        $port->save();
    }

    /**
     * @param  array<string, mixed>|null  $sebelum
     * @param  array<string, mixed>|null  $sesudah
     */
    private function catat(
        JenisHubungan $jenis,
        AksiHubungan $aksi,
        ?TitikJaringan $titik,
        ?SegmenKabel $segmen,
        string $ringkasan,
        ?array $sebelum,
        ?array $sesudah,
    ): void {
        $pelaku = Auth::user();

        RiwayatHubungan::create([
            'waktu' => now(),
            'pelaku_id' => $pelaku?->id,
            'pelaku_nama' => $pelaku?->name,
            'jenis' => $jenis,
            'aksi' => $aksi,
            'titik_jaringan_id' => $titik?->id,
            'segmen_kabel_id' => $segmen?->id,
            'ringkasan' => $ringkasan,
            'sebelum' => $sebelum,
            'sesudah' => $sesudah,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function potretSambungan(Sambungan $sambungan, CoreKabel $masuk, CoreKabel $keluar): array
    {
        return [
            'sambungan_id' => $sambungan->id,
            'core_masuk_id' => $masuk->id,
            'core_masuk' => $masuk->label(),
            'core_keluar_id' => $keluar->id,
            'core_keluar' => $keluar->label(),
            'catatan' => $sambungan->catatan,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function potretEndpoint(KoneksiEndpoint $koneksi, PortPerangkat $portPerangkat, string $sasaran): array
    {
        return [
            'koneksi_endpoint_id' => $koneksi->id,
            'port_perangkat_id' => $portPerangkat->id,
            'port_perangkat' => $portPerangkat->label(),
            'port_terminasi_id' => $koneksi->port_terminasi_id,
            'core_id' => $koneksi->core_id,
            'sasaran' => $sasaran,
            'catatan' => $koneksi->catatan,
        ];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $aksi
     * @return T
     */
    private function transaksi(Closure $aksi): mixed
    {
        try {
            return DB::transaction($aksi);
        } catch (UniqueConstraintViolationException) {
            $this->gagal('Core atau port tersebut baru saja dipakai oleh hubungan lain. Muat ulang halaman lalu coba lagi.');
        }
    }

    private function gagal(string $pesan): never
    {
        throw ValidationException::withMessages(['hubungan' => $pesan]);
    }
}
