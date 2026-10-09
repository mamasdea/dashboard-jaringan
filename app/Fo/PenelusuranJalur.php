<?php

namespace App\Fo;

use App\Models\CoreKabel;
use App\Models\KoneksiEndpoint;
use App\Models\Perangkat;
use App\Models\PortPerangkat;
use App\Models\PortTerminasi;
use App\Models\Sambungan;
use App\Models\SegmenKabel;
use App\Models\TitikJaringan;

/**
 * Menelusuri jalur serat lewat hubungan yang tercatat: port perangkat →
 * port terminasi atau core → kabel → sambungan → ... → port perangkat.
 *
 * Hanya membaca inventaris. Tidak menghubungi perangkat dan tidak memakai
 * hasil monitoring, sehingga tidak mengatakan apa pun tentang keadaan serat.
 * Di tempat data berhenti, penelusuran berhenti dan mencatat keterbatasannya.
 */
class PenelusuranJalur
{
    private bool $termuat = false;

    /** @var array<int, CoreKabel> */
    private array $core = [];

    /** @var array<int, SegmenKabel> */
    private array $segmen = [];

    /** @var array<int, TitikJaringan> */
    private array $titik = [];

    /** @var array<string, Sambungan> Kunci "core:titik". */
    private array $sambungan = [];

    /** @var array<string, PortTerminasi> Kunci "core:titik". */
    private array $portCore = [];

    /** @var array<string, KoneksiEndpoint> Kunci "core:titik". */
    private array $koneksiCore = [];

    /** @var array<int, KoneksiEndpoint> Per id port terminasi. */
    private array $koneksiPort = [];

    /** @var array<int, KoneksiEndpoint> Per id port perangkat. */
    private array $koneksiPerangkat = [];

    /**
     * Semua jalur yang berangkat dari port perangkat ini yang tercatat.
     *
     * @return array{jalur: list<Jalur>, keterbatasan: list<string>}
     */
    public function dariPerangkat(Perangkat $perangkat): array
    {
        $port = PortPerangkat::query()->where('perangkat_id', $perangkat->id)->orderBy('nama')->get();

        if ($port->isEmpty()) {
            return ['jalur' => [], 'keterbatasan' => ["Belum ada port {$perangkat->kode_aset} yang dicatat terhubung ke FO, sehingga jalurnya tidak dapat ditelusuri."]];
        }

        return ['jalur' => array_values($port->map(fn (PortPerangkat $p) => $this->dariPort($p->setRelation('perangkat', $perangkat)))->all()), 'keterbatasan' => []];
    }

    public function dariPort(PortPerangkat $port): Jalur
    {
        $this->muat();

        // Memakai objek yang sudah dimuat bersama perangkat dan OPD-nya.
        $koneksi = $this->koneksiPerangkat[$port->id] ?? null;
        $port = $koneksi !== null ? $koneksi->portPerangkat : $port;

        $jejak = new JejakJalur;
        $jejak->langkah(Jalur::PERANGKAT, $port->label(), $port->perangkat->nama);

        if ($koneksi === null) {
            $jejak->batas("{$port->label()} belum dihubungkan ke port terminasi atau core.");

            return $jejak->jadi($port, null);
        }

        if ($koneksi->port_terminasi_id !== null) {
            $terminasi = $koneksi->portTerminasi;
            $titikId = $terminasi->titik_jaringan_id;
            $jejak->titik($this->titik[$titikId]);
            $jejak->langkah(Jalur::PORT, $terminasi->label());

            $core = $terminasi->core_id === null ? null : ($this->core[$terminasi->core_id] ?? null);

            if ($core === null) {
                $jejak->batas("{$terminasi->label()} belum diterminasi ke core mana pun.");

                return $jejak->jadi($port, null);
            }
        } else {
            $titikId = (int) $koneksi->titik_jaringan_id;
            $core = $this->core[(int) $koneksi->core_id];
            $jejak->titik($this->titik[$titikId]);
        }

        $jejak->core($core);

        return $jejak->jadi($port, $this->ikuti($core, $this->seberang($core, $titikId, $jejak), $jejak));
    }

    /**
     * Jalur yang melewati core ini, ditelusuri ke dua arah. Null bila core
     * tidak dipakai di ujung mana pun.
     */
    public function melaluiCore(CoreKabel $core): ?Jalur
    {
        $this->muat();

        $core = $this->core[$core->id] ?? null;

        if ($core === null) {
            return null;
        }

        $segmen = $core->segmen;
        $ujung = array_filter([$segmen->titik_awal_id, $segmen->titik_akhir_id], fn (?int $id) => $id !== null);

        if (array_filter($ujung, fn (int $id) => $this->terpasang($core, $id)) === []) {
            return null;
        }

        $awal = new JejakJalur;
        $awal->catatCore($core);
        $akhir = new JejakJalur($awal);

        foreach ([[$segmen->titik_awal_id, 'awal', $awal], [$segmen->titik_akhir_id, 'akhir', $akhir]] as [$titikId, $sisi, $jejak]) {
            if ($titikId === null) {
                $jejak->batas("Titik {$sisi} kabel {$segmen->kode} belum dicatat.");
            }
        }

        $ujungAwal = $this->ikuti($core, $segmen->titik_awal_id, $awal);
        $ujungAkhir = $this->ikuti($core, $segmen->titik_akhir_id, $akhir);

        return $awal->gabung($this->langkahCore($core), $akhir, $ujungAwal, $ujungAkhir);
    }

    /**
     * Mengikuti apa yang terpasang pada $core di titik itu, terus sampai port
     * perangkat atau sampai data habis.
     */
    private function ikuti(CoreKabel $core, ?int $titikId, JejakJalur $jejak): ?PortPerangkat
    {
        while ($titikId !== null) {
            $titik = $this->titik[$titikId];
            $jejak->titik($titik);
            $kunci = "{$core->id}:{$titikId}";

            if (($sambungan = $this->sambungan[$kunci] ?? null) !== null) {
                $lanjut = $this->core[$sambungan->core_masuk_id === $core->id ? $sambungan->core_keluar_id : $sambungan->core_masuk_id];
                $jejak->langkah(Jalur::SAMBUNGAN, "Sambungan di {$titik->kode}", "{$core->label()} ↔ {$lanjut->label()}");

                if ($jejak->sudahLewat($lanjut)) {
                    $jejak->batas("Sambungan di {$titik->kode} mengarah kembali ke {$lanjut->label()}; data sambungan perlu diperiksa.");

                    return null;
                }

                $jejak->core($lanjut);
                $core = $lanjut;
                $titikId = $this->seberang($core, $titikId, $jejak);

                continue;
            }

            if (($terminasi = $this->portCore[$kunci] ?? null) !== null) {
                $jejak->langkah(Jalur::PORT, $terminasi->label());
                $koneksi = $this->koneksiPort[$terminasi->id] ?? null;

                if ($koneksi === null) {
                    $jejak->batas("{$terminasi->label()} belum dihubungkan ke port perangkat.");

                    return null;
                }

                return $this->sampai($koneksi, $jejak);
            }

            if (($koneksi = $this->koneksiCore[$kunci] ?? null) !== null) {
                return $this->sampai($koneksi, $jejak);
            }

            $jejak->batas("Ujung {$core->label()} di {$titik->kode} belum dicatat: tidak ada sambungan, terminasi, atau koneksi perangkat.");

            return null;
        }

        return null;
    }

    private function sampai(KoneksiEndpoint $koneksi, JejakJalur $jejak): PortPerangkat
    {
        $port = $koneksi->portPerangkat;
        $jejak->langkah(Jalur::PERANGKAT, $port->label(), $port->perangkat->nama);

        return $port;
    }

    /**
     * Ujung kabel di seberang titik ini. Null bila tidak dapat ditentukan;
     * sebabnya dicatat di jejak.
     */
    private function seberang(CoreKabel $core, int $titikId, JejakJalur $jejak): ?int
    {
        $segmen = $core->segmen;

        if ($segmen->titik_awal_id !== $titikId && $segmen->titik_akhir_id !== $titikId) {
            $jejak->batas("{$this->titik[$titikId]->kode} dicatat memakai {$core->label()}, tetapi bukan ujung kabel {$segmen->kode}.");

            return null;
        }

        $seberang = $segmen->titik_awal_id === $titikId ? $segmen->titik_akhir_id : $segmen->titik_awal_id;

        if ($seberang === null) {
            $jejak->batas("Ujung lain kabel {$segmen->kode} belum dicatat.");
        }

        return $seberang;
    }

    private function terpasang(CoreKabel $core, int $titikId): bool
    {
        $kunci = "{$core->id}:{$titikId}";

        return isset($this->sambungan[$kunci]) || isset($this->portCore[$kunci]) || isset($this->koneksiCore[$kunci]);
    }

    /**
     * @return array{jenis: string, label: string, rinci: string|null}
     */
    private function langkahCore(CoreKabel $core): array
    {
        return JejakJalur::langkahCore($core);
    }

    /**
     * Port terminasi di titik ini yang terhubung ke perangkat tetapi belum
     * diterminasi ke core.
     *
     * @return list<PortPerangkat>
     */
    public function portTanpaCoreDi(TitikJaringan $titik): array
    {
        $this->muat();

        $daftar = [];

        foreach ($this->koneksiPort as $koneksi) {
            $terminasi = $koneksi->portTerminasi;

            if ($terminasi->titik_jaringan_id === $titik->id && $terminasi->core_id === null) {
                $daftar[] = $koneksi->portPerangkat;
            }
        }

        return $daftar;
    }

    /**
     * Seluruh hubungan dimuat sekali per objek: penelusuran banyak jalur
     * (analisis dampak) tidak menambah kueri.
     */
    private function muat(): void
    {
        if ($this->termuat) {
            return;
        }

        $this->termuat = true;

        // Tanpa jalur_peta: tidak dipakai di sini dan bisa besar.
        $this->segmen = SegmenKabel::query()->get(['id', 'kode', 'nama', 'klasifikasi', 'titik_awal_id', 'titik_akhir_id', 'simulasi'])->keyBy('id')->all();
        $this->titik = TitikJaringan::query()->get(['id', 'kode', 'nama', 'jenis', 'tiang_id', 'opd_id', 'simulasi'])->keyBy('id')->all();

        foreach (CoreKabel::query()->get(['id', 'segmen_kabel_id', 'nomor', 'warna']) as $core) {
            $this->core[$core->id] = $core->setRelation('segmen', $this->segmen[$core->segmen_kabel_id]);
        }

        foreach (Sambungan::query()->get() as $sambungan) {
            $this->sambungan["{$sambungan->core_masuk_id}:{$sambungan->titik_jaringan_id}"] = $sambungan;
            $this->sambungan["{$sambungan->core_keluar_id}:{$sambungan->titik_jaringan_id}"] = $sambungan;
        }

        $port = [];

        foreach (PortTerminasi::query()->whereNotNull('core_id')->orWhereHas('koneksiEndpoint')->get() as $terminasi) {
            $terminasi->setRelation('titik', $this->titik[$terminasi->titik_jaringan_id]);
            $port[$terminasi->id] = $terminasi;

            if ($terminasi->core_id !== null) {
                $this->portCore["{$terminasi->core_id}:{$terminasi->titik_jaringan_id}"] = $terminasi;
            }
        }

        foreach (KoneksiEndpoint::query()->with('portPerangkat.perangkat.opd')->get() as $koneksi) {
            $this->koneksiPerangkat[$koneksi->port_perangkat_id] = $koneksi;

            if ($koneksi->port_terminasi_id !== null) {
                $koneksi->setRelation('portTerminasi', $port[(int) $koneksi->port_terminasi_id]);
                $this->koneksiPort[(int) $koneksi->port_terminasi_id] = $koneksi;
            } else {
                $this->koneksiCore["{$koneksi->core_id}:{$koneksi->titik_jaringan_id}"] = $koneksi;
            }
        }
    }
}
