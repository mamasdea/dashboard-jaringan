<?php

namespace App\Laporan;

use App\Models\Insiden;
use Carbon\CarbonImmutable;

/**
 * Jumlah dan durasi gangguan, dari tabel insiden. Insiden yang melewati
 * batas periode dihitung, dengan lamanya dipotong ke dalam periode.
 */
class LaporanGangguan
{
    public const array CATATAN = [
        'Gangguan = insiden monitoring: endpoint tidak menjawab tiga pemeriksaan berturut-turut, sampai terjangkau kembali atau masuk pemeliharaan.',
        'Insiden yang mulai sebelum atau berakhir sesudah periode tetap dihitung; lamanya dipotong pada batas periode. Insiden yang masih berlangsung dihitung sampai laporan dibuat.',
        'Lama insiden dapat lebih panjang daripada "gangguan" di laporan ketersediaan, karena insiden tetap terbuka selama status belum diketahui (belum ada bukti pulih).',
        'Insiden hanya menyatakan endpoint tidak terjangkau dari Diskominfo. Penyebabnya ada di tiket, bila sudah diverifikasi.',
    ];

    public function buat(Periode $periode): DokumenLaporan
    {
        $kini = CarbonImmutable::now()->utc();
        $mulai = $periode->mulai;
        $akhir = $periode->akhirEfektif($kini);

        $insiden = Insiden::query()
            ->with('endpoint.perangkat.opd')
            ->where('mulai_pada', '<', $akhir)
            ->where(fn ($query) => $query->whereNull('selesai_pada')->orWhere('selesai_pada', '>', $mulai))
            ->orderBy('mulai_pada')
            ->get();

        $perEndpoint = [];
        $rinci = [];
        $total = 0;

        foreach ($insiden as $i) {
            $lama = (int) $i->mulai_pada->max($mulai)->diffInSeconds(($i->selesai_pada ?? $akhir)->min($akhir));
            $perangkat = $i->endpoint->perangkat;
            $opd = $perangkat === null ? 'Tanpa OPD' : $perangkat->opd->nama;
            $nama = $perangkat === null ? $i->endpoint->nama : $perangkat->kode_aset;
            $total += $lama;

            $perEndpoint[$i->endpoint_id] ??= ['opd' => $opd, 'perangkat' => $nama, 'jumlah' => 0, 'total' => 0, 'terlama' => 0, 'berlangsung' => 0];
            $perEndpoint[$i->endpoint_id]['jumlah']++;
            $perEndpoint[$i->endpoint_id]['total'] += $lama;
            $perEndpoint[$i->endpoint_id]['terlama'] = max($perEndpoint[$i->endpoint_id]['terlama'], $lama);
            $perEndpoint[$i->endpoint_id]['berlangsung'] += $i->selesai_pada === null ? 1 : 0;

            $rinci[] = [
                $opd,
                $nama,
                DokumenLaporan::waktu($i->mulai_pada),
                $i->selesai_pada === null ? 'Masih berlangsung' : DokumenLaporan::waktu($i->selesai_pada),
                DokumenLaporan::angka($lama / 60, 0),
                match ($i->ditutup_karena) {
                    Insiden::PULIH => 'Terjangkau kembali',
                    Insiden::PEMELIHARAAN => 'Ditutup karena pemeliharaan',
                    default => 'Belum ditutup',
                },
            ];
        }

        usort($perEndpoint, fn (array $a, array $b) => [$b['total'], $a['opd']] <=> [$a['total'], $b['opd']]);

        return new DokumenLaporan(
            'gangguan',
            'Durasi dan Jumlah Gangguan',
            $periode->label(),
            $kini,
            [
                'Jumlah gangguan' => (string) $insiden->count(),
                'Masih berlangsung' => (string) $insiden->whereNull('selesai_pada')->count(),
                'Total lama gangguan (jam)' => DokumenLaporan::jam($total),
                'Rata-rata lama per gangguan (jam)' => $insiden->isEmpty() ? 'Tidak tersedia' : DokumenLaporan::jam(intdiv($total, $insiden->count())),
            ],
            [
                new BagianLaporan(
                    'Gangguan per perangkat',
                    ['OPD', 'Perangkat', 'Jumlah gangguan', 'Total (jam)', 'Terlama (jam)', 'Rata-rata (jam)', 'Masih berlangsung'],
                    array_map(fn (array $b) => [
                        $b['opd'], $b['perangkat'], (string) $b['jumlah'], DokumenLaporan::jam($b['total']), DokumenLaporan::jam($b['terlama']),
                        DokumenLaporan::jam(intdiv($b['total'], $b['jumlah'])), (string) $b['berlangsung'],
                    ], $perEndpoint),
                    'Tidak ada gangguan yang tercatat pada periode ini.',
                ),
                new BagianLaporan(
                    'Daftar gangguan',
                    ['OPD', 'Perangkat', 'Mulai (WIB)', 'Selesai (WIB)', 'Lama dalam periode (menit)', 'Keterangan'],
                    $rinci,
                    'Tidak ada gangguan yang tercatat pada periode ini.',
                ),
            ],
            self::CATATAN,
        );
    }
}
