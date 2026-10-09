<?php

namespace App\Http\Controllers;

use App\Enums\StatusData;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use App\Support\TautanAset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data peta jaringan untuk Leaflet. Dijaga middleware `can:lihat-aset`.
 *
 * Sengaja tidak memuat IP manajemen, nomor seri, kontak, maupun catatan:
 * peta hanya butuh identitas dan posisi. Rinciannya ada di halaman detail,
 * yang memeriksa hak akses sendiri.
 */
class PetaDataController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $opd = Opd::query()->orderBy('kode')->get();
        $tiang = Tiang::query()->orderBy('kode')->get();
        $titik = TitikJaringan::query()->with(['tiang:id,lintang,bujur', 'opd:id,lintang,bujur'])->orderBy('kode')->get();
        $segmen = SegmenKabel::query()->with(['titikAwal:id,kode,opd_id', 'titikAkhir:id,kode,opd_id'])->orderBy('kode')->get();

        // OPD yang dilayani tiap segmen (dari titik ujungnya), lalu diturunkan ke tiang yang dilaluinya.
        $opdSegmen = $segmen->mapWithKeys(fn (SegmenKabel $s) => [
            $s->id => array_values(array_unique(array_filter([$s->titikAwal?->opd_id, $s->titikAkhir?->opd_id]))),
        ]);
        $opdTiang = DB::table('tiang_segmen')->get(['tiang_id', 'segmen_kabel_id'])
            ->groupBy('tiang_id')
            ->map(fn (Collection $baris) => $baris->flatMap(fn ($b) => $opdSegmen[$b->segmen_kabel_id] ?? [])->unique()->values()->all());

        return response()->json([
            'opd' => $opd->map(fn (Opd $o) => [
                'id' => $o->id,
                'kode' => $o->kode,
                'nama' => $o->nama,
                ...$this->posisi($o->lintang, $o->bujur),
                'keterangan' => $o->aktif ? 'OPD aktif' : 'OPD nonaktif',
                'opd_ids' => [$o->id],
                'simulasi' => $o->simulasi,
                'url' => TautanAset::jalur($o),
            ])->all(),

            // Perangkat tidak punya koordinat sendiri; switch utama digambar di lokasi OPD-nya.
            'perangkat' => Perangkat::query()->with('opd:id,kode,lintang,bujur')->where('switch_utama', true)->orderBy('kode_aset')->get()
                ->map(fn (Perangkat $p) => [
                    'id' => $p->id,
                    'kode' => $p->kode_aset,
                    'nama' => $p->nama,
                    ...$this->posisi($p->opd->lintang, $p->opd->bujur),
                    'keterangan' => 'Switch utama '.$p->opd->kode.' · monitoring '.($p->monitoring_aktif ? 'diaktifkan' : 'tidak diaktifkan'),
                    'opd_ids' => [$p->opd_id],
                    'simulasi' => $p->simulasi,
                    'url' => TautanAset::jalur($p),
                ])->all(),

            'tiang' => $tiang->map(fn (Tiang $t) => [
                'id' => $t->id,
                'kode' => $t->kode,
                'nama' => null,
                ...$this->posisi($t->lintang, $t->bujur),
                'keterangan' => collect([$t->pemilik?->label(), $t->kondisi ? 'kondisi '.mb_strtolower($t->kondisi->label()) : null])->filter()->implode(' · ') ?: 'Pemilik dan kondisi belum didata',
                'opd_ids' => $opdTiang[$t->id] ?? [],
                'perlu_dilengkapi' => $t->status_data === StatusData::PerluDilengkapi,
                'simulasi' => $t->simulasi,
                'url' => TautanAset::jalur($t),
            ])->all(),

            'titik' => $titik->map(function (TitikJaringan $t): array {
                // Posisi sendiri, atau mengikuti tiang/OPD tempatnya terpasang.
                [$sumber, $lintang, $bujur] = match (true) {
                    $t->lintang !== null => ['sendiri', $t->lintang, $t->bujur],
                    $t->tiang?->lintang !== null => ['tiang', $t->tiang->lintang, $t->tiang->bujur],
                    $t->opd?->lintang !== null => ['opd', $t->opd->lintang, $t->opd->bujur],
                    default => [null, null, null],
                };

                return [
                    'id' => $t->id,
                    'kode' => $t->kode,
                    'nama' => $t->nama,
                    'jenis' => $t->jenis->value,
                    ...$this->posisi($lintang, $bujur),
                    'posisi_dari' => $sumber,
                    'keterangan' => $t->jenis->label().($t->jenis->berPort() && $t->kapasitas_port !== null ? ' · '.$t->kapasitas_port.' port' : ''),
                    'opd_ids' => $t->opd_id === null ? [] : [$t->opd_id],
                    'perlu_dilengkapi' => $t->status_data === StatusData::PerluDilengkapi,
                    'simulasi' => $t->simulasi,
                    'url' => TautanAset::jalur($t),
                ];
            })->all(),

            'segmen' => $segmen->map(fn (SegmenKabel $s) => [
                'id' => $s->id,
                'kode' => $s->kode,
                'nama' => $s->nama,
                'klasifikasi' => $s->klasifikasi?->value,
                // Leaflet memakai [lintang, bujur]; penyimpanan memakai [bujur, lintang].
                'jalur' => array_map(fn (array $titik) => [(float) $titik[1], (float) $titik[0]], $s->jalur_peta ?? []),
                'keterangan' => collect([
                    $s->klasifikasi?->label() ?? 'Klasifikasi belum ditentukan',
                    ($s->titikAwal->kode ?? '?').' → '.($s->titikAkhir->kode ?? '?'),
                    $s->jumlah_core !== null ? $s->jumlah_core.' core' : null,
                    $s->panjang_ukur_m !== null ? 'ukur '.number_format((float) $s->panjang_ukur_m, 0, ',', '.').' m' : null,
                    $s->panjang_peta_m !== null ? 'peta '.number_format((float) $s->panjang_peta_m, 0, ',', '.').' m' : null,
                ])->filter()->implode(' · '),
                'opd_ids' => $opdSegmen[$s->id],
                'perlu_dilengkapi' => $s->status_data === StatusData::PerluDilengkapi,
                'simulasi' => $s->simulasi,
                'url' => TautanAset::jalur($s),
            ])->all(),
        ]);
    }

    /**
     * @return array{lat: float|null, lng: float|null}
     */
    private function posisi(?string $lintang, ?string $bujur): array
    {
        return [
            'lat' => $lintang === null ? null : (float) $lintang,
            'lng' => $bujur === null ? null : (float) $bujur,
        ];
    }
}
