<?php

namespace App\Laporan;

use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Jumlah aset dan kelengkapan pendataannya, keadaan saat laporan dibuat.
 * Tidak memuat IP manajemen, nomor seri, atau kontak: laporan dapat dibuka
 * peran yang tidak berhak melihat rincian inventaris.
 */
class LaporanInventaris
{
    public const array CATATAN = [
        'Keadaan saat laporan dibuat; tidak bergantung pada periode.',
        'Tiang, titik jaringan, dan segmen kabel: "perlu dilengkapi" mengikuti status pendataan bertahap di inventaris.',
        'OPD dianggap lengkap bila koordinat dan nama kontak teknisnya terisi.',
        'Perangkat dianggap lengkap bila merek, model, dan nomor seri terisi; switch utama juga membutuhkan IP manajemen dan port uplink.',
        'Laporan ini hanya menyebut apa yang belum diisi. Nilainya (IP, nomor seri, kontak) tidak dicantumkan.',
    ];

    public function buat(): DokumenLaporan
    {
        /** @var list<array{jenis: string, kode: string, nama: string, kekurangan: list<string>, simulasi: bool}> $objek */
        $objek = [];

        foreach (Opd::query()->orderBy('kode')->get() as $o) {
            $objek[] = ['jenis' => 'OPD', 'kode' => $o->kode, 'nama' => $o->nama, 'simulasi' => $o->simulasi, 'kekurangan' => array_keys(array_filter([
                'koordinat' => $o->lintang === null || $o->bujur === null,
                'kontak teknis' => blank($o->kontak_teknis_nama),
            ]))];
        }

        foreach (Perangkat::query()->orderBy('kode_aset')->get() as $p) {
            $objek[] = ['jenis' => 'Perangkat', 'kode' => $p->kode_aset, 'nama' => $p->nama, 'simulasi' => $p->simulasi, 'kekurangan' => array_keys(array_filter([
                'merek' => blank($p->merek),
                'model' => blank($p->model),
                'nomor seri' => blank($p->nomor_seri),
                'IP manajemen' => $p->switch_utama && $p->ip_manajemen === null,
                'port uplink' => $p->switch_utama && blank($p->port_uplink),
            ]))];
        }

        foreach ([['Tiang', Tiang::class], ['Titik jaringan', TitikJaringan::class], ['Segmen kabel', SegmenKabel::class]] as [$jenis, $model]) {
            foreach ($model::query()->orderBy('kode')->get() as $m) {
                $objek[] = ['jenis' => $jenis, 'kode' => $m->kode, 'nama' => (string) ($m->nama ?? ''), 'simulasi' => $m->simulasi, 'kekurangan' => $m->kekurangan()];
            }
        }

        $jumlah = [];

        foreach ($objek as $o) {
            $jumlah[$o['jenis']] ??= ['semua' => 0, 'lengkap' => 0, 'simulasi' => 0];
            $jumlah[$o['jenis']]['semua']++;
            $jumlah[$o['jenis']]['lengkap'] += $o['kekurangan'] === [] ? 1 : 0;
            $jumlah[$o['jenis']]['simulasi'] += $o['simulasi'] ? 1 : 0;
        }

        $semua = count($objek);
        $lengkap = count(array_filter($objek, fn (array $o) => $o['kekurangan'] === []));
        $core = DB::table('core_kabel')->selectRaw('status, count(*) AS jumlah')->groupBy('status')->pluck('jumlah', 'status');
        $port = DB::table('port_terminasi')->selectRaw('status, count(*) AS jumlah')->groupBy('status')->pluck('jumlah', 'status');

        return new DokumenLaporan(
            'inventaris',
            'Inventaris Aset dan Kelengkapan Pendataan',
            null,
            CarbonImmutable::now()->utc(),
            [
                'Jumlah objek' => (string) $semua,
                'Lengkap' => (string) $lengkap,
                'Perlu dilengkapi' => (string) ($semua - $lengkap),
                'Kelengkapan' => DokumenLaporan::persen($semua === 0 ? null : $lengkap / $semua * 100),
                'Data SIMULASI di dalamnya' => (string) count(array_filter($objek, fn (array $o) => $o['simulasi'])),
            ],
            [
                new BagianLaporan(
                    'Jumlah aset per jenis',
                    ['Jenis', 'Jumlah', 'Lengkap', 'Perlu dilengkapi', 'Kelengkapan', 'Di antaranya SIMULASI'],
                    array_map(fn (string $jenis, array $j) => [
                        $jenis, (string) $j['semua'], (string) $j['lengkap'], (string) ($j['semua'] - $j['lengkap']),
                        DokumenLaporan::persen($j['lengkap'] / $j['semua'] * 100), (string) $j['simulasi'],
                    ], array_keys($jumlah), array_values($jumlah)),
                    'Belum ada aset yang didata.',
                ),
                new BagianLaporan(
                    'Pemakaian core dan port terminasi',
                    ['Objek', 'Jumlah', 'Terpakai', 'Tersedia', 'Rusak'],
                    array_map(fn (string $nama, $hitung) => [
                        $nama, (string) $hitung->sum(), (string) ($hitung['terpakai'] ?? 0), (string) ($hitung['tersedia'] ?? 0), (string) ($hitung['rusak'] ?? 0),
                    ], ['Core kabel', 'Port terminasi'], [$core, $port]),
                ),
                new BagianLaporan(
                    'Objek yang perlu dilengkapi',
                    ['Jenis', 'Kode', 'Nama', 'Yang belum diisi'],
                    array_values(array_map(
                        fn (array $o) => [$o['jenis'], $o['kode'], $o['nama'], implode(', ', $o['kekurangan'])],
                        array_filter($objek, fn (array $o) => $o['kekurangan'] !== []),
                    )),
                    'Semua objek sudah lengkap.',
                ),
            ],
            self::CATATAN,
        );
    }
}
