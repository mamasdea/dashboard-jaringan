<?php

namespace App\Fo;

use App\Models\KoneksiEndpoint;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;

/**
 * Menjawab "bila kabel, titik, atau tiang ini terganggu, OPD mana yang
 * memakainya?" dari inventaris saja.
 *
 * Hasilnya daftar OPD yang berpotensi terdampak, bukan diagnosis: tidak
 * memakai hasil monitoring dan tidak menyatakan aset itu rusak.
 */
class AnalisisDampak
{
    public const string LABEL = 'Potensi terdampak berdasarkan inventaris';

    public const string CATATAN = 'Daftar ini disusun dari hubungan FO yang tercatat, bukan dari pengukuran. '
        .'Hanya sambungan FO langsung yang ditelusuri; OPD yang terhubung di belakang perangkat lain tidak ikut terhitung.';

    public function __construct(private readonly PenelusuranJalur $penelusuran) {}

    public function untuk(SegmenKabel|TitikJaringan|Tiang $aset): HasilDampak
    {
        $keterbatasan = [];
        $portLepas = [];

        if ($aset instanceof SegmenKabel) {
            $segmen = [$aset];
        } elseif ($aset instanceof TitikJaringan) {
            $segmen = $aset->segmen()->orderBy('kode')->get()->all();
            $portLepas = $this->penelusuran->portTanpaCoreDi($aset);

            if ($segmen === []) {
                $keterbatasan[] = "Belum ada kabel yang dicatat berujung di {$aset->kode}.";
            }
        } else {
            $titik = $aset->titikJaringan()->get();
            $segmen = $aset->segmen()->get()
                ->concat($titik->flatMap(fn (TitikJaringan $t) => $t->segmen()->get()))
                ->unique('id')->sortBy('kode')->values()->all();

            foreach ($titik as $t) {
                array_push($portLepas, ...$this->penelusuran->portTanpaCoreDi($t));
            }

            if ($segmen === []) {
                $keterbatasan[] = "Belum ada kabel yang dicatat melewati {$aset->kode}.";
            }

            $tanpaRute = SegmenKabel::query()->whereDoesntHave('tiang')->count();

            if ($tanpaRute > 0) {
                $keterbatasan[] = "{$tanpaRute} segmen kabel belum memiliki rute tiang. Kabel itu mungkin melewati tiang ini tanpa tercatat.";
            }
        }

        /** @var array<string, Jalur> $jalur */
        $jalur = [];
        $tidakTerpakai = 0;

        foreach ($segmen as $s) {
            if ($s->kekurangan() !== []) {
                $keterbatasan[] = "Data kabel {$s->kode} belum lengkap: ".implode(', ', $s->kekurangan()).'.';
            }

            foreach ($s->core()->get() as $core) {
                $j = $this->penelusuran->melaluiCore($core);

                if ($j === null) {
                    $tidakTerpakai++;
                } else {
                    $jalur[$j->kunci()] ??= $j;
                }
            }
        }

        foreach ($portLepas as $port) {
            $j = $this->penelusuran->dariPort($port);
            $jalur[$j->kunci()] ??= $j;
        }

        /** @var array<int, array{opd: Opd, perangkat: array<int, Perangkat>, jalur: list<Jalur>, lengkap: bool}> $opd */
        $opd = [];
        $tanpaPerangkat = [];
        $sebelah = 0;

        foreach ($jalur as $j) {
            if ($j->perangkat() === []) {
                $tanpaPerangkat[] = $j;

                continue;
            }

            if (! $j->lengkap()) {
                $sebelah++;
            }

            foreach ($j->perangkat() as $perangkat) {
                $opd[$perangkat->opd_id] ??= ['opd' => $perangkat->opd, 'perangkat' => [], 'jalur' => [], 'lengkap' => true];
                $opd[$perangkat->opd_id]['perangkat'][$perangkat->id] = $perangkat;
                $opd[$perangkat->opd_id]['jalur'][] = $j;
                $opd[$perangkat->opd_id]['lengkap'] = $opd[$perangkat->opd_id]['lengkap'] && $j->lengkap();
            }
        }

        if ($tanpaPerangkat !== []) {
            $keterbatasan[] = count($tanpaPerangkat).' core terpakai tidak dapat ditelusuri sampai perangkat mana pun. OPD yang memakainya tidak dapat ditentukan dari inventaris.';
        }

        if ($sebelah > 0) {
            $keterbatasan[] = "{$sebelah} jalur tidak tertelusur penuh dari ujung ke ujung. Lihat rincian jalurnya.";
        }

        $belumTerhubung = Opd::query()
            ->whereHas('perangkat', fn ($query) => $query->where('switch_utama', true)
                ->whereNotIn('perangkat.id', KoneksiEndpoint::query()->join('port_perangkat', 'port_perangkat.id', '=', 'koneksi_endpoint.port_perangkat_id')->select('port_perangkat.perangkat_id')))
            ->count();

        if ($belumTerhubung > 0) {
            $keterbatasan[] = "{$belumTerhubung} OPD memiliki switch utama yang belum dicatat terhubung ke FO. OPD itu tidak dapat muncul di daftar ini walaupun mungkin terdampak.";
        }

        usort($opd, fn (array $a, array $b) => strcasecmp($a['opd']->nama, $b['opd']->nama));

        return new HasilDampak(
            $aset,
            array_values($segmen),
            array_map(fn (array $baris) => [...$baris, 'perangkat' => array_values($baris['perangkat'])], $opd),
            $tanpaPerangkat,
            $tidakTerpakai,
            $keterbatasan,
        );
    }
}
