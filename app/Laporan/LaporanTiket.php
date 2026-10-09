<?php

namespace App\Laporan;

use App\Enums\StatusTiket;
use App\Models\Tiket;
use Carbon\CarbonImmutable;

/**
 * Riwayat tiket yang gangguannya mulai di dalam periode.
 */
class LaporanTiket
{
    public const array CATATAN = [
        'Memuat tiket yang waktu mulainya berada di dalam periode.',
        'Status adalah status pekerjaan, terpisah dari status monitoring. Waktu pemulihan diisi orang yang menangani.',
        'Lama penanganan = waktu pemulihan − waktu mulai; kosong selama tiket belum selesai.',
        'Dugaan penyebab adalah perkiraan awal. Hanya penyebab terverifikasi yang sudah diperiksa.',
    ];

    public function buat(Periode $periode): DokumenLaporan
    {
        $kini = CarbonImmutable::now()->utc();

        $tiket = Tiket::query()
            ->with(['opd', 'perangkat', 'teknisi'])
            ->where('waktu_mulai', '>=', $periode->mulai)
            ->where('waktu_mulai', '<', $periode->akhir)
            ->orderBy('waktu_mulai')
            ->get();

        $selesai = $tiket->filter(fn (Tiket $t) => $t->status === StatusTiket::Selesai && $t->waktu_pemulihan !== null);
        $lama = $selesai->sum(fn (Tiket $t) => (int) $t->waktu_mulai->diffInSeconds($t->waktu_pemulihan));

        $ringkasan = ['Jumlah tiket' => (string) $tiket->count()];

        foreach (StatusTiket::cases() as $status) {
            $ringkasan[$status->label()] = (string) $tiket->where('status', $status)->count();
        }

        $ringkasan['Dibuat otomatis dari monitoring'] = (string) $tiket->where('sumber', Tiket::OTOMATIS)->count();
        $ringkasan['Rata-rata lama penanganan tiket selesai (jam)'] = $selesai->isEmpty() ? 'Tidak tersedia' : DokumenLaporan::jam(intdiv($lama, $selesai->count()));
        $ringkasan['Tiket selesai tanpa penyebab terverifikasi'] = (string) $selesai->whereNull('penyebab_terverifikasi')->count();

        return new DokumenLaporan(
            'tiket',
            'Riwayat Tiket Gangguan',
            $periode->label(),
            $kini,
            $ringkasan,
            [new BagianLaporan(
                'Daftar tiket',
                ['Nomor', 'Judul', 'OPD', 'Perangkat', 'Sumber', 'Prioritas', 'Status pekerjaan', 'Teknisi', 'Mulai (WIB)', 'Pemulihan (WIB)', 'Lama penanganan (jam)', 'Dugaan penyebab', 'Penyebab terverifikasi'],
                array_values($tiket->map(fn (Tiket $t): array => [
                    $t->nomor,
                    $t->judul,
                    (string) $t->opd?->nama,
                    (string) $t->perangkat?->kode_aset,
                    $t->sumber === Tiket::OTOMATIS ? 'Otomatis' : 'Manual',
                    $t->prioritas->label(),
                    $t->status->label(),
                    (string) $t->teknisi?->name,
                    DokumenLaporan::waktu($t->waktu_mulai),
                    DokumenLaporan::waktu($t->waktu_pemulihan),
                    $t->waktu_pemulihan === null ? '' : DokumenLaporan::jam((int) $t->waktu_mulai->diffInSeconds($t->waktu_pemulihan)),
                    (string) $t->dugaan_penyebab,
                    (string) $t->penyebab_terverifikasi,
                ])->all()),
                'Tidak ada tiket pada periode ini.',
            )],
            self::CATATAN,
        );
    }
}
