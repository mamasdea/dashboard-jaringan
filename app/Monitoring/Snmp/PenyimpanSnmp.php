<?php

namespace App\Monitoring\Snmp;

use App\Models\EndpointMonitoring;
use App\Models\InterfaceSnmp;
use App\Models\SampelTrafik;
use App\Models\SnmpPerangkat;
use Carbon\CarbonImmutable;

/**
 * Menyimpan hasil SNMP yang berhasil: identitas perangkat, interface, dan
 * laju trafik. Hanya database; dipanggil LayananStatus di dalam transaksinya.
 */
class PenyimpanSnmp
{
    public function simpan(EndpointMonitoring $endpoint, HasilSnmp $hasil, CarbonImmutable $pada, bool $simulasi): void
    {
        $perangkat = SnmpPerangkat::query()->firstOrNew(['endpoint_id' => $endpoint->id]);
        $uptimeSebelum = $perangkat->uptime_tiktik;

        if ($uptimeSebelum !== null && $hasil->uptimeTiktik !== null && $hasil->uptimeTiktik < $uptimeSebelum) {
            $perangkat->restart_terdeteksi_pada = $pada;
        }

        $perangkat->fill([
            'sys_descr' => $hasil->sysDescr,
            'sys_object_id' => $hasil->sysObjectId,
            'sys_name' => $hasil->sysName,
            'uptime_tiktik' => $hasil->uptimeTiktik,
            'diperbarui_pada' => $pada,
            'simulasi' => $simulasi,
        ])->save();

        $namaUplink = mb_strtolower(trim((string) ($endpoint->perangkat->port_uplink ?? $endpoint->port_uplink)));
        $tersimpan = InterfaceSnmp::query()->where('endpoint_id', $endpoint->id)->get()->keyBy(fn (InterfaceSnmp $i) => mb_strtolower($i->nama));
        // Selang lebih panjang dari ini berarti ada pemeriksaan yang terlewat; laju tidak dihitung.
        $selangMaks = (float) $endpoint->kedaluwarsaDetik();

        foreach ($hasil->interface as $data) {
            $baris = $tersimpan[mb_strtolower($data->nama)] ?? new InterfaceSnmp(['endpoint_id' => $endpoint->id, 'nama' => $data->nama]);

            $this->perbarui($baris, $data, $hasil, $pada, $uptimeSebelum, $selangMaks, $namaUplink, $simulasi);
        }
    }

    private function perbarui(
        InterfaceSnmp $baris,
        DataInterface $data,
        HasilSnmp $hasil,
        CarbonImmutable $pada,
        ?int $uptimeSebelum,
        float $selangMaks,
        string $namaUplink,
        bool $simulasi,
    ): void {
        // Interface dikenali dari namanya. Alamat fisik yang berbeda di bawah nama yang
        // sama berarti perangkat kerasnya diganti: penghitung lama tidak boleh dipakai.
        $identitasBerubah = $baris->exists
            && $baris->alamat_fisik !== null
            && $data->alamatFisik !== null
            && $baris->alamat_fisik !== $data->alamatFisik;

        if ($baris->exists && $baris->if_index !== $data->ifIndex) {
            $baris->if_index_berubah_pada = $pada;
        }

        $selang = $baris->sampel_pada === null ? 0.0 : (float) $baris->sampel_pada->diffInSeconds($pada, absolute: false);
        $sebelum = fn (?string $nilai): ?int => $identitasBerubah || $nilai === null ? null : (int) $nilai;

        $laju = [];

        foreach (['masuk' => $data->oktetMasuk, 'keluar' => $data->oktetKeluar] as $arah => $sesudah) {
            $laju[$arah] = $identitasBerubah && $sesudah !== null
                ? ['bps' => null, 'alasan' => PenghitungLaju::IDENTITAS_BERUBAH]
                : PenghitungLaju::hitung(
                    $sebelum($baris->getAttribute("oktet_{$arah}")),
                    $sesudah,
                    $selang,
                    $selangMaks,
                    (bool) $data->penghitung64Bit,
                    $baris->uptime_tiktik_saat_sampel ?? $uptimeSebelum,
                    $hasil->uptimeTiktik,
                    $baris->diskontinuitas_tiktik,
                    $data->diskontinuitasTiktik,
                    $data->kecepatanBps,
                );
        }

        // Galat dan buang hanya dibandingkan bila kedua sampel berasal dari masa hidup penghitung yang sama.
        $bolehSelisih = ! $identitasBerubah && ! in_array(PenghitungLaju::PERANGKAT_RESTART, array_column($laju, 'alasan'), true)
            && ! in_array(PenghitungLaju::PENGHITUNG_RESET, array_column($laju, 'alasan'), true)
            && $selang >= 1 && $selang <= $selangMaks;

        $selisih = [];

        foreach (['galat_masuk' => $data->galatMasuk, 'galat_keluar' => $data->galatKeluar, 'buang_masuk' => $data->buangMasuk, 'buang_keluar' => $data->buangKeluar] as $kolom => $sesudah) {
            $selisih[$kolom] = PenghitungLaju::selisih($sebelum($baris->getAttribute($kolom)), $sesudah, $bolehSelisih);
        }

        $uplink = $namaUplink !== '' && in_array($namaUplink, array_map(
            fn (?string $n) => mb_strtolower((string) $n),
            [$data->nama, $data->deskripsi, $data->alias],
        ), true);

        $baris->fill([
            'alamat_fisik' => $data->alamatFisik,
            'if_index' => $data->ifIndex,
            'deskripsi' => $data->deskripsi,
            'alias' => $data->alias,
            'tipe' => $data->tipe,
            'kecepatan_bps' => $data->kecepatanBps,
            'status_admin' => $data->statusAdmin,
            'status_operasional' => $data->statusOperasional,
            'uplink' => $uplink,
            'oktet_masuk' => $data->oktetMasuk,
            'oktet_keluar' => $data->oktetKeluar,
            'penghitung_64_bit' => $data->penghitung64Bit,
            'galat_masuk' => $data->galatMasuk,
            'galat_keluar' => $data->galatKeluar,
            'buang_masuk' => $data->buangMasuk,
            'buang_keluar' => $data->buangKeluar,
            'diskontinuitas_tiktik' => $data->diskontinuitasTiktik,
            'uptime_tiktik_saat_sampel' => $hasil->uptimeTiktik,
            'sampel_pada' => $pada,
            'bps_masuk' => $laju['masuk']['bps'],
            'bps_keluar' => $laju['keluar']['bps'],
            'alasan_laju_kosong' => $laju['masuk']['alasan'] ?? $laju['keluar']['alasan'],
            'sfp_rx_dbm' => $data->sfpRxDbm,
            'sfp_tx_dbm' => $data->sfpTxDbm,
            'sfp_suhu_c' => $data->sfpSuhuC,
            'sfp_sumber' => $data->sfpRxDbm !== null || $data->sfpTxDbm !== null || $data->sfpSuhuC !== null ? $hasil->sumberSfp : null,
            'terlihat_terakhir_pada' => $pada,
            'simulasi' => $simulasi,
        ])->save();

        if ($uplink) {
            SampelTrafik::create([
                'interface_snmp_id' => $baris->id,
                'diambil_pada' => $pada,
                'selang_detik' => $selang > 0 ? round($selang, 2) : null,
                'bps_masuk' => $laju['masuk']['bps'],
                'bps_keluar' => $laju['keluar']['bps'],
                ...$selisih,
                'alasan_laju_kosong' => $laju['masuk']['alasan'] ?? $laju['keluar']['alasan'],
                'status_operasional' => $data->statusOperasional,
                'simulasi' => $simulasi,
            ]);
        }
    }
}
