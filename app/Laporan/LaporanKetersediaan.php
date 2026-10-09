<?php

namespace App\Laporan;

use App\Enums\StatusMonitoring;
use App\Models\Opd;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ketersediaan koneksi per OPD, dari riwayat status endpoint switch utamanya.
 * Hanya membaca data tersimpan.
 */
class LaporanKetersediaan
{
    public const array CATATAN = [
        'Yang diukur adalah keterjangkauan switch utama OPD dari jaringan Diskominfo, bukan layanan internet di OPD.',
        'Ketersediaan = (normal + menurun) ÷ (normal + menurun + gangguan). Pemeliharaan dan waktu tanpa data tidak masuk pembagi.',
        'Normal dan menurun: endpoint menjawab pemeriksaan. Gangguan: endpoint berstatus tidak terjangkau.',
        'Tanpa data: status belum diketahui (kolektor tidak berjalan, hasil kedaluwarsa, endpoint belum dipantau). Waktu ini tidak dihitung sebagai normal.',
        'Cakupan data = bagian periode yang punya data. Ketersediaan dengan cakupan rendah tidak mewakili seluruh periode.',
        'Waktu yang belum terjadi tidak dihitung. Semua lama dalam jam.',
    ];

    public function buat(Periode $periode): DokumenLaporan
    {
        $kini = CarbonImmutable::now()->utc();
        $mulai = $periode->mulai;
        $akhir = $periode->akhirEfektif($kini);

        $opd = Opd::query()->where('aktif', true)->with('switchUtama.endpoint.status')->orderBy('nama')->get();
        $endpointIds = $opd->map(fn (Opd $o) => $o->switchUtama?->endpoint?->id)->filter()->values()->all();

        $awal = [];
        $transisi = [];

        if ($endpointIds !== []) {
            // Status yang berlaku tepat pada awal periode.
            $baris = DB::table('riwayat_status')
                ->selectRaw('DISTINCT ON (endpoint_id) endpoint_id, menjadi')
                ->whereIn('endpoint_id', $endpointIds)->where('pada', '<=', $mulai)
                ->orderBy('endpoint_id')->orderByDesc('pada')->orderByDesc('id')->get();

            foreach ($baris as $b) {
                $awal[$b->endpoint_id] = StatusMonitoring::from($b->menjadi);
            }

            $baris = DB::table('riwayat_status')
                ->whereIn('endpoint_id', $endpointIds)->where('pada', '>', $mulai)->where('pada', '<', $akhir)
                ->orderBy('pada')->orderBy('id')->get(['endpoint_id', 'pada', 'menjadi']);

            foreach ($baris as $b) {
                $transisi[$b->endpoint_id][] = ['pada' => CarbonImmutable::parse($b->pada)->utc(), 'menjadi' => StatusMonitoring::from($b->menjadi)];
            }
        }

        $isi = [];
        $jumlah = [PenghitungKetersediaan::NORMAL => 0, PenghitungKetersediaan::MENURUN => 0, PenghitungKetersediaan::GANGGUAN => 0, PenghitungKetersediaan::PEMELIHARAAN => 0, PenghitungKetersediaan::TANPA_DATA => 0];
        $tanpaPantauan = 0;

        foreach ($opd as $o) {
            $endpoint = $o->switchUtama?->endpoint;
            $status = $endpoint?->status;

            if ($endpoint === null) {
                $tanpaPantauan++;
            }

            $detik = PenghitungKetersediaan::hitung(
                $endpoint === null ? null : ($awal[$endpoint->id] ?? null),
                $endpoint === null ? [] : ($transisi[$endpoint->id] ?? []),
                $mulai,
                $akhir,
                // Kolektor yang mati tidak menulis perubahan status; batas ini
                // mencegah status terakhir dianggap berlaku terus.
                $status?->hasil_valid_terakhir_pada?->addSeconds($endpoint->kedaluwarsaDetik()),
            );

            foreach ($detik as $golongan => $nilai) {
                $jumlah[$golongan] += $nilai;
            }

            $isi[] = [
                $o->nama,
                $o->switchUtama === null ? 'Belum ada switch utama' : ($endpoint === null ? $o->switchUtama->kode_aset.' (tidak dipantau)' : $o->switchUtama->kode_aset),
                DokumenLaporan::persen(PenghitungKetersediaan::persen($detik)),
                DokumenLaporan::jam($detik[PenghitungKetersediaan::NORMAL]),
                DokumenLaporan::jam($detik[PenghitungKetersediaan::MENURUN]),
                DokumenLaporan::jam($detik[PenghitungKetersediaan::GANGGUAN]),
                DokumenLaporan::jam($detik[PenghitungKetersediaan::PEMELIHARAAN]),
                DokumenLaporan::jam($detik[PenghitungKetersediaan::TANPA_DATA]),
                DokumenLaporan::persen(PenghitungKetersediaan::cakupan($detik)),
            ];
        }

        return new DokumenLaporan(
            'ketersediaan',
            'Ketersediaan Koneksi OPD ke Diskominfo',
            $periode->label(),
            $kini,
            [
                'OPD aktif' => (string) $opd->count(),
                'OPD tanpa switch utama yang dipantau' => (string) $tanpaPantauan,
                'Lama periode yang dihitung (jam)' => DokumenLaporan::jam($periode->detik($kini)),
                'Ketersediaan gabungan' => DokumenLaporan::persen(PenghitungKetersediaan::persen($jumlah)),
                'Cakupan data gabungan' => DokumenLaporan::persen(PenghitungKetersediaan::cakupan($jumlah)),
            ],
            [new BagianLaporan(
                'Ketersediaan per OPD',
                ['OPD', 'Switch utama', 'Ketersediaan', 'Normal (jam)', 'Menurun (jam)', 'Gangguan (jam)', 'Pemeliharaan (jam)', 'Tanpa data (jam)', 'Cakupan data'],
                $isi,
                'Belum ada OPD aktif.',
            )],
            self::CATATAN,
        );
    }
}
