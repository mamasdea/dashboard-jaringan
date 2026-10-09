<?php

namespace App\Providers;

use App\Monitoring\Adapter\AdapterJaringan;
use App\Monitoring\Adapter\AdapterSimulasi;
use App\Monitoring\DaftarIzinSasaran;
use App\Monitoring\Icmp\PemeriksaIcmp;
use App\Monitoring\Kontrak\AdapterPemeriksa;
use App\Monitoring\Sfp\AdapterSfp;
use App\Monitoring\Sfp\SfpMikrotik;
use App\Monitoring\Snmp\PabrikKlienSnmp;
use App\Monitoring\Snmp\PabrikKlienSnmpEkstensi;
use App\Monitoring\Snmp\PemeriksaSnmp;
use App\Support\BrankasKredensial;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class MonitoringServiceProvider extends ServiceProvider
{
    /**
     * Adapter yang dapat dipilih lewat MONITORING_ADAPTER. Kolektor nyata
     * (ICMP, SNMP) ditambahkan di sini saat sudah dibuat.
     *
     * @var array<string, class-string<AdapterPemeriksa>>
     */
    private const array ADAPTER = [
        'simulasi' => AdapterSimulasi::class,
        // ICMP, ditambah SNMP untuk endpoint yang punya profil SNMP.
        'jaringan' => AdapterJaringan::class,
    ];

    /**
     * Adapter optik SFP per vendor, dicoba berurutan menurut sysObjectID perangkat.
     *
     * @var list<class-string<AdapterSfp>>
     */
    private const array ADAPTER_SFP = [
        SfpMikrotik::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AdapterPemeriksa::class, function ($app): AdapterPemeriksa {
            $nama = (string) config('monitoring.adapter');
            $kelas = self::ADAPTER[$nama] ?? throw new InvalidArgumentException(
                "Adapter monitoring '{$nama}' tidak dikenal. Pilihan: ".implode(', ', array_keys(self::ADAPTER)).'.',
            );

            return $app->make($kelas);
        });

        $this->app->bind(DaftarIzinSasaran::class, fn (): DaftarIzinSasaran => DaftarIzinSasaran::dariKonfigurasi());
        $this->app->bind(PabrikKlienSnmp::class, PabrikKlienSnmpEkstensi::class);
        $this->app->bind(PemeriksaSnmp::class, fn ($app): PemeriksaSnmp => new PemeriksaSnmp(
            $app->make(PabrikKlienSnmp::class),
            array_map(fn (string $kelas) => $app->make($kelas), self::ADAPTER_SFP),
        ));
        $this->app->bind(PemeriksaIcmp::class);

        $this->app->bind(BrankasKredensial::class, fn (): BrankasKredensial => new BrankasKredensial(
            config('monitoring.kunci_publik'),
            config('monitoring.kunci_privat'),
        ));
    }
}
