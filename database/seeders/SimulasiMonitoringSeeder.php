<?php

namespace Database\Seeders;

use App\Models\EndpointMonitoring;
use App\Models\Perangkat;
use App\Models\ProfilSnmp;
use App\Monitoring\Adapter\AdapterSimulasi;
use App\Monitoring\LayananStatus;
use Illuminate\Database\Seeder;

/**
 * Skenario monitoring contoh berlabel SIMULASI. Endpoint-nya sendiri sudah
 * terbentuk dari perangkat contoh yang monitoringnya diaktifkan; di sini hanya
 * diatur supaya adapter simulasi memperlihatkan setiap status.
 *
 * Status baru muncul setelah kolektor dijalankan:
 *     php artisan monitoring:kolektor
 */
class SimulasiMonitoringSeeder extends Seeder
{
    public function run(LayananStatus $layanan): void
    {
        // Perangkat contoh yang sudah ada sebelum modul monitoring dibuat.
        Perangkat::query()->simulasi()->get()->each(fn (Perangkat $perangkat) => EndpointMonitoring::sinkronkanDari($perangkat));

        foreach ([
            'SIM-SW-DINKES-01' => AdapterSimulasi::TIDAK_TERJANGKAU,
            'SIM-SW-DISDIK-01' => AdapterSimulasi::MENURUN,
            'SIM-SW-RSUD-01' => AdapterSimulasi::BERKEDIP,
            'SIM-SW-DPUPR-01' => AdapterSimulasi::GALAT_KOLEKTOR,
        ] as $kodeAset => $skenario) {
            $this->endpoint($kodeAset)?->update(['skenario_simulasi' => $skenario]);
        }

        $pemeliharaan = $this->endpoint('SIM-SW-KERTEK-01');

        if ($pemeliharaan !== null && ! $pemeliharaan->pemeliharaan) {
            $layanan->mulaiPemeliharaan($pemeliharaan, catatan: 'SIMULASI: penggantian perangkat');
        }

        // Profil SNMP contoh hanya dapat disegel bila kunci publik sudah diatur.
        if (filled(config('monitoring.kunci_publik')) && ! ProfilSnmp::query()->where('nama', '[SIMULASI] SNMPv2c baca-saja')->exists()) {
            (new ProfilSnmp(['nama' => '[SIMULASI] SNMPv2c baca-saja', 'versi' => 'v2c', 'simulasi' => true, 'catatan' => 'Data contoh; community bukan milik perangkat nyata.']))
                ->aturKredensial(['community' => 'simulasi-bukan-rahasia'])
                ->save();
        }
    }

    private function endpoint(string $kodeAset): ?EndpointMonitoring
    {
        return EndpointMonitoring::query()
            ->whereHas('perangkat', fn ($query) => $query->where('kode_aset', $kodeAset))
            ->first();
    }
}
