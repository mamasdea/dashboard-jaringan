<?php

namespace Database\Seeders;

use App\Fo\LayananHubungan;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\PortPerangkat;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use Illuminate\Database\Seeder;

/**
 * Inventaris FO contoh berlabel SIMULASI (kode berawalan SIM-). Dipanggil oleh
 * SimulasiSeeder setelah OPD dan perangkat contoh tersedia.
 *
 *   SIM-OTB-DISKOMINFO ==[SIM-BB-01, backbone 24 core, tiang 1-4]== SIM-CL-01
 *   SIM-CL-01 ==[SIM-DS-01, distribusi 12 core, tiang 4-6]== SIM-OTB-SETDA
 *
 * Sebagian objek sengaja belum lengkap untuk memperlihatkan status
 * "Perlu dilengkapi".
 */
class SimulasiFoSeeder extends Seeder
{
    public function run(LayananHubungan $layanan): void
    {
        if (Tiang::query()->where('kode', 'SIM-TG-001')->exists()) {
            return;
        }

        $diskominfo = Opd::query()->where('kode', 'SIM-DISKOMINFO')->firstOrFail();
        $setda = Opd::query()->where('kode', 'SIM-SETDA')->firstOrFail();

        // Tiang 1-6 di antara Diskominfo dan Setda; 7 dan 8 baru didata kodenya.
        foreach ([
            1 => [-7.358950, 109.903300, 'diskominfo', 'baik'],
            2 => [-7.359100, 109.903450, 'diskominfo', 'baik'],
            3 => [-7.359250, 109.903600, 'pln', 'baik'],
            4 => [-7.359400, 109.903750, 'pln', 'miring'],
            5 => [-7.359550, 109.903900, 'telkom', 'baik'],
            6 => [-7.359650, 109.904000, 'diskominfo', 'baik'],
            7 => [null, null, null, null],
            8 => [-7.360100, 109.904600, null, null],
        ] as $nomor => [$lintang, $bujur, $pemilik, $kondisi]) {
            Tiang::create([
                'kode' => sprintf('SIM-TG-%03d', $nomor),
                'lintang' => $lintang, 'bujur' => $bujur, 'pemilik' => $pemilik, 'kondisi' => $kondisi,
                'catatan' => 'Data contoh', 'simulasi' => true,
            ]);
        }

        $tiang = fn (int $nomor): Tiang => Tiang::query()->where('kode', sprintf('SIM-TG-%03d', $nomor))->firstOrFail();

        $otbDiskominfo = TitikJaringan::create([
            'kode' => 'SIM-OTB-DISKOMINFO', 'nama' => 'OTB ruang NOC', 'jenis' => 'otb', 'opd_id' => $diskominfo->id,
            'lintang' => -7.358900, 'bujur' => 109.903200, 'lokasi' => 'Ruang NOC rak A1', 'kapasitas_port' => 24, 'simulasi' => true,
        ]);
        $closure = TitikJaringan::create([
            'kode' => 'SIM-CL-01', 'nama' => 'Closure percabangan Setda', 'jenis' => 'closure', 'tiang_id' => $tiang(4)->id, 'simulasi' => true,
        ]);
        $otbSetda = TitikJaringan::create([
            'kode' => 'SIM-OTB-SETDA', 'nama' => 'OTB Setda', 'jenis' => 'otb', 'opd_id' => $setda->id,
            'lintang' => -7.359700, 'bujur' => 109.904100, 'lokasi' => 'Ruang server lantai 1', 'kapasitas_port' => 12, 'simulasi' => true,
        ]);
        TitikJaringan::create([
            'kode' => 'SIM-ODC-01', 'nama' => 'ODC simpang', 'jenis' => 'odc', 'tiang_id' => $tiang(6)->id, 'kapasitas_port' => 48, 'simulasi' => true,
        ]);
        // Baru diketahui kode dan jenisnya.
        TitikJaringan::create(['kode' => 'SIM-ODP-01', 'jenis' => 'odp', 'simulasi' => true]);

        $backbone = SegmenKabel::create([
            'kode' => 'SIM-BB-01', 'nama' => 'Diskominfo – closure Setda', 'klasifikasi' => 'backbone', 'jumlah_core' => 24,
            'titik_awal_id' => $otbDiskominfo->id, 'titik_akhir_id' => $closure->id, 'panjang_ukur_m' => 96.5,
            'jalur_peta' => [[109.903200, -7.358900], [109.903300, -7.358950], [109.903450, -7.359100], [109.903600, -7.359250], [109.903750, -7.359400]],
            'simulasi' => true,
        ]);
        $distribusi = SegmenKabel::create([
            'kode' => 'SIM-DS-01', 'nama' => 'Closure Setda – OTB Setda', 'klasifikasi' => 'distribusi', 'jumlah_core' => 12,
            'titik_awal_id' => $closure->id, 'titik_akhir_id' => $otbSetda->id, 'panjang_ukur_m' => 58,
            'jalur_peta' => [[109.903750, -7.359400], [109.903900, -7.359550], [109.904000, -7.359650], [109.904100, -7.359700]],
            'simulasi' => true,
        ]);
        // Belum diukur dan belum ada jalur.
        SegmenKabel::create(['kode' => 'SIM-DS-02', 'klasifikasi' => 'distribusi', 'titik_awal_id' => $closure->id, 'simulasi' => true]);

        $layanan->aturRuteTiang($backbone, [$tiang(1)->id, $tiang(2)->id, $tiang(3)->id, $tiang(4)->id]);
        // Tiang 4 dilalui dua kabel.
        $layanan->aturRuteTiang($distribusi, [$tiang(4)->id, $tiang(5)->id, $tiang(6)->id]);

        foreach ([1, 2] as $nomor) {
            $coreBackbone = $backbone->core()->where('nomor', $nomor)->firstOrFail();
            $coreDistribusi = $distribusi->core()->where('nomor', $nomor)->firstOrFail();

            $layanan->terminasikan($otbDiskominfo->port()->where('nomor', $nomor)->firstOrFail(), $coreBackbone);
            $layanan->sambung($closure, $coreBackbone, $coreDistribusi, 'Data contoh');
            $layanan->terminasikan($otbSetda->port()->where('nomor', $nomor)->firstOrFail(), $coreDistribusi);
        }

        foreach ([
            ['SIM-SW-CORE-01', 'TenGigabitEthernet1/0/1', $otbDiskominfo],
            ['SIM-SW-SETDA-01', 'GigabitEthernet0/1', $otbSetda],
        ] as [$kodeAset, $namaPort, $otb]) {
            $perangkat = Perangkat::query()->where('kode_aset', $kodeAset)->first();

            if ($perangkat !== null) {
                $layanan->hubungkanEndpoint(
                    PortPerangkat::firstOrCreate(['perangkat_id' => $perangkat->id, 'nama' => $namaPort]),
                    port: $otb->port()->where('nomor', 1)->firstOrFail(),
                    catatan: 'Data contoh',
                );
            }
        }
    }
}
